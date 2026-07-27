<?php

use App\Jobs\ImportWordpressPostsJob;
use App\Models\Article;
use App\Models\MediaAsset;
use App\Models\WordpressImportRecord;
use App\Services\Ads\AdReportService;
use App\Services\Ads\PendingAdCreativeCleanupService;
use App\Services\Articles\EditorialQualityService;
use App\Services\Articles\ScheduledArticlePublisher;
use App\Services\Homepage\HomepageSectionQaService;
use App\Services\Media\MediaCleanupService;
use App\Services\Media\MediaDuplicateCleanupService;
use App\Services\Media\MediaQualityReportService;
use App\Services\Media\MediaVariantGenerator;
use App\Services\Operations\LaunchReadinessCheckService;
use App\Services\Seo\RedirectQaReportService;
use App\Services\Seo\SeoHealthCheckService;
use App\Services\Seo\SeoMetadataRepairService;
use App\Services\Wordpress\WordpressContentSanitizer;
use App\Services\Wordpress\WordpressImportRetryService;
use App\Services\Wordpress\WordpressMigrationReadinessService;
use App\Services\Wordpress\WordpressMigrationReportService;
use App\Services\Wordpress\WordpressOldLinkRepairService;
use App\Services\Wordpress\WordpressRestImporter;
use App\Support\CsvExport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ads:prune-pending-creatives {--hours=24}', function (PendingAdCreativeCleanupService $cleanup): int {
    $deleted = $cleanup->prune(max(1, (int) $this->option('hours')));
    $this->info("Pruned {$deleted} unattached advertising creative(s).");

    return self::SUCCESS;
})->purpose('Delete abandoned public advertising creative uploads');

Schedule::command('ads:prune-pending-creatives')->hourly()->withoutOverlapping();
Schedule::command('editorial-analytics:rollup --days=1')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('editorial-analytics:rollup --days=3 --prune')->dailyAt('02:20')->withoutOverlapping();

Artisan::command('wordpress:import-posts {base-url?} {--per-page=20} {--queue}', function (WordpressRestImporter $importer): int {
    $baseUrl = $this->argument('base-url') ?? config('services.wordpress.base_url');

    if (! is_string($baseUrl) || $baseUrl === '') {
        $this->error('Provide a WordPress base URL or set WORDPRESS_BASE_URL.');

        return self::FAILURE;
    }

    $perPage = (int) $this->option('per-page');

    if ($this->option('queue')) {
        $totalPages = $importer->countPostPages($baseUrl, $perPage);

        for ($page = 1; $page <= $totalPages; $page++) {
            ImportWordpressPostsJob::dispatch($baseUrl, $perPage, $page);
        }

        $this->info("Queued {$totalPages} WordPress import page jobs.");

        return self::SUCCESS;
    }

    $summary = $importer->importPosts($baseUrl, $perPage);

    $this->info("Imported {$summary['imported']} WordPress posts. Failed {$summary['failed']}.");

    return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Import posts from the configured WordPress REST API.');

Artisan::command('wordpress:import-report', function (WordpressRestImporter $importer): int {
    $report = $importer->report();

    $this->info("Failed imports: {$report['failed_count']}");
    $this->info("Missing featured images: {$report['missing_featured_image_count']}");

    return self::SUCCESS;
})->purpose('Summarize WordPress import failures and missing featured images.');

Artisan::command('wordpress:dry-run {base-url?} {--per-page=20} {--page=1}', function (WordpressRestImporter $importer): int {
    $baseUrl = $this->argument('base-url') ?? config('services.wordpress.base_url');

    if (! is_string($baseUrl) || $baseUrl === '') {
        $this->error('Provide a WordPress base URL or set WORDPRESS_BASE_URL.');

        return self::FAILURE;
    }

    $preview = $importer->dryRunPostsPage($baseUrl, (int) $this->option('per-page'), (int) $this->option('page'));

    $this->info('Total pages: '.$preview['total_pages']);

    foreach ($preview['posts'] as $post) {
        $this->line("{$post['wordpress_id']} {$post['slug']} author={$post['author']['status']} category={$post['category']['status']}");
    }

    return self::SUCCESS;
})->purpose('Preview WordPress import mapping decisions without writing content.');

Artisan::command('wordpress:dry-run-all {base-url?} {--per-page=20} {--max-pages=}', function (WordpressRestImporter $importer): int {
    $baseUrl = $this->argument('base-url') ?? config('services.wordpress.base_url');

    if (! is_string($baseUrl) || $baseUrl === '') {
        $this->error('Provide a WordPress base URL or set WORDPRESS_BASE_URL.');

        return self::FAILURE;
    }

    $maxPages = $this->option('max-pages');
    $preview = $importer->dryRunPosts(
        $baseUrl,
        (int) $this->option('per-page'),
        $maxPages === null || $maxPages === '' ? null : (int) $maxPages,
    );

    $this->info('Total pages: '.$preview['total_pages']);
    $this->info('Pages scanned: '.$preview['pages_scanned']);
    $this->info('Posts scanned: '.$preview['summary']['posts']);
    $this->info('Mapped authors: '.$preview['summary']['mapped_authors']);
    $this->info('Mapped categories: '.$preview['summary']['mapped_categories']);
    $this->info('Missing featured images: '.$preview['summary']['missing_featured_images']);
    $this->info('Authors to create: '.$preview['summary']['will_create_authors']);

    return self::SUCCESS;
})->purpose('Preview all WordPress import mapping decisions without writing content.');

Artisan::command('wordpress:dry-run-export {base-url?} {--per-page=20} {--max-pages=}', function (WordpressRestImporter $importer): int {
    $baseUrl = $this->argument('base-url') ?? config('services.wordpress.base_url');

    if (! is_string($baseUrl) || $baseUrl === '') {
        $this->error('Provide a WordPress base URL or set WORDPRESS_BASE_URL.');

        return self::FAILURE;
    }

    $maxPages = $this->option('max-pages');
    $rows = $importer->dryRunExportRows(
        $baseUrl,
        (int) $this->option('per-page'),
        $maxPages === null || $maxPages === '' ? null : (int) $maxPages,
    );

    foreach (CsvExport::lines([
        'wordpress_id',
        'title',
        'slug',
        'status',
        'old_url',
        'author_status',
        'author_source_id',
        'author_source_slug',
        'author_source_name',
        'author_target_slug',
        'author_target_name',
        'category_status',
        'category_source_id',
        'category_source_slug',
        'category_source_name',
        'category_target_slug',
        'category_target_name',
        'tag_statuses',
        'tag_source_slugs',
        'tag_target_slugs',
        'featured_image_status',
        'featured_image_url',
        'warnings',
    ], $rows) as $line) {
        $this->line($line);
    }

    return self::SUCCESS;
})->purpose('Export WordPress dry-run mapping decisions as CSV without writing content.');

Artisan::command('wordpress:import-page {base-url?} {--per-page=20} {--page=1}', function (WordpressRestImporter $importer): int {
    $baseUrl = $this->argument('base-url') ?? config('services.wordpress.base_url');

    if (! is_string($baseUrl) || $baseUrl === '') {
        $this->error('Provide a WordPress base URL or set WORDPRESS_BASE_URL.');

        return self::FAILURE;
    }

    $page = max(1, (int) $this->option('page'));
    $summary = $importer->importPostsPage($baseUrl, (int) $this->option('per-page'), $page);

    $this->info("Imported {$summary['imported']} WordPress posts from page {$page}. Failed {$summary['failed']}.");

    return self::SUCCESS;
})->purpose('Import one WordPress REST post page for local or staging rehearsal.');

Artisan::command('wordpress:import-batch {base-url?} {--per-page=50} {--limit=100} {--offset=0} {--sleep=0}', function (WordpressRestImporter $importer): int {
    $baseUrl = $this->argument('base-url') ?? config('services.wordpress.base_url');

    if (! is_string($baseUrl) || $baseUrl === '') {
        $this->error('Provide a WordPress base URL or set WORDPRESS_BASE_URL.');

        return self::FAILURE;
    }

    $perPage = max(1, min((int) $this->option('per-page'), WordpressRestImporter::MAX_BATCH_PER_PAGE));
    $limit = max(1, (int) $this->option('limit'));
    $offset = max(0, (int) $this->option('offset'));
    $sleep = max(0.0, (float) $this->option('sleep'));

    $this->info("Importing WordPress posts with limit {$limit}, offset {$offset}, per page {$perPage}.");

    $summary = $importer->importPostsBatch(
        $baseUrl,
        $perPage,
        $limit,
        $offset,
        $sleep,
        fn (int $page, int $totalPages, array $pageSummary): null => $this->info(
            "Page {$page} of {$totalPages}: imported {$pageSummary['imported']}, failed {$pageSummary['failed']}."
        ),
    );

    $this->info("Imported {$summary['imported']} WordPress posts across {$summary['pages_scanned']} scanned pages. Failed {$summary['failed']}.");

    return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Import a limited WordPress REST post batch for safer local or staging rehearsals.');

Artisan::command('wordpress:retry-failed {wordpress-id?} {--limit=50} {--only-locks}', function (WordpressImportRetryService $retry): int {
    $wordpressId = $this->argument('wordpress-id');
    $query = WordpressImportRecord::query()
        ->where('wordpress_type', 'post')
        ->where('status', 'failed')
        ->orderBy('wordpress_id');

    if ($wordpressId !== null) {
        $query->where('wordpress_id', (int) $wordpressId);
    } else {
        $query->limit(max(1, (int) $this->option('limit')));
    }

    if ($this->option('only-locks')) {
        $query->where('error_message', 'like', '%database is locked%');
    }

    $retried = 0;
    $imported = 0;
    $failed = 0;

    foreach ($query->get() as $record) {
        $retried++;

        try {
            $retry->retry($record);
            $imported++;
        } catch (Throwable $exception) {
            $failed++;
            $this->warn("Retry failed for WordPress {$record->wordpress_id}: {$exception->getMessage()}");
        }
    }

    $label = $retried === 1 ? 'import' : 'imports';

    $this->info("Retried {$retried} failed WordPress {$label}. Imported {$imported}. Failed {$failed}.");

    return $failed === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Retry failed WordPress post imports from stored source payloads.');

Artisan::command('wordpress:finalize-import', function (WordpressRestImporter $importer): int {
    $summary = $importer->finalizeImportedContent();

    $this->info("Author legacy URLs backfilled: {$summary['author_legacy_urls_backfilled']}");
    $this->info("Author legacy URLs unresolved: {$summary['author_legacy_urls_unresolved']}");
    $this->info("Rewritten articles: {$summary['rewritten_articles']}");
    $this->info("Redirects created or updated: {$summary['redirects_created_or_updated']}");

    return $summary['author_legacy_urls_unresolved'] === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Rewrite imported links and create 301 redirects after WordPress import.');

Artisan::command('wordpress:review-export', function (WordpressMigrationReportService $reporter): int {
    foreach (CsvExport::lines(
        ['issue', 'wordpress_id', 'title', 'slug', 'old_url', 'new_url', 'error_message', 'warnings'],
        $reporter->reviewExportRows(),
    ) as $line) {
        $this->line($line);
    }

    return self::SUCCESS;
})->purpose('Export WordPress migration review rows as CSV.');

Artisan::command('wordpress:normalization-export', function (WordpressMigrationReportService $reporter): int {
    foreach (CsvExport::lines(
        ['normalization', 'elements', 'wordpress_id', 'title', 'slug', 'old_url', 'new_url'],
        $reporter->normalizationExportRows(),
    ) as $line) {
        $this->line($line);
    }

    return self::SUCCESS;
})->purpose('Export non-review WordPress embed normalization rows as CSV.');

Artisan::command('wordpress:migration-readiness {--json}', function (WordpressMigrationReadinessService $checker): int {
    $summary = $checker->summary();

    if ($this->option('json')) {
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $summary['ready'] ? self::SUCCESS : self::FAILURE;
    }

    $this->info('WordPress migration readiness: '.($summary['ready'] ? 'ready' : 'blockers found'));

    foreach ($summary['checks'] as $check) {
        $line = "[{$check['status']}] {$check['code']}: {$check['message']}";
        $check['status'] === 'fail' ? $this->warn($line) : $this->line($line);
    }

    return $summary['ready'] ? self::SUCCESS : self::FAILURE;
})->purpose('Run WordPress migration launch readiness checks for imports, redirects, old links, media, and SEO.');

Artisan::command('wordpress:repair-old-links {--apply} {--limit=} {--skip-known-failed} {--progress-every=50} {--download-timeout=30}', function (WordpressOldLinkRepairService $repair, WordpressContentSanitizer $contentSanitizer): int {
    $limit = $this->option('limit');
    $progressEvery = max(1, (int) $this->option('progress-every'));
    $downloadTimeout = max(1, (int) $this->option('download-timeout'));

    $contentSanitizer->setDownloadTimeoutSeconds($downloadTimeout);
    $this->info("Download timeout: {$downloadTimeout} seconds");

    $summary = $repair->repair(
        apply: (bool) $this->option('apply'),
        limit: $limit === null || $limit === '' ? null : (int) $limit,
        skipKnownFailed: (bool) $this->option('skip-known-failed'),
        progressEvery: $progressEvery,
        onProgress: function (array $progress): void {
            $this->line(sprintf(
                'Progress: checked %s/%s articles; references %s; failed downloads %s; current %s',
                $progress['articles_checked'],
                $progress['total_articles'],
                $progress['references_found'],
                $progress['failed_downloads'],
                $progress['slug'] ?? '-',
            ));
        },
    );

    $this->info('Mode: '.($this->option('apply') ? 'apply' : 'dry run'));
    $this->info("Articles checked: {$summary['articles_checked']}");
    $this->info("Articles with references: {$summary['articles_with_references']}");
    $this->info("Skipped known failed articles: {$summary['skipped_known_failed_articles']}");
    $this->info("References found: {$summary['references_found']}");
    $this->info("Downloaded media: {$summary['downloaded_media']}");
    $this->info("Reused media: {$summary['reused_media']}");
    $this->info("Failed downloads: {$summary['failed_downloads']}");
    $this->info("Rewritten articles: {$summary['rewritten_articles']}");
    $this->info("Shortcodes removed: {$summary['shortcodes_removed']}");

    $brokenSources = collect($summary['broken_sources'])
        ->take(10)
        ->values();

    if ($brokenSources->isNotEmpty()) {
        $this->warn('Failed old upload repair sources:');

        $brokenSources->each(function (array $source): void {
            $this->warn(sprintf(
                '- %s - %s (%s refs, %s articles; samples: %s)',
                $source['source_url'] ?? '-',
                $source['reason'] ?? 'No failure reason captured.',
                $source['occurrence_count'] ?? 0,
                $source['article_count'] ?? 0,
                implode(', ', $source['sample_articles'] ?? []),
            ));
        });
    }

    return $summary['failed_downloads'] === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Dry-run or apply repairs for old WordPress upload links in imported article bodies.');

Artisan::command('wordpress:resolve-media-review-warnings {--apply}', function (WordpressOldLinkRepairService $repair): int {
    $summary = $repair->resolveMediaReviewWarnings(apply: (bool) $this->option('apply'));

    $this->info('Mode: '.($this->option('apply') ? 'apply' : 'dry run'));
    $this->info("Records checked: {$summary['records_checked']}");
    $this->info("Records fixable: {$summary['records_fixable']}");
    $this->info("Records updated: {$summary['records_updated']}");
    $this->info("Skipped with old upload refs: {$summary['skipped_with_old_upload_refs']}");

    return self::SUCCESS;
})->purpose('Clear resolved WordPress media review warnings after old upload references are removed.');

Artisan::command('wordpress:old-link-export {--grouped}', function (WordpressOldLinkRepairService $repair): int {
    if ($this->option('grouped')) {
        $headers = ['article_id', 'wordpress_id', 'slug', 'old_url', 'new_url', 'source_url', 'literal_url', 'reason', 'status', 'message', 'occurrence_count', 'article_count'];

        foreach (CsvExport::lines($headers, $repair->brokenUploadReportRows()) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    foreach (CsvExport::lines(
        ['article_id', 'wordpress_id', 'slug', 'source_url', 'literal', 'status', 'message'],
        $repair->exportRows(),
    ) as $line) {
        $this->line($line);
    }

    return self::SUCCESS;
})->purpose('Export unresolved old WordPress upload references from imported article bodies as CSV.');

Artisan::command('wordpress:remove-old-link {article} {sourceUrl} {--apply}', function (WordpressOldLinkRepairService $repair): int {
    $articleIdentifier = (string) $this->argument('article');
    $sourceUrl = (string) $this->argument('sourceUrl');

    $article = Article::query()
        ->where('slug', $articleIdentifier)
        ->orWhere('id', ctype_digit($articleIdentifier) ? (int) $articleIdentifier : 0)
        ->orWhere('old_wordpress_id', ctype_digit($articleIdentifier) ? (int) $articleIdentifier : 0)
        ->first();

    if (! $article) {
        $this->error("Imported article not found for [{$articleIdentifier}].");

        return self::FAILURE;
    }

    $article->loadMissing('wordpressImportRecord');

    DB::beginTransaction();
    $result = $repair->removeOldUploadReference($article, $sourceUrl);

    $this->option('apply') ? DB::commit() : DB::rollBack();

    $this->info('Mode: '.($this->option('apply') ? 'apply' : 'dry run'));
    $this->info("Article: {$article->slug}");
    $this->info("Source URL: {$result['source_url']}");
    $this->info("Removed references: {$result['removed_references']}");
    $this->info('Remaining old upload references: '.($result['remaining_old_upload_references'] ? 'yes' : 'no'));

    return $result['removed_references'] > 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Remove one unresolved old WordPress upload reference from an imported article body.');

Artisan::command('wordpress:remove-failed-old-links {--apply} {--limit=}', function (WordpressOldLinkRepairService $repair): int {
    $limit = $this->option('limit');
    $rows = collect($repair->brokenUploadReportRows())
        ->filter(fn (array $row): bool => ($row['status'] ?? null) === 'old_link_repair_failed')
        ->values();

    if ($limit !== null && $limit !== '') {
        $rows = $rows->take(max(1, (int) $limit))->values();
    }

    $removedReferences = 0;
    $articlesTouched = collect();

    DB::beginTransaction();

    foreach ($rows as $row) {
        $articleIds = is_array($row['article_id'] ?? null)
            ? $row['article_id']
            : [$row['article_id'] ?? null];

        foreach (array_filter($articleIds) as $articleId) {
            $article = Article::query()->find((int) $articleId);

            if (! $article) {
                continue;
            }

            $result = $repair->removeOldUploadReference($article, (string) ($row['source_url'] ?? ''));
            $removedReferences += $result['removed_references'];

            if ($result['removed_references'] > 0) {
                $articlesTouched->push($article->id);
            }
        }
    }

    $remainingFailedSources = collect($repair->brokenUploadReportRows())
        ->filter(fn (array $row): bool => ($row['status'] ?? null) === 'old_link_repair_failed')
        ->count();

    $this->option('apply') ? DB::commit() : DB::rollBack();

    $this->info('Mode: '.($this->option('apply') ? 'apply' : 'dry run'));
    $this->info('Sources checked: '.$rows->count());
    $this->info('Articles touched: '.$articlesTouched->unique()->count());
    $this->info("Removed references: {$removedReferences}");
    $this->info("Remaining failed sources: {$remainingFailedSources}");

    return $rows->isEmpty() || $removedReferences > 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Remove all known failed old WordPress upload references from imported article bodies.');

Artisan::command('search:sync-articles', function (): int {
    $articles = Article::query()->published()->get();

    $articles->searchable();

    $this->info('Queued '.$articles->count().' published articles for the search index.');

    return self::SUCCESS;
})->purpose('Sync published articles to the configured Laravel Scout search index.');

Artisan::command('wordpress:sync-imported-search', function (): int {
    $articles = Article::query()
        ->published()
        ->whereNotNull('old_wordpress_id')
        ->get();

    $articles->searchable();

    $label = $articles->count() === 1 ? 'article' : 'articles';

    $this->info("Queued {$articles->count()} imported published {$label} for the search index.");

    return self::SUCCESS;
})->purpose('Sync imported published WordPress articles to the configured Laravel Scout search index.');

Artisan::command('articles:publish-scheduled', function (ScheduledArticlePublisher $publisher): int {
    $published = $publisher->publishDue();
    $label = $published === 1 ? 'article' : 'articles';

    $this->info("Published {$published} scheduled {$label}.");

    return self::SUCCESS;
})->purpose('Publish due scheduled articles.');

Schedule::command('articles:publish-scheduled')->everyMinute();

Artisan::command('media:regenerate-variants {--only-missing}', function (MediaVariantGenerator $generator): int {
    $regenerated = 0;

    MediaAsset::query()
        ->where('disk', '!=', 'external')
        ->where('mime_type', 'like', 'image/%')
        ->orderBy('id')
        ->each(function (MediaAsset $asset) use ($generator, &$regenerated): void {
            if ($this->option('only-missing') && ! empty($asset->metadata['responsive_variants'])) {
                return;
            }

            $variants = $generator->generate($asset);

            if ($variants === []) {
                return;
            }

            $asset->forceFill([
                'metadata' => array_filter(
                    array_merge($asset->metadata ?? [], ['responsive_variants' => $variants]),
                    fn ($value) => $value !== null && $value !== '',
                ),
            ])->save();

            $regenerated++;
        });

    $label = $regenerated === 1 ? 'media asset' : 'media assets';

    $this->info("Regenerated variants for {$regenerated} {$label}.");

    return self::SUCCESS;
})->purpose('Regenerate responsive image variants for stored media assets.');

Artisan::command('media:quality-report', function (MediaQualityReportService $reporter): int {
    $summary = $reporter->summary();

    $this->info("Media assets checked: {$summary['totals']['assets_checked']}");
    $this->info("Quality issues: {$summary['totals']['issues']}");
    $this->info("Missing alt text: {$summary['totals']['missing_alt_text']}");
    $this->info("Missing variants: {$summary['totals']['missing_variants']}");
    $this->info("Missing files: {$summary['totals']['missing_file']}");

    return self::SUCCESS;
})->purpose('Report media metadata, responsive variant, and file readiness issues.');

Artisan::command('media:quality-export', function (MediaQualityReportService $reporter): int {
    foreach (CsvExport::lines(['issue', 'media_asset_id', 'path', 'url', 'message'], $reporter->exportRows()) as $line) {
        $this->line($line);
    }

    return self::SUCCESS;
})->purpose('Export media quality issue rows as CSV.');

Artisan::command('media:cleanup-quality {--apply}', function (MediaCleanupService $cleanup): int {
    $summary = $cleanup->repair(apply: (bool) $this->option('apply'));

    $this->info('Mode: '.($this->option('apply') ? 'apply' : 'dry run'));
    $this->info("Assets checked: {$summary['assets_checked']}");
    $this->info("Fixable assets: {$summary['fixable_assets']}");
    $this->info("Fixed assets: {$summary['fixed_assets']}");
    $this->info("Missing alt text: {$summary['missing_alt_text']}");
    $this->info("Missing dimensions: {$summary['missing_dimensions']}");
    $this->info("Missing variants: {$summary['missing_variants']}");
    $this->info("Missing imported credits: {$summary['missing_credit']}");

    return self::SUCCESS;
})->purpose('Dry-run or apply safe media metadata and responsive variant cleanup.');

Artisan::command('media:localize-featured-images {--apply} {--clear-failed} {--limit=250} {--download-timeout=30}', function (WordpressContentSanitizer $sanitizer): int {
    $apply = (bool) $this->option('apply');
    $clearFailed = (bool) $this->option('clear-failed');
    $limit = max(1, (int) $this->option('limit'));
    $downloadTimeout = max(1, (int) $this->option('download-timeout'));
    $sanitizer->setDownloadTimeoutSeconds($downloadTimeout);

    $externalAssets = MediaAsset::query()
        ->where('disk', 'external')
        ->whereNotNull('url')
        ->where('url', 'like', '%/wp-content/uploads/%')
        ->whereHas('featuredArticles', fn ($query) => $query->published())
        ->withCount([
            'featuredArticles as published_featured_articles_count' => fn ($query) => $query->published(),
        ])
        ->orderByDesc('published_featured_articles_count')
        ->orderBy('id')
        ->limit($limit)
        ->get();

    $summary = [
        'candidates' => $externalAssets->count(),
        'localized' => 0,
        'updated_article_references' => 0,
        'failed_downloads' => 0,
        'cleared_failed_references' => 0,
        'failures' => [],
    ];

    if ($apply) {
        $externalAssets->each(function (MediaAsset $externalAsset) use ($sanitizer, $clearFailed, &$summary): void {
            $sourceUrl = trim((string) ($externalAsset->metadata['source_url'] ?? $externalAsset->url));

            if ($sourceUrl === '') {
                $summary['failed_downloads']++;
                $summary['failures'][] = [
                    'media_asset_id' => $externalAsset->id,
                    'source_url' => null,
                    'reason' => 'Missing source URL.',
                ];

                return;
            }

            $localAsset = $sanitizer->downloadMedia($sourceUrl, [
                'wordpress_id' => $externalAsset->metadata['wordpress_id'] ?? null,
                'source' => 'wordpress_featured_image_localization',
                'mime_type' => $externalAsset->mime_type,
                'alt_text' => $externalAsset->alt_text,
                'caption' => $externalAsset->caption,
                'width' => $externalAsset->width,
                'height' => $externalAsset->height,
                'size_bytes' => $externalAsset->size_bytes,
            ]);

            if (! $localAsset) {
                $reason = $sanitizer->lastDownloadError() ?? 'Media could not be downloaded.';
                $summary['failed_downloads']++;
                $summary['failures'][] = [
                    'media_asset_id' => $externalAsset->id,
                    'source_url' => $sourceUrl,
                    'reason' => $reason,
                ];

                if ($clearFailed) {
                    $externalAsset->forceFill([
                        'metadata' => array_filter(
                            array_merge($externalAsset->metadata ?? [], [
                                'featured_image_localization_failed_at' => now()->toIso8601String(),
                                'featured_image_localization_error' => $reason,
                            ]),
                            fn ($value) => $value !== null && $value !== '',
                        ),
                    ])->save();

                    $summary['cleared_failed_references'] += Article::query()
                        ->where('featured_image_id', $externalAsset->id)
                        ->update(['featured_image_id' => null]);
                }

                return;
            }

            $summary['localized']++;
            $summary['updated_article_references'] += Article::query()
                ->where('featured_image_id', $externalAsset->id)
                ->update(['featured_image_id' => $localAsset->id]);
        });
    }

    $this->info('Mode: '.($apply ? 'apply' : 'dry run'));
    $this->info("Candidate external featured images: {$summary['candidates']}");
    $this->info("Localized media assets: {$summary['localized']}");
    $this->info("Article references updated: {$summary['updated_article_references']}");
    $this->info("Failed downloads: {$summary['failed_downloads']}");
    $this->info("Failed featured references cleared: {$summary['cleared_failed_references']}");

    foreach (array_slice($summary['failures'], 0, 10) as $failure) {
        $this->warn(sprintf(
            'Failed media #%s %s: %s',
            $failure['media_asset_id'],
            $failure['source_url'] ?? '-',
            $failure['reason'],
        ));
    }

    return self::SUCCESS;
})->purpose('Dry-run or apply localization of external WordPress featured images into local storage.');

Artisan::command('media:dedupe {--apply}', function (MediaDuplicateCleanupService $cleanup): int {
    $summary = $cleanup->repair(apply: (bool) $this->option('apply'));

    $this->info('Mode: '.($this->option('apply') ? 'apply' : 'dry run'));
    $this->info("Duplicate groups: {$summary['duplicate_groups']}");
    $this->info("Mergeable duplicate assets: {$summary['mergeable_duplicate_assets']}");
    $this->info("Skipped conflicting groups: {$summary['skipped_conflicting_groups']}");
    $this->info("Skipped conflicting assets: {$summary['skipped_conflicting_assets']}");
    $this->info("Merged duplicate assets: {$summary['merged_duplicate_assets']}");
    $this->info("References updated: {$summary['references_updated']}");

    return self::SUCCESS;
})->purpose('Dry-run or merge duplicate media asset records while preserving references.');

Artisan::command('homepage:qa', function (HomepageSectionQaService $reporter): int {
    $summary = $reporter->summary();

    $this->info("Homepage sections checked: {$summary['totals']['sections_checked']}");
    $this->info("Homepage QA issues: {$summary['totals']['issues']}");
    $this->info("No active homepage sections: {$summary['totals']['no_active_sections']}");
    $this->info("Missing manual articles: {$summary['totals']['manual_article_missing']}");
    $this->info("Unpublished manual articles: {$summary['totals']['manual_article_unpublished']}");
    $this->info("Unknown ad zones: {$summary['totals']['unknown_ad_zones']}");

    return (int) $summary['totals']['issues'] === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Report homepage section readiness issues.');

Artisan::command('articles:quality-report {--limit=100}', function (EditorialQualityService $quality): int {
    $limit = max(1, min((int) $this->option('limit'), 1000));
    $articles = Article::query()
        ->published()
        ->with(['author', 'category', 'featuredImage', 'sponsoredContentDetail'])
        ->latest('published_at')
        ->limit($limit)
        ->get();
    $rows = $articles
        ->map(fn (Article $article): array => [
            $article->id,
            $article->slug,
            collect($quality->inspect($article))->pluck('code')->join(', '),
        ])
        ->filter(fn (array $row): bool => $row[2] !== '')
        ->values();

    $this->table(['ID', 'Slug', 'Warnings'], $rows->all());
    $this->info("Reviewed {$articles->count()} published articles; {$rows->count()} require editorial review.");

    return self::SUCCESS;
})->purpose('Show a non-destructive editorial quality review queue for published articles.');

Artisan::command('redirects:qa-report', function (RedirectQaReportService $reporter): int {
    $summary = $reporter->summary();

    $this->info("Missing redirects: {$summary['totals']['missing']}");
    $this->info("Inactive redirects: {$summary['totals']['inactive']}");
    $this->info("Non-301 redirects: {$summary['totals']['non_301']}");
    $this->info("Duplicate redirect targets: {$summary['totals']['duplicate_targets']}");

    return ((int) $summary['totals']['missing']
        + (int) $summary['totals']['inactive']
        + (int) $summary['totals']['non_301']) === 0
        ? self::SUCCESS
        : self::FAILURE;
})->purpose('Report redirect readiness issues after WordPress migration.');

Artisan::command('redirects:qa-export', function (RedirectQaReportService $reporter): int {
    foreach (CsvExport::lines(
        ['issue', 'source_url', 'target_url', 'status_code', 'is_active', 'wordpress_id', 'details'],
        $reporter->exportRows(),
    ) as $line) {
        $this->line($line);
    }

    return self::SUCCESS;
})->purpose('Export redirect QA issue rows as CSV.');

Artisan::command('ads:campaign-export {--from=} {--to=}', function (AdReportService $reporter): int {
    foreach (CsvExport::lines(
        ['campaign_id', 'campaign', 'advertiser', 'status', 'impressions', 'clicks', 'ctr_percent'],
        $reporter->campaignExportRows($this->option('from'), $this->option('to')),
    ) as $line) {
        $this->line($line);
    }

    return self::SUCCESS;
})->purpose('Export ad campaign report rows as CSV.');

Artisan::command('ads:zone-inventory-export', function (AdReportService $reporter): int {
    $rows = $reporter->zoneInventoryRows();

    foreach (CsvExport::lines(
        ['zone_key', 'zone_name', 'page_context', 'is_active', 'allowed_sizes', 'active_creatives', 'eligible_creatives', 'fallback_creatives', 'global_fallback_creatives', 'has_launch_delivery', 'delivery_status'],
        $rows,
    ) as $line) {
        $this->line($line);
    }

    return $reporter->requiredZoneCoverage($rows)['blocking_count'] === 0
        ? self::SUCCESS
        : self::FAILURE;
})->purpose('Export direct-ad zone inventory and launch coverage rows as CSV.');

Artisan::command('seo:public-qa {--summary}', function (SeoHealthCheckService $reporter): int {
    $summary = $reporter->summary();
    $severity = $summary['totals']['issues_by_severity'];

    $this->info("Articles checked: {$summary['totals']['articles_checked']}");
    $this->info("Categories checked: {$summary['totals']['categories_checked']}");
    $this->info("Tags checked: {$summary['totals']['tags_checked']}");
    $this->info("Authors checked: {$summary['totals']['authors_checked']}");
    $this->info("Public SEO issues: {$summary['totals']['issues']}");
    $this->info("Critical issues: {$severity['critical']}");
    $this->info("Warnings: {$severity['warning']}");
    $this->info("Nice-to-have issues: {$severity['nice_to_have']}");

    if (! (bool) $this->option('summary')) {
        foreach ($summary['issues'] as $issue) {
            $this->line("[{$issue['severity']}:{$issue['code']}] {$issue['url']} {$issue['message']}");
        }
    }

    return (int) $severity['critical'] === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Report public SEO metadata gaps for articles, categories, tags, and authors.');

Artisan::command('seo:repair-metadata {--apply} {--only-imported}', function (SeoMetadataRepairService $repair): int {
    $apply = (bool) $this->option('apply');
    $articleSummary = $repair->repairArticles(
        apply: $apply,
        onlyImported: (bool) $this->option('only-imported'),
    );
    $pageSummary = $repair->repairPublicPages(apply: $apply);

    $this->info('Mode: '.($apply ? 'apply' : 'dry run'));
    $this->info("Articles checked: {$articleSummary['articles_checked']}");
    $this->info("Article excerpts repaired: {$articleSummary['article_excerpts_repaired']}");
    $this->info("Public pages checked: {$pageSummary['pages_checked']}");
    $this->info('Metadata created: '.($articleSummary['metadata_created'] + $pageSummary['metadata_created']));
    $this->info('Metadata updated: '.($articleSummary['metadata_updated'] + $pageSummary['metadata_updated']));
    $this->info('Canonical URLs repaired: '.($articleSummary['canonical_repaired'] + $pageSummary['canonical_repaired']));
    $this->info("OG images repaired: {$articleSummary['og_images_repaired']}");

    return self::SUCCESS;
})->purpose('Repair article SEO metadata fallbacks and configured frontend canonical URLs.');

Artisan::command('launch:qa {--json}', function (LaunchReadinessCheckService $checker): int {
    $summary = $checker->summary();

    if ($this->option('json')) {
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $summary['ready'] ? self::SUCCESS : self::FAILURE;
    }

    $this->info('Launch readiness: '.($summary['ready'] ? 'ready' : 'blockers found'));

    foreach ($summary['checks'] as $check) {
        $line = "[{$check['status']}] {$check['code']}: {$check['message']}";
        in_array($check['status'], ['fail', 'warn'], true) ? $this->warn($line) : $this->line($line);
    }

    return $summary['ready'] ? self::SUCCESS : self::FAILURE;
})->purpose('Run pre-launch checks for queues, settings, WordPress migration, homepage, and ad-zone health.');
