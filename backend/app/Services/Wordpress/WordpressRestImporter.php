<?php

namespace App\Services\Wordpress;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Redirect;
use App\Models\SeoMetadata;
use App\Models\Tag;
use App\Models\WordpressImportMapping;
use App\Models\WordpressImportRecord;
use App\Support\UrlPath;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class WordpressRestImporter
{
    public const int MAX_BATCH_PER_PAGE = 20;

    public const string CONTENT_PIPELINE_VERSION = '2026-07-17.1';

    public const string UNTITLED_NONPUBLIC_WARNING = 'untitled_nonpublic_post';

    private const string FROZEN_TAXONOMY_EVIDENCE_VERSION = '2026-07-15.1';

    private const int DATABASE_LOCK_RETRY_ATTEMPTS = 3;

    /** @var array<int, string> */
    private const array CONTENT_REVIEW_WARNINGS = [
        'broken_embed',
        'unsafe_html_removed',
        'unsupported_shortcode',
        'untrusted_iframe_removed',
    ];

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $wordpressAuthorCache = [];

    /** @var array<string, string> */
    private array $frozenLinkMap = [];

    public function __construct(
        private readonly WordpressContentSanitizer $contentSanitizer,
        private readonly WordpressFrozenContentDecisionProvenance $contentDecisionProvenance,
    ) {}

    /** @param array<string, string> $linkMap */
    public function setFrozenLinkMap(array $linkMap): void
    {
        $this->frozenLinkMap = $linkMap;
    }

    public function clearFrozenLinkMap(): void
    {
        $this->frozenLinkMap = [];
    }

    /**
     * @return array{imported: int, failed: int, normalizations: array<string, int>}
     */
    public function importPosts(string $baseUrl, int $perPage = 20): array
    {
        $this->contentSanitizer->setMediaSourceBase($baseUrl);

        try {
            $summary = [
                'imported' => 0,
                'failed' => 0,
                'normalizations' => $this->emptyNormalizationSummary(),
            ];
            $currentPage = 1;
            $totalPages = 1;

            do {
                $page = $this->fetchPostsPage($baseUrl, $perPage, $currentPage);
                $summary = $this->importPostCollection($page['posts'], $summary, trackNormalizations: true);
                $totalPages = $page['total_pages'];
                $currentPage++;
            } while ($currentPage <= $totalPages);

            return $summary;
        } finally {
            $this->contentSanitizer->setMediaSourceBase(null);
        }
    }

    /**
     * @return array{imported: int, failed: int}
     */
    public function importPostsPage(string $baseUrl, int $perPage = 20, int $page = 1): array
    {
        $this->contentSanitizer->setMediaSourceBase($baseUrl);

        try {
            $postsPage = $this->fetchPostsPage($baseUrl, $perPage, $page);

            return $this->importPostCollection($postsPage['posts'], ['imported' => 0, 'failed' => 0]);
        } finally {
            $this->contentSanitizer->setMediaSourceBase(null);
        }
    }

    /**
     * @param  null|callable(int, int, array{imported: int, failed: int}): void  $onPageImported
     * @return array{imported: int, failed: int, pages_scanned: int, total_pages: int}
     */
    public function importPostsBatch(
        string $baseUrl,
        int $perPage = 50,
        int $limit = 100,
        int $offset = 0,
        float $sleepSeconds = 0.0,
        ?callable $onPageImported = null,
    ): array {
        $this->contentSanitizer->setMediaSourceBase($baseUrl);
        $this->contentSanitizer->setMediaDownloadsEnabled(false);

        try {
            return $this->importPostsBatchWithoutMediaDownloads(
                $baseUrl,
                $perPage,
                $limit,
                $offset,
                $sleepSeconds,
                $onPageImported,
            );
        } finally {
            $this->contentSanitizer->setMediaDownloadsEnabled(true);
            $this->contentSanitizer->setMediaSourceBase(null);
        }
    }

    /**
     * @return array{
     *     source_posts: int,
     *     imported: int,
     *     failed: int,
     *     skipped_existing: int,
     *     skipped_recorded_not_imported: int,
     *     pages_scanned: int,
     *     total_pages: int,
     *     missing_ids: array<int, int>,
     *     recorded_not_imported_ids: array<int, int>
     * }
     */
    public function importMissingPosts(string $baseUrl, int $perPage = 20, ?int $maxPages = null): array
    {
        $this->contentSanitizer->setMediaSourceBase($baseUrl);

        try {
            $perPage = max(1, min($perPage, 100));
            $currentPage = 1;
            $pagesToScan = null;
            $importedIds = $this->importedWordpressPostIds();
            $recordedIds = $this->recordedWordpressPostIds();
            $summary = [
                'source_posts' => 0,
                'imported' => 0,
                'failed' => 0,
                'skipped_existing' => 0,
                'skipped_recorded_not_imported' => 0,
                'pages_scanned' => 0,
                'total_pages' => 1,
                'missing_ids' => [],
                'recorded_not_imported_ids' => [],
            ];

            do {
                $postsPage = $this->fetchPostsPage($baseUrl, $perPage, $currentPage);
                $summary['total_pages'] = $postsPage['total_pages'];
                $pagesToScan ??= $maxPages === null ? $summary['total_pages'] : min($summary['total_pages'], max(1, $maxPages));

                $postsToImport = [];

                foreach ($postsPage['posts'] as $post) {
                    $wordpressId = (int) ($post['id'] ?? 0);

                    if ($wordpressId <= 0) {
                        continue;
                    }

                    $summary['source_posts']++;

                    if (isset($importedIds[$wordpressId])) {
                        $summary['skipped_existing']++;

                        continue;
                    }

                    if (isset($recordedIds[$wordpressId])) {
                        $summary['skipped_recorded_not_imported']++;
                        $summary['recorded_not_imported_ids'][] = $wordpressId;

                        continue;
                    }

                    $summary['missing_ids'][] = $wordpressId;
                    $postsToImport[] = $post;
                }

                $pageSummary = $this->importPostCollection($postsToImport, ['imported' => 0, 'failed' => 0]);
                $summary['imported'] += $pageSummary['imported'];
                $summary['failed'] += $pageSummary['failed'];
                $summary['pages_scanned']++;
                $currentPage++;
            } while ($pagesToScan !== null && $currentPage <= $pagesToScan);

            $summary['missing_ids'] = array_values(array_unique($summary['missing_ids']));
            $summary['recorded_not_imported_ids'] = array_values(array_unique($summary['recorded_not_imported_ids']));

            return $summary;
        } finally {
            $this->contentSanitizer->setMediaSourceBase(null);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function taxonomyInventory(string $baseUrl, int $perPage = 100, ?int $maxPages = null): array
    {
        $categories = $this->fetchAllTermsInventory($baseUrl, 'categories', $perPage, $maxPages);
        $tags = $this->fetchAllTermsInventory($baseUrl, 'tags', $perPage, $maxPages);

        return [
            'categories' => count($categories['terms']),
            'tags' => count($tags['terms']),
            'category_ids' => $this->termIds($categories['terms']),
            'tag_ids' => $this->termIds($tags['terms']),
            'scan_complete' => $categories['scan_complete'] && $tags['scan_complete'],
            'category_pages_scanned' => $categories['pages_scanned'],
            'category_total_pages' => $categories['total_pages'],
            'expected_categories' => $categories['expected_terms'],
            'unique_categories' => $categories['unique_terms'],
            'category_total_header_present' => $categories['total_terms_header_present'],
            'category_total_pages_header_present' => $categories['total_pages_header_present'],
            'category_pagination_metadata_consistent' => $categories['pagination_metadata_consistent'],
            'category_cardinality_consistent' => $categories['cardinality_consistent'],
            'tag_pages_scanned' => $tags['pages_scanned'],
            'tag_total_pages' => $tags['total_pages'],
            'expected_tags' => $tags['expected_terms'],
            'unique_tags' => $tags['unique_terms'],
            'tag_total_header_present' => $tags['total_terms_header_present'],
            'tag_total_pages_header_present' => $tags['total_pages_header_present'],
            'tag_pagination_metadata_consistent' => $tags['pagination_metadata_consistent'],
            'tag_cardinality_consistent' => $tags['cardinality_consistent'],
        ];
    }

    /**
     * @return array{
     *     categories_imported: int,
     *     tags_imported: int,
     *     failed: int,
     *     total_categories: int,
     *     total_tags: int
     * }
     */
    public function importTaxonomies(string $baseUrl, int $perPage = 100): array
    {
        $categories = $this->fetchAllTerms($baseUrl, 'categories', $perPage);
        $tags = $this->fetchAllTerms($baseUrl, 'tags', $perPage);
        $summary = [
            'categories_imported' => 0,
            'tags_imported' => 0,
            'failed' => 0,
            'total_categories' => count($categories),
            'total_tags' => count($tags),
        ];

        foreach ($categories as $category) {
            try {
                $this->resolveCategoryTerm($category);
                $summary['categories_imported']++;
            } catch (Throwable $exception) {
                $this->recordTermFailure('category', $category, $exception);
                $summary['failed']++;
            }
        }

        foreach ($categories as $category) {
            $this->syncCategoryParent($category);
        }

        foreach ($tags as $tag) {
            try {
                $this->resolveTagTerm($tag);
                $summary['tags_imported']++;
            } catch (Throwable $exception) {
                $this->recordTermFailure('tag', $tag, $exception);
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * @param  null|callable(int, int, array{imported: int, failed: int}): void  $onPageImported
     * @return array{imported: int, failed: int, pages_scanned: int, total_pages: int}
     */
    private function importPostsBatchWithoutMediaDownloads(
        string $baseUrl,
        int $perPage,
        int $limit,
        int $offset,
        float $sleepSeconds,
        ?callable $onPageImported,
    ): array {
        $perPage = max(1, min($perPage, self::MAX_BATCH_PER_PAGE));
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $currentPage = intdiv($offset, $perPage) + 1;
        $skipOnCurrentPage = $offset % $perPage;
        $processed = 0;
        $summary = [
            'imported' => 0,
            'failed' => 0,
            'pages_scanned' => 0,
            'total_pages' => 1,
        ];

        do {
            $postsPage = $this->fetchPostsPage($baseUrl, $perPage, $currentPage);
            $summary['total_pages'] = $postsPage['total_pages'];
            $posts = $postsPage['posts'];

            if ($skipOnCurrentPage > 0) {
                $posts = array_slice($posts, $skipOnCurrentPage);
                $skipOnCurrentPage = 0;
            }

            $posts = array_slice($posts, 0, $limit - $processed);
            $pageSummary = $this->importPostCollection($posts, ['imported' => 0, 'failed' => 0]);
            $summary['imported'] += $pageSummary['imported'];
            $summary['failed'] += $pageSummary['failed'];
            $summary['pages_scanned']++;
            $processed += count($posts);

            if ($onPageImported) {
                $onPageImported($currentPage, $summary['total_pages'], $pageSummary);
            }

            $currentPage++;

            if ($sleepSeconds > 0 && $processed < $limit && $currentPage <= $summary['total_pages']) {
                usleep((int) ($sleepSeconds * 1_000_000));
            }
        } while ($processed < $limit && $currentPage <= $summary['total_pages']);

        return $summary;
    }

    public function countPostPages(string $baseUrl, int $perPage = 20): int
    {
        return $this->fetchPostsPage($baseUrl, $perPage, 1)['total_pages'];
    }

    /**
     * @return array<string, mixed>
     */
    public function dryRunPostsPage(string $baseUrl, int $perPage = 20, int $page = 1): array
    {
        $postsPage = $this->fetchPostsPage($baseUrl, $perPage, $page);
        $posts = collect($postsPage['posts'])
            ->map(fn (array $post): array => $this->dryRunPostSummary($post))
            ->values();

        return [
            'total_pages' => $postsPage['total_pages'],
            'expected_posts' => $postsPage['total_posts'],
            'total_posts_header_present' => $postsPage['total_posts'] !== null,
            'status_scope' => $postsPage['status_scope'],
            'summary' => $this->dryRunSummary($posts),
            'normalization_preview' => $this->sourceNormalizationPreview($posts),
            'posts' => $posts->all(),
        ];
    }

    /**
     * @return array{
     *     total_pages: int,
     *     pages_scanned: int,
     *     summary: array<string, int>,
     *     posts: array<int, array<string, mixed>>
     * }
     */
    public function dryRunPosts(string $baseUrl, int $perPage = 20, ?int $maxPages = null): array
    {
        $firstPage = $this->dryRunPostsPage($baseUrl, $perPage, 1);
        $totalPages = $firstPage['total_pages'];
        $pagesToScan = $maxPages === null ? $totalPages : min($totalPages, max(1, $maxPages));
        $posts = collect($firstPage['posts']);
        $expectedPosts = $firstPage['expected_posts'];
        $totalPostsHeaderPresent = $firstPage['total_posts_header_present'];
        $statusScope = $firstPage['status_scope'];
        $paginationMetadataConsistent = true;

        for ($page = 2; $page <= $pagesToScan; $page++) {
            $pagePreview = $this->dryRunPostsPage($baseUrl, $perPage, $page);
            $posts = $posts->merge($pagePreview['posts']);
            $paginationMetadataConsistent = $paginationMetadataConsistent
                && $pagePreview['total_pages'] === $totalPages
                && $pagePreview['expected_posts'] === $expectedPosts
                && $pagePreview['status_scope'] === $statusScope;
            $totalPostsHeaderPresent = $totalPostsHeaderPresent && $pagePreview['total_posts_header_present'];
        }

        $uniquePosts = $posts
            ->pluck('wordpress_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->count();
        $scanComplete = $pagesToScan === $totalPages
            && $paginationMetadataConsistent
            && $totalPostsHeaderPresent
            && is_int($expectedPosts)
            && $expectedPosts === $posts->count()
            && $uniquePosts === $posts->count();

        return [
            'total_pages' => $totalPages,
            'pages_scanned' => $pagesToScan,
            'expected_posts' => $expectedPosts,
            'unique_posts' => $uniquePosts,
            'total_posts_header_present' => $totalPostsHeaderPresent,
            'pagination_metadata_consistent' => $paginationMetadataConsistent,
            'status_scope' => $statusScope,
            'scan_complete' => $scanComplete,
            'summary' => $this->dryRunSummary($posts),
            'normalization_preview' => $this->sourceNormalizationPreview($posts),
            'posts' => $posts->values()->all(),
        ];
    }

    /**
     * @return array<int, array{
     *     wordpress_id: int,
     *     title: string,
     *     slug: string,
     *     status: string,
     *     old_url: ?string,
     *     author_status: string,
     *     author_source_id: ?int,
     *     author_source_slug: ?string,
     *     author_source_name: ?string,
     *     author_target_slug: ?string,
     *     author_target_name: ?string,
     *     category_status: string,
     *     category_source_id: ?int,
     *     category_source_slug: ?string,
     *     category_source_name: ?string,
     *     category_target_slug: ?string,
     *     category_target_name: ?string,
     *     tag_statuses: string,
     *     tag_source_slugs: string,
     *     tag_target_slugs: string,
     *     featured_image_status: string,
     *     featured_image_url: ?string,
     *     warnings: string
     * }>
     */
    public function dryRunExportRows(string $baseUrl, int $perPage = 20, ?int $maxPages = null): array
    {
        return collect($this->dryRunPosts($baseUrl, $perPage, $maxPages)['posts'])
            ->map(fn (array $post): array => $this->dryRunExportRow($post))
            ->all();
    }

    /**
     * @return array{
     *     total_post_records: int,
     *     imported_count: int,
     *     failed_count: int,
     *     pending_count: int,
     *     skipped_count: int,
     *     missing_featured_image_count: int,
     *     failed: array<int, array<string, mixed>>,
     *     missing_featured_images: array<int, array<string, mixed>>,
     *     latest_records: array<int, array<string, mixed>>
     * }
     */
    public function report(): array
    {
        $failed = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'failed')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (WordpressImportRecord $record) => $this->recordSummary($record))
            ->all();

        $missingFeaturedImages = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->whereJsonContains('warnings', 'missing_featured_image')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (WordpressImportRecord $record) => $this->recordSummary($record))
            ->all();

        $latestRecords = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (WordpressImportRecord $record) => $this->recordSummary($record))
            ->all();

        return [
            'total_post_records' => WordpressImportRecord::query()
                ->where('wordpress_type', 'post')
                ->count(),
            'imported_count' => WordpressImportRecord::query()
                ->where('wordpress_type', 'post')
                ->where('status', 'imported')
                ->count(),
            'failed_count' => WordpressImportRecord::query()
                ->where('wordpress_type', 'post')
                ->where('status', 'failed')
                ->count(),
            'pending_count' => WordpressImportRecord::query()
                ->where('wordpress_type', 'post')
                ->where('status', 'pending')
                ->count(),
            'skipped_count' => WordpressImportRecord::query()
                ->where('wordpress_type', 'post')
                ->where('status', 'skipped')
                ->count(),
            'missing_featured_image_count' => WordpressImportRecord::query()
                ->where('wordpress_type', 'post')
                ->whereJsonContains('warnings', 'missing_featured_image')
                ->count(),
            'failed' => $failed,
            'missing_featured_images' => $missingFeaturedImages,
            'latest_records' => $latestRecords,
        ];
    }

    /**
     * @return array{
     *     author_legacy_urls_backfilled: int,
     *     author_legacy_urls_unresolved: int,
     *     rewritten_articles: int,
     *     redirects_created_or_updated: int
     * }
     */
    public function finalizeImportedContent(?string $sourceBaseUrl = null): array
    {
        $authorLegacyUrlsBackfilled = $this->backfillMissingAuthorLegacyUrls($sourceBaseUrl);
        $rewrittenArticles = 0;
        $redirects = 0;

        $records = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->where('importable_type', Article::class)
            ->whereNotNull('importable_id')
            ->get();

        foreach ($records as $record) {
            if (! $record->importable instanceof Article) {
                continue;
            }

            $article = $record->importable;
            $rewrittenBody = $this->rewriteInternalLinks($article->body_html);

            if ($rewrittenBody !== $article->body_html) {
                $article->forceFill(['body_html' => $rewrittenBody])->save();
                $rewrittenArticles++;
            }
        }

        foreach ($this->importedLinkRecords() as $record) {
            if ($this->isSelfRedirect($record->old_url, $record->new_url)) {
                continue;
            }

            Redirect::query()->updateOrCreate(
                ['source_url' => $record->old_url],
                [
                    'target_url' => $record->new_url,
                    'status_code' => 301,
                    'is_active' => true,
                ],
            );
            $redirects++;
        }

        return [
            'author_legacy_urls_backfilled' => $authorLegacyUrlsBackfilled,
            'author_legacy_urls_unresolved' => $this->missingAuthorLegacyUrlRecords()->count(),
            'rewritten_articles' => $rewrittenArticles,
            'redirects_created_or_updated' => $redirects,
        ];
    }

    private function backfillMissingAuthorLegacyUrls(?string $sourceBaseUrl): int
    {
        $records = $this->missingAuthorLegacyUrlRecords();

        if ($records->isEmpty()) {
            return 0;
        }

        $sourceBaseUrl = $this->validatedSourceBaseUrl($sourceBaseUrl ?? config('services.wordpress.base_url'));
        $postEvidence = $this->storedPostAuthorEvidence($records->pluck('wordpress_id')->map(
            fn (mixed $id): int => (int) $id,
        )->all());
        $backfilled = 0;

        foreach ($records as $record) {
            $recordPayload = is_array($record->source_payload) ? $record->source_payload : [];
            $storedPostPayload = $postEvidence[(int) $record->wordpress_id] ?? [];
            $candidates = [
                'author_import_payload' => $recordPayload,
                'stored_post_payload' => $storedPostPayload,
            ];
            $linkedEvidence = $this->firstAuthorEvidenceWithLink($candidates);

            if ($linkedEvidence === null && $sourceBaseUrl !== null) {
                $candidates['wordpress_users_api'] = $this->fetchWordpressAuthor(
                    $sourceBaseUrl,
                    (int) $record->wordpress_id,
                ) ?? [];
                $linkedEvidence = $this->firstAuthorEvidenceWithLink($candidates);
            }

            $evidence = $linkedEvidence ?? $this->firstAuthorEvidenceWithSlug($candidates);

            if ($evidence === null) {
                continue;
            }

            $evidenceSource = $evidence['source'];
            $evidencePayload = $evidence['payload'];
            $legacyUrl = $linkedEvidence === null
                ? null
                : $this->absoluteHttpUrl($evidencePayload['link'] ?? null);
            $urlSource = $legacyUrl === null ? 'source_slug' : 'source_link';

            if ($legacyUrl === null && $sourceBaseUrl !== null) {
                $legacyUrl = $this->legacyAuthorUrlFromSourceSlug($sourceBaseUrl, $evidencePayload['slug'] ?? null);
            }

            if ($legacyUrl === null) {
                continue;
            }

            $sourcePayload = array_replace($recordPayload, $evidencePayload);
            $sourcePayload['_razbudise']['legacy_author_url_evidence'] = [
                'evidence_source' => $evidenceSource,
                'url_source' => $urlSource,
                'source_base_url' => $urlSource === 'source_slug' ? $sourceBaseUrl : null,
                'source_slug' => $evidencePayload['slug'] ?? null,
            ];

            $record->forceFill([
                'old_url' => $legacyUrl,
                'source_payload' => $sourcePayload,
            ])->save();
            $backfilled++;
        }

        return $backfilled;
    }

    /**
     * @return Collection<int, WordpressImportRecord>
     */
    private function missingAuthorLegacyUrlRecords(): Collection
    {
        return WordpressImportRecord::query()
            ->where('wordpress_type', 'author')
            ->where('status', 'imported')
            ->whereNotNull('new_url')
            ->where(function ($query): void {
                $query->whereNull('old_url')->orWhereRaw("TRIM(old_url) = ''");
            })
            ->orderBy('wordpress_id')
            ->get();
    }

    /**
     * @param  array<int, int>  $wordpressAuthorIds
     * @return array<int, array<string, mixed>>
     */
    private function storedPostAuthorEvidence(array $wordpressAuthorIds): array
    {
        $wanted = collect($wordpressAuthorIds)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();
        $evidence = [];

        if ($wanted === []) {
            return $evidence;
        }

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->whereNotNull('source_payload')
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$evidence, $wanted): void {
                foreach ($records as $record) {
                    $author = data_get($record->source_payload, '_embedded.author.0');

                    if (! is_array($author)) {
                        continue;
                    }

                    $wordpressAuthorId = (int) ($author['id'] ?? data_get($record->source_payload, 'author'));

                    if (! isset($wanted[$wordpressAuthorId])) {
                        continue;
                    }

                    $hasLink = $this->absoluteHttpUrl($author['link'] ?? null) !== null;
                    $hasSlug = $this->sourceSlug($author['slug'] ?? null) !== null;

                    if (! $hasLink && ! $hasSlug) {
                        continue;
                    }

                    $current = $evidence[$wordpressAuthorId] ?? null;

                    if ($current === null
                        || ($hasLink && $this->absoluteHttpUrl($current['link'] ?? null) === null)) {
                        $evidence[$wordpressAuthorId] = $author;
                    }
                }
            });

        return $evidence;
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidates
     * @return array{source: string, payload: array<string, mixed>}|null
     */
    private function firstAuthorEvidenceWithLink(array $candidates): ?array
    {
        foreach ($candidates as $source => $payload) {
            if ($this->absoluteHttpUrl($payload['link'] ?? null) !== null) {
                return ['source' => $source, 'payload' => $payload];
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidates
     * @return array{source: string, payload: array<string, mixed>}|null
     */
    private function firstAuthorEvidenceWithSlug(array $candidates): ?array
    {
        foreach ($candidates as $source => $payload) {
            if ($this->sourceSlug($payload['slug'] ?? null) !== null) {
                return ['source' => $source, 'payload' => $payload];
            }
        }

        return null;
    }

    private function validatedSourceBaseUrl(mixed $sourceBaseUrl): ?string
    {
        $url = $this->absoluteHttpUrl($sourceBaseUrl);

        if ($url === null) {
            return null;
        }

        if (parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            return null;
        }

        return rtrim($url, '/');
    }

    private function absoluteHttpUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($url === '' || ! in_array($scheme, ['http', 'https'], true) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    private function legacyAuthorUrlFromSourceSlug(string $sourceBaseUrl, mixed $slug): ?string
    {
        $slug = $this->sourceSlug($slug);

        if ($slug === null) {
            return null;
        }

        return $sourceBaseUrl.'/author/'.rawurlencode(rawurldecode($slug)).'/';
    }

    private function sourceSlug(mixed $slug): ?string
    {
        if (! is_string($slug)) {
            return null;
        }

        $slug = trim($slug);
        $decoded = rawurldecode($slug);

        if ($slug === ''
            || $decoded === ''
            || str_contains($decoded, '/')
            || str_contains($decoded, '\\')
            || str_contains($decoded, "\0")
            || in_array($decoded, ['.', '..'], true)) {
            return null;
        }

        return $slug;
    }

    /**
     * @param  array<int, array<string, mixed>>  $posts
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function importPostCollection(array $posts, array $summary, bool $trackNormalizations = false): array
    {
        foreach ($posts as $post) {
            try {
                $this->importPostWithDatabaseLockRetry($post);
                $summary['imported']++;

                if ($trackNormalizations) {
                    $summary['normalizations'] = $this->mergeNormalizationSummary(
                        $summary['normalizations'] ?? $this->emptyNormalizationSummary(),
                        $this->normalizationSummaryForPost($post),
                    );
                }
            } catch (Throwable $exception) {
                $this->recordFailure($post, $exception);
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function importPostWithDatabaseLockRetry(
        array $post,
        bool $allowUntitledNonpublic = false,
    ): Article {
        for ($attempt = 1; $attempt <= self::DATABASE_LOCK_RETRY_ATTEMPTS; $attempt++) {
            try {
                return $this->importPost($post, $allowUntitledNonpublic);
            } catch (Throwable $exception) {
                if (! $this->isDatabaseLockException($exception) || $attempt === self::DATABASE_LOCK_RETRY_ATTEMPTS) {
                    throw $exception;
                }

                usleep($attempt * 250_000);
            }
        }
    }

    private function isDatabaseLockException(Throwable $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'database is locked');
    }

    /**
     * @return array{posts: array<int, array<string, mixed>>, total_pages: int, total_posts: ?int, status_scope: string}
     */
    private function fetchPostsPage(string $baseUrl, int $perPage, int $page): array
    {
        $response = $this->fetchPostsPageResponse($baseUrl, $perPage, $page, 'any');

        $statusScope = 'any';

        if ($this->shouldFallbackToPublishedPosts($response)) {
            $response = $this->fetchPostsPageResponse($baseUrl, $perPage, $page, 'publish');
            $statusScope = 'publish';
        }

        $response->throw();

        $posts = $response->json();
        $posts = is_array($posts) ? $posts : [];
        $posts = array_map(
            fn (array $post): array => $this->stripUntrustedReconciliationProvenance(
                $this->stripUntrustedContentDecisionEvidence($post),
            ),
            $posts,
        );
        $totalPostsHeader = $response->header('X-WP-Total');

        return [
            'posts' => $this->enrichPostsWithMissingAuthors($baseUrl, $posts),
            'total_pages' => max(1, (int) ($response->header('X-WP-TotalPages') ?? 1)),
            'total_posts' => is_string($totalPostsHeader) && ctype_digit($totalPostsHeader)
                ? (int) $totalPostsHeader
                : null,
            'status_scope' => $statusScope,
        ];
    }

    private function fetchPostsPageResponse(string $baseUrl, int $perPage, int $page, string $status): Response
    {
        return Http::acceptJson()
            ->timeout(30)
            ->retry(3, 250, throw: false)
            ->get(rtrim($baseUrl, '/').'/wp-json/wp/v2/posts', [
                '_embed' => 1,
                'page' => max(1, $page),
                'per_page' => max(1, min($perPage, 100)),
                'status' => $status,
            ]);
    }

    private function shouldFallbackToPublishedPosts(Response $response): bool
    {
        return $response->status() === 400
            && $response->json('code') === 'rest_invalid_param'
            && $response->json('data.details.status.code') === 'rest_forbidden_status';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllTerms(string $baseUrl, string $endpoint, int $perPage, ?int $maxPages = null): array
    {
        return $this->fetchAllTermsInventory($baseUrl, $endpoint, $perPage, $maxPages)['terms'];
    }

    /**
     * @return array{
     *     terms: array<int, array<string, mixed>>,
     *     pages_scanned: int,
     *     total_pages: int,
     *     expected_terms: ?int,
     *     unique_terms: int,
     *     total_terms_header_present: bool,
     *     total_pages_header_present: bool,
     *     pagination_metadata_consistent: bool,
     *     cardinality_consistent: bool,
     *     scan_complete: bool
     * }
     */
    private function fetchAllTermsInventory(string $baseUrl, string $endpoint, int $perPage, ?int $maxPages = null): array
    {
        $currentPage = 1;
        $totalPages = 1;
        $terms = [];
        $pagesScanned = 0;
        $paginationMetadataConsistent = true;
        $expectedTerms = null;
        $totalTermsHeaderPresent = true;
        $totalPagesHeaderPresent = true;

        do {
            $page = $this->fetchTermsPage($baseUrl, $endpoint, $perPage, $currentPage);
            $terms = array_merge($terms, $page['terms']);

            if ($currentPage === 1) {
                $expectedTerms = $page['total_terms'];
            } else {
                $paginationMetadataConsistent = $paginationMetadataConsistent
                    && $page['total_pages'] === $totalPages
                    && $page['total_terms'] === $expectedTerms;
            }

            $totalTermsHeaderPresent = $totalTermsHeaderPresent && $page['total_terms_header_present'];
            $totalPagesHeaderPresent = $totalPagesHeaderPresent && $page['total_pages_header_present'];
            $totalPages = $page['total_pages'];
            $pagesToScan = $maxPages === null ? $totalPages : min($totalPages, max(1, $maxPages));
            $pagesScanned++;
            $currentPage++;
        } while ($currentPage <= $pagesToScan);

        $uniqueTerms = count($this->termIds($terms));
        $termsCollected = count($terms);
        $cardinalityConsistent = $totalTermsHeaderPresent
            && $expectedTerms !== null
            && $expectedTerms === $termsCollected
            && $uniqueTerms === $termsCollected;

        return [
            'terms' => $terms,
            'pages_scanned' => $pagesScanned,
            'total_pages' => $totalPages,
            'expected_terms' => $expectedTerms,
            'unique_terms' => $uniqueTerms,
            'total_terms_header_present' => $totalTermsHeaderPresent,
            'total_pages_header_present' => $totalPagesHeaderPresent,
            'pagination_metadata_consistent' => $paginationMetadataConsistent,
            'cardinality_consistent' => $cardinalityConsistent,
            'scan_complete' => $pagesScanned === $totalPages
                && $paginationMetadataConsistent
                && $totalPagesHeaderPresent
                && $cardinalityConsistent,
        ];
    }

    /**
     * @return array{
     *     terms: array<int, array<string, mixed>>,
     *     total_pages: int,
     *     total_terms: ?int,
     *     total_terms_header_present: bool,
     *     total_pages_header_present: bool
     * }
     */
    private function fetchTermsPage(string $baseUrl, string $endpoint, int $perPage, int $page): array
    {
        $response = Http::acceptJson()
            ->timeout(30)
            ->retry(3, 250, throw: false)
            ->get(rtrim($baseUrl, '/')."/wp-json/wp/v2/{$endpoint}", [
                '_fields' => 'id,name,slug,description,parent,link,taxonomy,count',
                'hide_empty' => false,
                'page' => max(1, $page),
                'per_page' => max(1, min($perPage, 100)),
            ]);

        $response->throw();
        $terms = $response->json();
        $totalTermsHeader = $response->header('X-WP-Total');
        $totalPagesHeader = $response->header('X-WP-TotalPages');

        return [
            'terms' => is_array($terms) ? array_values(array_filter($terms, fn (mixed $term): bool => is_array($term))) : [],
            'total_pages' => max(1, (int) ($totalPagesHeader ?? 1)),
            'total_terms' => is_string($totalTermsHeader) && ctype_digit($totalTermsHeader)
                ? (int) $totalTermsHeader
                : null,
            'total_terms_header_present' => is_string($totalTermsHeader) && ctype_digit($totalTermsHeader),
            'total_pages_header_present' => is_string($totalPagesHeader) && ctype_digit($totalPagesHeader),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $terms
     * @return array<int, int>
     */
    private function termIds(array $terms): array
    {
        return collect($terms)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, true>
     */
    private function importedWordpressPostIds(): array
    {
        $articleIds = Article::query()
            ->whereNotNull('old_wordpress_id')
            ->pluck('old_wordpress_id')
            ->map(fn (mixed $id): int => (int) $id);

        $recordIds = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->pluck('wordpress_id')
            ->map(fn (mixed $id): int => (int) $id);

        return $articleIds
            ->merge($recordIds)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();
    }

    /**
     * @return array<int, true>
     */
    private function recordedWordpressPostIds(): array
    {
        return WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->pluck('wordpress_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $posts
     * @return array<int, array<string, mixed>>
     */
    private function enrichPostsWithMissingAuthors(string $baseUrl, array $posts): array
    {
        return array_map(function (array $post) use ($baseUrl): array {
            if ($this->hasUsableEmbeddedAuthor($post)) {
                return $post;
            }

            $wordpressAuthorId = (int) ($post['author'] ?? 0);

            if ($wordpressAuthorId <= 0) {
                return $post;
            }

            $author = $this->fetchWordpressAuthor($baseUrl, $wordpressAuthorId)
                ?? $this->wordpressAuthorFromPostMetadata($post, $wordpressAuthorId);

            if (! $author) {
                return $post;
            }

            $embedded = $post['_embedded'] ?? [];
            $embedded = is_array($embedded) ? $embedded : [];
            $embedded['author'] = [$author];
            $post['_embedded'] = $embedded;

            return $post;
        }, $posts);
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function hasUsableEmbeddedAuthor(array $post): bool
    {
        $author = data_get($post, '_embedded.author.0');

        if (! is_array($author)) {
            return false;
        }

        return $this->cleanText($author['name'] ?? null) !== ''
            || trim((string) ($author['slug'] ?? '')) !== ''
            || (int) ($author['id'] ?? 0) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchWordpressAuthor(string $baseUrl, int $wordpressAuthorId): ?array
    {
        $cacheKey = rtrim($baseUrl, '/').'#'.$wordpressAuthorId;

        if (array_key_exists($cacheKey, $this->wordpressAuthorCache)) {
            return $this->wordpressAuthorCache[$cacheKey];
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->get(rtrim($baseUrl, '/')."/wp-json/wp/v2/users/{$wordpressAuthorId}");

        if (! $response->ok() || ! is_array($response->json())) {
            return $this->wordpressAuthorCache[$cacheKey] = null;
        }

        return $this->wordpressAuthorCache[$cacheKey] = $response->json();
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>|null
     */
    private function wordpressAuthorFromPostMetadata(array $post, int $wordpressAuthorId): ?array
    {
        $name = $this->cleanText(data_get($post, 'yoast_head_json.author'))
            ?: $this->cleanText(data_get($post, 'yoast_head_json.twitter_misc.Written by'))
            ?: $this->schemaAuthorName($post);

        if ($name === '') {
            return null;
        }

        return [
            'id' => $wordpressAuthorId,
            'name' => $name,
            'slug' => $this->safeSlug('', $name),
            'description' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function schemaAuthorName(array $post): string
    {
        $graph = data_get($post, 'yoast_head_json.schema.@graph', []);

        if (! is_array($graph)) {
            return '';
        }

        foreach ($graph as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['@type'] ?? null) === 'Article') {
                $name = $this->cleanText(data_get($node, 'author.name'));

                if ($name !== '') {
                    return $name;
                }
            }
        }

        foreach ($graph as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['@type'] ?? null) === 'Person') {
                $name = $this->cleanText($node['name'] ?? null);

                if ($name !== '') {
                    return $name;
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $post
     */
    public function importStoredPostPayload(
        array $post,
        ?string $sourceBaseUrl = null,
        bool $trustContentDecisionEvidence = false,
        bool $allowUntitledNonpublic = false,
    ): Article {
        $baseUrl = $sourceBaseUrl ?? config('services.wordpress.base_url');
        $this->contentSanitizer->setMediaSourceBase(is_string($baseUrl) ? $baseUrl : null);
        $hasTrustedContentDecisionProvenance = $trustContentDecisionEvidence
            && $this->hasFrozenContentDecisionProvenance($post);
        $hasTrustedReconciliationProvenance = $this->hasFrozenReconciliationProvenance($post);
        $post = $hasTrustedContentDecisionProvenance
            ? $post
            : $this->stripUntrustedContentDecisionEvidence($post);

        if ($hasTrustedReconciliationProvenance
            && ! $this->hasFrozenReconciliationProvenance($post)) {
            $hasTrustedReconciliationProvenance = false;
        }

        if (! $hasTrustedReconciliationProvenance) {
            $post = $this->stripUntrustedReconciliationProvenance($post);
        }

        $allowUntitledNonpublic = $allowUntitledNonpublic && $hasTrustedReconciliationProvenance;

        try {
            return $this->importPostWithDatabaseLockRetry($post, $allowUntitledNonpublic);
        } catch (Throwable $exception) {
            $this->recordFailure($post, $exception);

            throw $exception;
        } finally {
            $this->contentSanitizer->setMediaSourceBase(null);
        }
    }

    /**
     * Public WordPress REST is not allowed to self-assert private exporter
     * decisions. Strip the exceptional evidence before preview, import, or
     * failure persistence so a later stored-payload retry cannot promote it.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function stripUntrustedContentDecisionEvidence(array $post): array
    {
        $namespace = $post['_razbudise'] ?? null;

        if (! is_array($namespace) || array_is_list($namespace)) {
            unset($post['_razbudise']);

            return $post;
        }

        unset(
            $namespace['featured_image_fallback'],
            $namespace['blob_image_removal'],
            $namespace['frozen_content_decision_provenance'],
        );

        if ($namespace === []) {
            unset($post['_razbudise']);
        } else {
            $post['_razbudise'] = $namespace;
        }

        return $post;
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function stripUntrustedReconciliationProvenance(array $post): array
    {
        $namespace = $post['_razbudise'] ?? null;

        if (! is_array($namespace) || array_is_list($namespace)) {
            unset($post['_razbudise']);

            return $post;
        }

        unset(
            $namespace['unavailable_media'],
            $namespace['frozen_reconciliation_provenance'],
            $namespace['frozen_materialization_provenance'],
        );

        if ($namespace === []) {
            unset($post['_razbudise']);
        } else {
            $post['_razbudise'] = $namespace;
        }

        return $post;
    }

    /** @param array<string, mixed> $post */
    public function hasFrozenContentDecisionProvenance(array $post): bool
    {
        return $this->contentDecisionProvenance->isValid($post);
    }

    /** @param array<string, mixed> $post */
    public function hasFrozenReconciliationProvenance(array $post): bool
    {
        return $this->contentDecisionProvenance->hasValidReconciliationPayload($post);
    }

    /**
     * Return strictly shaped unavailable-media evidence. The reconciliation
     * signature covers both the source payload and this evidence, so changing
     * either invalidates the authorization. Its manifest SHA identifies the
     * separate unavailable-media inventory, not the reconciliation bundle.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>|null
     */
    public function trustedUnavailableMediaEvidence(array $post): ?array
    {
        if (! $this->hasFrozenReconciliationProvenance($post)) {
            return null;
        }

        $evidence = data_get($post, '_razbudise.unavailable_media');

        if (! is_array($evidence)
            || array_is_list($evidence)
            || ! $this->hasOnlyKeys($evidence, ['manifest_sha256', 'featured', 'inline'])
            || preg_match('/^[a-f0-9]{64}$/', (string) ($evidence['manifest_sha256'] ?? '')) !== 1) {
            return null;
        }

        $normalized = ['manifest_sha256' => $evidence['manifest_sha256']];
        $featured = $evidence['featured'] ?? null;

        if ($featured !== null) {
            if (! is_array($featured)
                || array_is_list($featured)
                || ! $this->hasExactKeys($featured, ['attachment_id', 'source_url', 'role', 'treatment'])
                || ! is_int($featured['attachment_id'])
                || $featured['attachment_id'] < 1
                || ! is_string($featured['source_url'])
                || trim($featured['source_url']) === ''
                || trim($featured['source_url']) !== $featured['source_url']
                || ! in_array($featured['role'], ['featured', 'featured_fallback'], true)
                || $featured['treatment'] !== 'frontend_placeholder') {
                return null;
            }

            $normalized['featured'] = $featured;
        }

        $inline = $evidence['inline'] ?? null;

        if ($inline !== null) {
            if (! is_array($inline) || ! array_is_list($inline) || $inline === []) {
                return null;
            }

            $seenSourceUrls = [];
            foreach ($inline as $entry) {
                if (! is_array($entry)
                    || array_is_list($entry)
                    || ! $this->hasExactKeys($entry, ['source_url', 'occurrences', 'role', 'treatment'])
                    || ! is_string($entry['source_url'])
                    || trim($entry['source_url']) === ''
                    || trim($entry['source_url']) !== $entry['source_url']
                    || ! is_int($entry['occurrences'])
                    || $entry['occurrences'] < 1
                    || $entry['role'] !== 'inline'
                    || $entry['treatment'] !== 'remove_element'
                    || isset($seenSourceUrls[$entry['source_url']])) {
                    return null;
                }

                $seenSourceUrls[$entry['source_url']] = true;
            }

            $normalized['inline'] = $inline;
        }

        return count($normalized) > 1 ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<int, string>  $keys
     */
    private function hasExactKeys(array $value, array $keys): bool
    {
        return count($value) === count($keys)
            && array_diff(array_keys($value), $keys) === [];
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<int, string>  $keys
     */
    private function hasOnlyKeys(array $value, array $keys): bool
    {
        return array_diff(array_keys($value), $keys) === [];
    }

    /**
     * Import one tag from a checksummed private source export.
     *
     * @param  array<string, mixed>  $term
     */
    public function importStoredTagPayload(array $term, ?string $targetSlug = null): Tag
    {
        try {
            $wordpressId = (int) ($term['id'] ?? 0);
            $name = $this->cleanText($term['name'] ?? null);
            $targetSlug ??= $this->safeSlug((string) ($term['slug'] ?? ''), $name);

            if ($wordpressId < 1
                || $name === ''
                || $targetSlug === ''
                || Str::slug($targetSlug) !== $targetSlug
                || strlen($targetSlug) > 255) {
                throw new \InvalidArgumentException('WordPress tag is missing an ID, name, or slug.');
            }

            $tag = Tag::query()->where('slug', $targetSlug)->first();
            $mappedTag = $this->importedTermTarget('tag', $wordpressId, Tag::class)
                ?? $this->mappedTarget(
                    'tag',
                    $wordpressId,
                    (string) ($term['slug'] ?? ''),
                    Tag::class,
                );

            if (! $tag instanceof Tag
                && $mappedTag instanceof Tag
                && ! WordpressImportRecord::query()
                    ->where('wordpress_type', 'tag')
                    ->where('wordpress_id', '!=', $wordpressId)
                    ->where('status', 'imported')
                    ->where('importable_type', Tag::class)
                    ->where('importable_id', $mappedTag->id)
                    ->exists()) {
                $tag = $mappedTag;
            }

            $tag ??= new Tag;
            $tag->forceFill([
                'name' => $name,
                'slug' => $targetSlug,
            ])->save();
            $this->recordFrozenTermInventory('tag', $term, $tag, "/tag/{$tag->slug}");

            return $tag;
        } catch (Throwable $exception) {
            $this->recordTermFailure('tag', $term, $exception);

            throw $exception;
        }
    }

    /**
     * Import one category from a checksummed private source export.
     *
     * @param  array<string, mixed>  $term
     */
    public function importStoredCategoryPayload(array $term): Category
    {
        try {
            $mappedCategory = $this->mappedTarget(
                'category',
                (int) ($term['id'] ?? 0),
                (string) ($term['slug'] ?? ''),
                Category::class,
            );

            if ($mappedCategory instanceof Category) {
                $this->recordFrozenTermInventory(
                    'category',
                    $term,
                    $mappedCategory,
                    "/category/{$mappedCategory->slug}",
                );

                return $mappedCategory;
            }

            $category = $this->resolveCategoryTerm($term);

            if (! $category instanceof Category) {
                throw new \InvalidArgumentException('WordPress category is missing an ID, name, or slug.');
            }

            $name = $this->cleanText($term['name'] ?? null);
            $category->forceFill([
                'name' => $name,
                'slug' => $this->safeSlug((string) ($term['slug'] ?? ''), $name),
                'description' => $this->cleanText($term['description'] ?? null) ?: null,
                'is_active' => true,
            ])->save();
            $this->recordFrozenTermInventory('category', $term, $category, "/category/{$category->slug}");

            return $category;
        } catch (Throwable $exception) {
            $this->recordTermFailure('category', $term, $exception);

            throw $exception;
        }
    }

    /**
     * Reconcile category parent relationships after every frozen category exists.
     *
     * @param  array<int, array<string, mixed>>  $categories
     */
    public function syncStoredCategoryParents(array $categories): void
    {
        foreach ($categories as $category) {
            $this->syncCategoryParent($category);
        }
    }

    /**
     * Import one exact author identity from a checksummed private source export.
     *
     * @param  array<string, mixed>  $sourceAuthor
     */
    public function importStoredAuthorPayload(array $sourceAuthor): Author
    {
        $wordpressId = (int) ($sourceAuthor['wordpress_id'] ?? 0);
        $name = $this->cleanText($sourceAuthor['display_name'] ?? null);
        $sourceSlug = (string) ($sourceAuthor['source_slug'] ?? '');
        $slug = $this->safeSlug($sourceSlug, $name);

        if ($wordpressId < 1 || $name === '' || $slug === '') {
            throw new \InvalidArgumentException('WordPress author is missing an ID, name, or slug.');
        }

        $author = $this->mappedTarget('author', $wordpressId, $sourceSlug, Author::class);

        if (! $author instanceof Author) {
            $author = Author::query()->where('slug', $slug)->first();
        }

        if ($author instanceof Author
            && WordpressImportRecord::query()
                ->where('wordpress_type', 'author')
                ->where('wordpress_id', '!=', $wordpressId)
                ->where('importable_type', Author::class)
                ->where('importable_id', $author->id)
                ->exists()) {
            throw new \RuntimeException("WordPress author {$wordpressId} conflicts with another imported author identity.");
        }

        $author ??= new Author;
        $author->forceFill([
            'name' => $name,
            'slug' => $slug,
            'job_title' => $author->job_title ?: 'Автор',
            'show_email' => false,
            'is_active' => true,
            'sort_order' => $author->exists ? $author->sort_order : 100,
        ])->save();
        $this->recordImportedAuthor($wordpressId, [
            'id' => $wordpressId,
            'slug' => $sourceSlug,
            'name' => $name,
            'link' => $sourceAuthor['old_url'] ?? null,
        ], $author);

        return $author;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function importPost(array $post, bool $allowUntitledNonpublic = false): Article
    {
        return DB::transaction(function () use ($post, $allowUntitledNonpublic): Article {
            $wordpressId = (int) ($post['id'] ?? 0);
            $sourceTitle = $this->cleanText(data_get($post, 'title.rendered'));
            $title = $this->materializedPostTitle($post, $allowUntitledNonpublic);

            if ($wordpressId <= 0 || $title === '') {
                throw new \InvalidArgumentException('WordPress post is missing an ID or title.');
            }

            $author = $this->resolveAuthor($post);
            $category = $this->resolveCategory($post);
            $featuredMedia = $this->featuredMediaEvidence($post);
            $trustedUnavailableMedia = $this->trustedUnavailableMediaEvidence($post);
            $trustedUnavailableFeaturedMedia = $this->trustedUnavailableFeaturedMediaEvidence($post);
            $trustedUnavailableInlineMedia = is_array($trustedUnavailableMedia['inline'] ?? null)
                ? $trustedUnavailableMedia['inline']
                : [];
            $featuredImage = $this->resolveFeaturedImage(
                $post,
                $trustedUnavailableFeaturedMedia !== null,
            );
            $usesFeaturedImageFallback = $this->usesFeaturedImageFallback($post);
            $sourceStatus = (string) ($post['status'] ?? 'draft');
            $status = $this->mapStatus($sourceStatus);
            $isPublicSource = $sourceStatus === 'publish';
            $sourceDate = $this->parseWordpressDate($post['date_gmt'] ?? null);
            $publishedAt = $status === 'published' ? $sourceDate : null;
            $scheduledAt = $status === 'scheduled' ? $sourceDate : null;
            $modifiedAt = $this->parseWordpressDate($post['modified_gmt'] ?? null) ?? $sourceDate;
            $sourceBody = data_get($post, 'content.rendered');
            $blobImageRemovalEvidence = $this->trustedBlobImageRemovalEvidence($post);
            $removesPersistedBlobImages = $blobImageRemovalEvidence !== null;
            $contentPreview = $this->contentSanitizer->preview(
                $sourceBody,
                $removesPersistedBlobImages,
                $trustedUnavailableInlineMedia,
            );
            $content = $this->contentSanitizer->clean(
                $sourceBody,
                $removesPersistedBlobImages,
                $trustedUnavailableInlineMedia,
            );
            $warnings = array_values(array_unique($content['warnings']));
            $normalizationCounts = $content['normalization_counts'];

            if ($usesFeaturedImageFallback) {
                $warnings[] = 'trusted_featured_image_fallback';
                $normalizationCounts['trusted_featured_image_fallback'] = 1;
            }

            if ($trustedUnavailableFeaturedMedia !== null) {
                $warnings[] = 'trusted_unavailable_featured_media';
                $normalizationCounts['trusted_unavailable_featured_media'] = 1;
            }

            if ($featuredMedia !== null
                && $trustedUnavailableFeaturedMedia === null
                && ! $this->storedMediaExists($featuredImage)) {
                $warnings[] = 'featured_image_download_failed';
                $warnings[] = 'manual_review';
            }

            if ($sourceTitle === '' && $allowUntitledNonpublic) {
                $warnings[] = self::UNTITLED_NONPUBLIC_WARNING;
            }

            $warnings = array_values(array_unique($warnings));

            $sourcePayload = $post;
            if ($blobImageRemovalEvidence !== null
                && ! is_array(data_get($sourcePayload, '_razbudise.blob_image_removal'))) {
                $sourcePayload['_razbudise']['blob_image_removal'] = $blobImageRemovalEvidence;
            }
            $sourcePayload['_razbudise']['normalization_counts'] = $normalizationCounts;
            $bodyHtml = $this->rewriteInternalLinks($content['html']);
            $sourcePayload['_razbudise']['content_pipeline'] = [
                'version' => self::CONTENT_PIPELINE_VERSION,
                'source_body_sha256' => hash('sha256', (string) $sourceBody),
                'preview_body_sha256' => hash('sha256', (string) ($contentPreview['html'] ?? '')),
                'persisted_body_sha256' => hash('sha256', (string) $bodyHtml),
            ];

            if ($this->hasFrozenReconciliationProvenance($sourcePayload)) {
                $sourcePayload = $this->contentDecisionProvenance->addMaterialization(
                    $sourcePayload,
                    (string) $bodyHtml,
                    $warnings,
                    $normalizationCounts,
                );
            }

            $article = Article::query()->updateOrCreate(
                ['old_wordpress_id' => $wordpressId],
                [
                    'title' => $title,
                    'slug' => $this->uniqueSlug((string) ($post['slug'] ?? ''), $wordpressId, $sourceTitle),
                    'excerpt' => $this->cleanExcerpt(data_get($post, 'excerpt.rendered')),
                    'body_html' => $bodyHtml,
                    'status' => $status,
                    'type' => 'editorial',
                    'author_id' => $author?->id,
                    'category_id' => $category?->id,
                    'featured_image_id' => $featuredImage?->id,
                    'published_at' => $publishedAt,
                    'scheduled_at' => $scheduledAt,
                    'is_sponsored' => false,
                    'is_ai_generated' => false,
                    'is_ai_assisted' => false,
                    'old_url' => $post['link'] ?? null,
                    'created_at' => $sourceDate,
                ],
            );

            if ($modifiedAt) {
                $article->forceFill(['updated_at' => $modifiedAt])->save();
            }

            $this->syncCategories($article, $post);
            $this->syncTags($article, $post);
            $this->syncSeoMetadata($article, $post);

            WordpressImportRecord::query()->updateOrCreate(
                ['wordpress_id' => $wordpressId, 'wordpress_type' => 'post'],
                [
                    'importable_type' => Article::class,
                    'importable_id' => $article->id,
                    'old_url' => $post['link'] ?? null,
                    'new_url' => $isPublicSource ? "/{$article->slug}" : null,
                    'status' => 'imported',
                    'source_payload' => $sourcePayload,
                    'warnings' => $warnings,
                    'error_message' => null,
                    'imported_at' => now(),
                ],
            );

            if ($isPublicSource) {
                $this->createRedirectForImport($post['link'] ?? null, "/{$article->slug}");
            }

            return $article;
        });
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function resolveAuthor(array $post): ?Author
    {
        $authorData = data_get($post, '_embedded.author.0');
        $wordpressAuthorId = (int) ($authorData['id'] ?? $post['author'] ?? 0);
        $authorSlug = is_array($authorData) ? (string) ($authorData['slug'] ?? '') : '';

        $mappedAuthor = $this->mappedTarget('author', $wordpressAuthorId, $authorSlug, Author::class);

        if ($mappedAuthor instanceof Author) {
            if ($wordpressAuthorId > 0) {
                WordpressImportRecord::query()->updateOrCreate(
                    ['wordpress_id' => $wordpressAuthorId, 'wordpress_type' => 'author'],
                    [
                        'importable_type' => Author::class,
                        'importable_id' => $mappedAuthor->id,
                        'old_url' => is_array($authorData) ? ($authorData['link'] ?? null) : null,
                        'new_url' => "/profil/{$mappedAuthor->slug}",
                        'status' => 'imported',
                        'source_payload' => is_array($authorData) ? $authorData : [],
                        'warnings' => [],
                        'error_message' => null,
                        'imported_at' => now(),
                    ],
                );
            }

            return $mappedAuthor;
        }

        if ($wordpressAuthorId > 0) {
            $existingRecord = WordpressImportRecord::query()
                ->where('wordpress_id', $wordpressAuthorId)
                ->where('wordpress_type', 'author')
                ->where('importable_type', Author::class)
                ->first();

            if ($existingRecord?->importable instanceof Author) {
                return $existingRecord->importable;
            }
        }

        if (! is_array($authorData)) {
            $authorData = [];
        }

        $existingAuthor = $this->existingAuthorFromWordpressData($authorData);

        if ($existingAuthor) {
            $this->recordImportedAuthor($wordpressAuthorId, $authorData, $existingAuthor);

            return $existingAuthor;
        }

        $name = $this->cleanText($authorData['name'] ?? null) ?: ($wordpressAuthorId > 0 ? "WordPress Author {$wordpressAuthorId}" : '');

        if ($name === '') {
            return null;
        }

        $author = Author::query()->updateOrCreate(
            ['slug' => $this->safeSlug((string) ($authorData['slug'] ?? ''), $name)],
            [
                'name' => $name,
                'short_bio' => $this->cleanText($authorData['description'] ?? null) ?: null,
                'job_title' => 'Автор',
                'show_email' => false,
                'is_active' => true,
                'sort_order' => 100,
            ],
        );

        if ($wordpressAuthorId > 0) {
            $this->recordImportedAuthor($wordpressAuthorId, $authorData, $author);
        }

        return $author;
    }

    /**
     * @param  array<string, mixed>  $authorData
     */
    private function existingAuthorFromWordpressData(array $authorData): ?Author
    {
        $name = $this->cleanText($authorData['name'] ?? null);
        $slug = $this->safeSlug((string) ($authorData['slug'] ?? ''), $name);
        $email = trim(strtolower((string) ($authorData['email'] ?? '')));

        if ($slug !== '') {
            $author = Author::query()->where('slug', $slug)->first();

            if ($author) {
                return $author;
            }
        }

        if ($email !== '') {
            $author = Author::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($author) {
                return $author;
            }
        }

        if ($name !== '') {
            return Author::query()->where('name', $name)->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $authorData
     */
    private function recordImportedAuthor(int $wordpressAuthorId, array $authorData, Author $author): void
    {
        if ($wordpressAuthorId <= 0) {
            return;
        }

        WordpressImportRecord::query()->updateOrCreate(
            ['wordpress_id' => $wordpressAuthorId, 'wordpress_type' => 'author'],
            [
                'importable_type' => Author::class,
                'importable_id' => $author->id,
                'old_url' => $authorData['link'] ?? null,
                'new_url' => "/profil/{$author->slug}",
                'status' => 'imported',
                'source_payload' => $authorData,
                'warnings' => [],
                'error_message' => null,
                'imported_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function resolveCategory(array $post): ?Category
    {
        $categoryData = $this->terms($post)
            ->first(fn (array $term) => ($term['taxonomy'] ?? null) === 'category');

        if (! $categoryData) {
            return Category::query()->where('slug', 'vesti')->first();
        }

        return $this->resolveCategoryTerm($categoryData);
    }

    /**
     * @param  array<string, mixed>  $categoryData
     */
    private function resolveCategoryTerm(array $categoryData): ?Category
    {
        $mappedCategory = $this->mappedTarget(
            'category',
            (int) ($categoryData['id'] ?? 0),
            (string) ($categoryData['slug'] ?? ''),
            Category::class,
        );

        if ($mappedCategory instanceof Category) {
            $this->recordImportedTerm('category', $categoryData, $mappedCategory, "/category/{$mappedCategory->slug}");

            return $mappedCategory;
        }

        $name = $this->cleanText($categoryData['name'] ?? null);

        if ($name === '') {
            return null;
        }

        $category = Category::query()->updateOrCreate(
            ['slug' => $this->safeSlug((string) ($categoryData['slug'] ?? ''), $name)],
            [
                'name' => $name,
                'description' => $this->cleanText($categoryData['description'] ?? null) ?: null,
                'is_active' => true,
                'sort_order' => 500,
            ],
        );

        $this->recordImportedTerm('category', $categoryData, $category, "/category/{$category->slug}");

        return $category;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function syncCategories(Article $article, array $post): void
    {
        $categoryIds = $this->terms($post)
            ->filter(fn (array $term) => ($term['taxonomy'] ?? null) === 'category')
            ->map(function (array $term): ?int {
                return $this->resolveCategoryTerm($term)?->id;
            })
            ->filter()
            ->unique()
            ->values();

        if ($categoryIds->isEmpty() && $article->category_id) {
            $categoryIds = collect([$article->category_id]);
        }

        $article->categories()->sync($categoryIds);
    }

    /**
     * @param  array<string, mixed>  $categoryData
     */
    private function syncCategoryParent(array $categoryData): void
    {
        $wordpressId = (int) ($categoryData['id'] ?? 0);
        $parentWordpressId = (int) ($categoryData['parent'] ?? 0);

        if ($wordpressId <= 0) {
            return;
        }

        $categoryRecord = WordpressImportRecord::query()
            ->where('wordpress_id', $wordpressId)
            ->where('wordpress_type', 'category')
            ->first();
        if (! $categoryRecord?->importable instanceof Category) {
            throw new \RuntimeException("WordPress category {$wordpressId} is missing its imported mapping.");
        }

        $parent = null;

        if ($parentWordpressId > 0) {
            $parentRecord = WordpressImportRecord::query()
                ->where('wordpress_id', $parentWordpressId)
                ->where('wordpress_type', 'category')
                ->first();

            if (! $parentRecord?->importable instanceof Category) {
                throw new \RuntimeException("WordPress category {$wordpressId} parent mapping is missing.");
            }

            $parent = $parentRecord->importable->id;
        }

        $categoryRecord->importable->forceFill([
            'parent_id' => $parent,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function resolveFeaturedImage(
        array $post,
        bool $useTrustedUnavailablePlaceholder = false,
    ): ?MediaAsset {
        $media = $this->featuredMediaEvidence($post);

        if (! is_array($media) || empty($media['source_url'])) {
            return null;
        }

        if ($useTrustedUnavailablePlaceholder) {
            return null;
        }

        $mediaSource = $this->usesFeaturedImageFallback($post)
            ? 'wordpress_featured_fallback'
            : 'wordpress_rest';
        $asset = $this->downloadWordpressMedia($media, $mediaSource);

        if ($asset instanceof MediaAsset) {
            return $this->recordFeaturedImageUsage($asset, $media, $mediaSource);
        }

        $asset = MediaAsset::query()->updateOrCreate(
            ['url' => $media['source_url']],
            [
                'disk' => 'external',
                'path' => parse_url((string) $media['source_url'], PHP_URL_PATH) ?: (string) $media['source_url'],
                'mime_type' => $media['mime_type'] ?? null,
                'alt_text' => $this->cleanText($media['alt_text'] ?? null) ?: null,
                'caption' => $this->cleanExcerpt(data_get($media, 'caption.rendered')),
                'width' => data_get($media, 'media_details.width'),
                'height' => data_get($media, 'media_details.height'),
                'size_bytes' => data_get($media, 'media_details.filesize'),
                'metadata' => [
                    'wordpress_id' => $media['id'] ?? null,
                    'source' => $mediaSource,
                    'source_url' => $media['source_url'],
                ],
            ],
        );

        return $this->recordFeaturedImageUsage($asset, $media, $mediaSource);
    }

    /**
     * Return featured-media evidence only when the signed unavailable-media
     * authorization names the exact attachment and source URL being imported.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>|null
     */
    public function trustedUnavailableFeaturedMediaEvidence(array $post): ?array
    {
        $trustedUnavailableMedia = $this->trustedUnavailableMediaEvidence($post);
        $evidence = $trustedUnavailableMedia['featured'] ?? null;
        $media = $this->featuredMediaEvidence($post);
        $expectedRole = (int) ($post['featured_media'] ?? 0) > 0
            ? 'featured'
            : ($this->usesFeaturedImageFallback($post) ? 'featured_fallback' : null);

        if (! is_array($evidence)
            || ! is_array($media)
            || $expectedRole === null
            || $evidence['role'] !== $expectedRole
            || (int) ($media['id'] ?? 0) !== $evidence['attachment_id']
            || ! is_string($media['source_url'] ?? null)
            || ! hash_equals($media['source_url'], $evidence['source_url'])) {
            return null;
        }

        return $evidence;
    }

    /** @param array<string, mixed> $media */
    private function recordFeaturedImageUsage(
        MediaAsset $asset,
        array $media,
        string $mediaSource,
    ): MediaAsset {
        $metadata = is_array($asset->metadata) ? $asset->metadata : [];
        $sources = collect(is_array($metadata['sources'] ?? null) ? $metadata['sources'] : [])
            ->filter(fn (mixed $source): bool => is_string($source) && trim($source) !== '')
            ->map(fn (string $source): string => trim($source));
        if (is_string($metadata['source'] ?? null) && trim($metadata['source']) !== '') {
            $sources->push(trim($metadata['source']));
        }
        $sources->push($mediaSource);

        $roles = collect(is_array($metadata['roles'] ?? null) ? $metadata['roles'] : [])
            ->filter(fn (mixed $role): bool => is_string($role) && trim($role) !== '')
            ->map(fn (string $role): string => trim($role))
            ->push('featured');
        if ($mediaSource === 'wordpress_featured_fallback') {
            $roles->push('featured_fallback');
        }

        $usageMetadata = array_replace($metadata, [
            'wordpress_id' => (int) ($media['id'] ?? 0) ?: null,
            'source_identity' => 'wordpress_attachment',
            'sources' => $sources->unique()->sort()->values()->all(),
            'roles' => $roles->unique()->sort()->values()->all(),
            'source_url' => (string) ($media['source_url'] ?? ''),
        ]);

        if ($usageMetadata !== $metadata) {
            $asset->forceFill(['metadata' => $usageMetadata])->save();
        }

        return $asset->refresh();
    }

    /**
     * Return the normal WordPress featured-media evidence or the frozen,
     * explicitly approved inline-attachment fallback.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>|null
     */
    private function featuredMediaEvidence(array $post): ?array
    {
        $featuredMediaId = (int) ($post['featured_media'] ?? 0);
        $embeddedMedia = data_get($post, '_embedded.wp:featuredmedia.0');

        if ($featuredMediaId > 0) {
            return is_array($embeddedMedia) && (int) ($embeddedMedia['id'] ?? 0) === $featuredMediaId
                ? $embeddedMedia
                : null;
        }

        $fallback = data_get($post, '_razbudise.featured_image_fallback');

        // Keep the existing public REST importer contract: some WordPress
        // fixtures/providers expose embedded media without the scalar field.
        // Frozen reconciliation validates the scalar/embedded pair strictly.
        if (! is_array($fallback)) {
            return is_array($embeddedMedia) && trim((string) ($embeddedMedia['source_url'] ?? '')) !== ''
                ? $embeddedMedia
                : null;
        }

        $media = is_array($fallback) ? ($fallback['media'] ?? null) : null;
        $postId = (int) ($post['id'] ?? 0);
        $attachmentId = is_array($fallback) ? (int) ($fallback['attachment_id'] ?? 0) : 0;
        $sourceBody = (string) data_get($post, 'content.rendered', '');

        if (! is_array($fallback)
            || ! is_array($media)
            || ($fallback['strategy'] ?? null) !== 'explicit_inline_attachment'
            || (int) ($fallback['post_id'] ?? 0) !== $postId
            || $postId < 1
            || $attachmentId < 1
            || ! $this->isVersionedFeaturedImageFallback(
                $postId,
                $attachmentId,
                $fallback['decision_sha256'] ?? null,
            )
            || (int) ($media['id'] ?? 0) !== $attachmentId
            || (int) ($media['post'] ?? 0) !== $postId
            || ! str_starts_with(strtolower((string) ($media['mime_type'] ?? '')), 'image/')
            || trim((string) ($media['source_url'] ?? '')) === ''
            || ! $this->bodyReferencesAttachmentImage(
                $sourceBody,
                $attachmentId,
                (string) $media['source_url'],
            )) {
            return null;
        }

        return $media;
    }

    private function isVersionedFeaturedImageFallback(
        int $postId,
        int $attachmentId,
        mixed $decisionSha256,
    ): bool {
        if (! is_string($decisionSha256)
            || preg_match('/^[a-f0-9]{64}$/', $decisionSha256) !== 1) {
            return false;
        }

        $decisions = $this->versionedContentMigrationDecisions($decisionSha256);

        return is_array($decisions)
            && ($decisions['featured_image_fallbacks'][$postId] ?? null) === $attachmentId;
    }

    /**
     * Return the validated, versioned decision inventory for completion
     * evidence. Public REST payloads still cannot activate these decisions.
     *
     * @return array{
     *     sha256: string,
     *     featured_image_fallbacks: array<int, int>,
     *     blob_image_removals: array<int, array{raw_post_content_sha256: string, expected_blob_image_count: int}>
     * }|null
     */
    public function contentMigrationDecisions(): ?array
    {
        return $this->versionedContentMigrationDecisions(null);
    }

    /** @param array<string, mixed> $post */
    private function trustedBlobImageRemovalEvidence(array $post): ?array
    {
        $evidence = data_get($post, '_razbudise.blob_image_removal');

        if (! is_array($evidence) || array_is_list($evidence)) {
            return null;
        }

        $postId = (int) ($post['id'] ?? 0);
        $sourceBody = (string) data_get($post, 'content.rendered', '');
        $decisionSha256 = $evidence['decision_sha256'] ?? null;
        $decisions = $this->versionedContentMigrationDecisions($decisionSha256);
        $decision = is_array($decisions)
            ? ($decisions['blob_image_removals'][$postId] ?? null)
            : null;
        $blobImageCount = $this->persistedBlobImageCount($sourceBody);

        if (! is_array($decision)
            || ($evidence['strategy'] ?? null) !== 'remove_nonpersistent_blob_images'
            || (int) ($evidence['post_id'] ?? 0) !== $postId
            || ! is_string($decisionSha256)
            || ($evidence['raw_post_content_sha256'] ?? null) !== ($decision['raw_post_content_sha256'] ?? null)
            || (int) ($decision['expected_blob_image_count'] ?? 0) !== $blobImageCount
            || (int) ($evidence['expected_blob_image_count'] ?? 0) !== $blobImageCount
            || $blobImageCount < 1) {
            return null;
        }

        return $evidence;
    }

    /**
     * @return array{
     *     sha256: string,
     *     featured_image_fallbacks: array<int, int>,
     *     blob_image_removals: array<int, array{raw_post_content_sha256: string, expected_blob_image_count: int}>
     * }|null
     */
    private function versionedContentMigrationDecisions(mixed $decisionSha256): ?array
    {
        if ($decisionSha256 !== null
            && (! is_string($decisionSha256)
                || preg_match('/^[a-f0-9]{64}$/', $decisionSha256) !== 1)) {
            return null;
        }

        $configuredPath = config('services.wordpress.content_migration_decisions_path');
        $decisionPath = is_string($configuredPath) && trim($configuredPath) !== ''
            ? trim($configuredPath)
            : dirname(base_path()).'/deployment/wordpress/content-migration-decisions.json';
        $json = @file_get_contents($decisionPath);

        $artifactSha256 = is_string($json) ? hash('sha256', $json) : null;

        if (! is_string($json)
            || ! is_string($artifactSha256)
            || (is_string($decisionSha256) && ! hash_equals($artifactSha256, $decisionSha256))) {
            return null;
        }

        try {
            $decisions = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decisions)
            || array_is_list($decisions)
            || ($decisions['schema_version'] ?? null) !== 1
            || ! is_array($decisions['featured_image_fallbacks'] ?? null)
            || array_is_list($decisions['featured_image_fallbacks'])
            || ! is_array($decisions['blob_image_removals'] ?? null)
            || array_is_list($decisions['blob_image_removals'])) {
            return null;
        }

        $featuredImageFallbacks = [];
        foreach ($decisions['featured_image_fallbacks'] as $postId => $attachmentId) {
            if (! ctype_digit((string) $postId)
                || (int) $postId < 1
                || ! is_int($attachmentId)
                || $attachmentId < 1) {
                return null;
            }

            $featuredImageFallbacks[(int) $postId] = $attachmentId;
        }

        $blobImageRemovals = [];
        foreach ($decisions['blob_image_removals'] as $postId => $decision) {
            if (! ctype_digit((string) $postId)
                || (int) $postId < 1
                || ! is_array($decision)
                || array_is_list($decision)
                || preg_match('/^[a-f0-9]{64}$/', (string) ($decision['raw_post_content_sha256'] ?? '')) !== 1
                || ! is_int($decision['expected_blob_image_count'] ?? null)
                || $decision['expected_blob_image_count'] < 1) {
                return null;
            }

            $blobImageRemovals[(int) $postId] = [
                'raw_post_content_sha256' => $decision['raw_post_content_sha256'],
                'expected_blob_image_count' => $decision['expected_blob_image_count'],
            ];
        }

        return [
            'sha256' => $artifactSha256,
            'featured_image_fallbacks' => $featuredImageFallbacks,
            'blob_image_removals' => $blobImageRemovals,
        ];
    }

    private function persistedBlobImageCount(string $html): int
    {
        if (stripos($html, 'blob:') === false || stripos($html, '<img') === false) {
            return 0;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            if (! $document->loadHTML(
                '<?xml encoding="UTF-8"><div>'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            )) {
                return 0;
            }

            $count = 0;
            foreach ($document->getElementsByTagName('img') as $image) {
                if ($image instanceof DOMElement
                    && str_starts_with(strtolower(trim($image->getAttribute('src'))), 'blob:')) {
                    $count++;
                }
            }

            return $count;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    private function bodyReferencesAttachmentImage(
        string $html,
        int $attachmentId,
        string $sourceUrl,
    ): bool {
        if ($attachmentId < 1 || trim($sourceUrl) === '' || stripos($html, '<img') === false) {
            return false;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            if (! $document->loadHTML(
                '<?xml encoding="UTF-8"><div>'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            )) {
                return false;
            }

            $expectedClass = 'wp-image-'.$attachmentId;
            $sourceUrl = html_entity_decode(trim($sourceUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            foreach ($document->getElementsByTagName('img') as $image) {
                if (! $image instanceof DOMElement) {
                    continue;
                }

                $classes = preg_split('/\\s+/u', trim($image->getAttribute('class'))) ?: [];
                if (! in_array($expectedClass, $classes, true)) {
                    continue;
                }

                $candidates = [trim($image->getAttribute('src'))];
                foreach (explode(',', $image->getAttribute('srcset')) as $candidate) {
                    $parts = preg_split('/\\s+/u', trim($candidate), 2) ?: [];
                    $candidates[] = (string) ($parts[0] ?? '');
                }

                foreach ($candidates as $candidate) {
                    if (html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8') === $sourceUrl) {
                        return true;
                    }
                }
            }

            return false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    /** @param array<string, mixed> $post */
    private function usesFeaturedImageFallback(array $post): bool
    {
        return (int) ($post['featured_media'] ?? 0) === 0
            && is_array(data_get($post, '_razbudise.featured_image_fallback'))
            && $this->featuredMediaEvidence($post) !== null;
    }

    private function storedMediaExists(?MediaAsset $asset): bool
    {
        if (! $asset instanceof MediaAsset
            || ! is_string($asset->disk)
            || $asset->disk === ''
            || $asset->disk === 'external'
            || ! is_string($asset->path)
            || $asset->path === '') {
            return false;
        }

        try {
            return Storage::disk($asset->disk)->exists($asset->path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function downloadWordpressMedia(array $media, string $source = 'wordpress_rest'): ?MediaAsset
    {
        return $this->contentSanitizer->downloadMedia((string) ($media['source_url'] ?? ''), [
            'wordpress_id' => (int) ($media['id'] ?? 0) ?: null,
            'source' => $source,
            'mime_type' => $media['mime_type'] ?? null,
            'alt_text' => $this->cleanText($media['alt_text'] ?? null) ?: null,
            'caption' => $this->cleanExcerpt(data_get($media, 'caption.rendered')),
            'width' => data_get($media, 'media_details.width'),
            'height' => data_get($media, 'media_details.height'),
            'size_bytes' => data_get($media, 'media_details.filesize'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function syncTags(Article $article, array $post): void
    {
        $tagIds = $this->terms($post)
            ->filter(fn (array $term) => ($term['taxonomy'] ?? null) === 'post_tag')
            ->map(function (array $term): ?int {
                return $this->resolveTagTerm($term)?->id;
            })
            ->filter()
            ->values();

        $article->tags()->sync($tagIds);
    }

    /**
     * @param  array<string, mixed>  $term
     */
    private function resolveTagTerm(array $term): ?Tag
    {
        $wordpressId = (int) ($term['id'] ?? 0);
        $recordedTag = $this->importedTermTarget('tag', $wordpressId, Tag::class);

        if ($recordedTag instanceof Tag) {
            $this->recordImportedTerm('tag', $term, $recordedTag, "/tag/{$recordedTag->slug}");

            return $recordedTag;
        }

        $mappedTag = $this->mappedTarget(
            'tag',
            $wordpressId,
            (string) ($term['slug'] ?? ''),
            Tag::class,
        );

        if ($mappedTag instanceof Tag) {
            $this->recordImportedTerm('tag', $term, $mappedTag, "/tag/{$mappedTag->slug}");

            return $mappedTag;
        }

        $name = $this->cleanText($term['name'] ?? null);

        if ($name === '') {
            return null;
        }

        $tag = Tag::query()->updateOrCreate(
            ['slug' => $this->safeSlug((string) ($term['slug'] ?? ''), $name)],
            ['name' => $name],
        );

        $this->recordImportedTerm('tag', $term, $tag, "/tag/{$tag->slug}");

        return $tag;
    }

    /**
     * @param  array<string, mixed>  $term
     */
    private function recordImportedTerm(string $wordpressType, array $term, Category|Tag $target, string $newUrl): void
    {
        $wordpressId = (int) ($term['id'] ?? 0);

        if ($wordpressId <= 0) {
            return;
        }

        $existingRecord = WordpressImportRecord::query()
            ->where('wordpress_id', $wordpressId)
            ->where('wordpress_type', $wordpressType)
            ->first();
        $preserveFrozenEvidence = data_get(
            $existingRecord?->source_payload,
            '_razbudise.frozen_taxonomy_evidence_version',
        ) === self::FROZEN_TAXONOMY_EVIDENCE_VERSION;

        WordpressImportRecord::query()->updateOrCreate(
            ['wordpress_id' => $wordpressId, 'wordpress_type' => $wordpressType],
            [
                'importable_type' => $target::class,
                'importable_id' => $target->id,
                'old_url' => $preserveFrozenEvidence ? $existingRecord?->old_url : ($term['link'] ?? null),
                'new_url' => $newUrl,
                'status' => 'imported',
                'source_payload' => $preserveFrozenEvidence ? $existingRecord?->source_payload : $term,
                'warnings' => [],
                'error_message' => null,
                'imported_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $term
     */
    private function recordFrozenTermInventory(
        string $wordpressType,
        array $term,
        Category|Tag $target,
        string $newUrl,
    ): void {
        $sourcePayload = $term;
        $sourcePayload['_razbudise']['frozen_taxonomy_evidence_version'] = self::FROZEN_TAXONOMY_EVIDENCE_VERSION;

        WordpressImportRecord::query()->updateOrCreate(
            [
                'wordpress_id' => (int) ($term['id'] ?? 0),
                'wordpress_type' => $wordpressType,
            ],
            [
                'importable_type' => $target::class,
                'importable_id' => $target->id,
                'old_url' => $term['link'] ?? null,
                'new_url' => $newUrl,
                'status' => 'imported',
                'source_payload' => $sourcePayload,
                'warnings' => [],
                'error_message' => null,
                'imported_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $term
     */
    private function recordTermFailure(string $wordpressType, array $term, Throwable $exception): void
    {
        $wordpressId = (int) ($term['id'] ?? 0);

        if ($wordpressId <= 0) {
            return;
        }

        WordpressImportRecord::query()->updateOrCreate(
            ['wordpress_id' => $wordpressId, 'wordpress_type' => $wordpressType],
            [
                'old_url' => $term['link'] ?? null,
                'status' => 'failed',
                'source_payload' => $term,
                'warnings' => [],
                'error_message' => $exception->getMessage(),
                'imported_at' => null,
            ],
        );
    }

    private function importedTermTarget(string $wordpressType, int $wordpressId, string $targetClass): ?object
    {
        if ($wordpressId < 1) {
            return null;
        }

        $record = WordpressImportRecord::query()
            ->where('wordpress_type', $wordpressType)
            ->where('wordpress_id', $wordpressId)
            ->where('status', 'imported')
            ->where('importable_type', $targetClass)
            ->first(['importable_id']);

        return $record?->importable_id
            ? $targetClass::query()->find($record->importable_id)
            : null;
    }

    private function mappedTarget(string $sourceType, int $wordpressId, string $sourceSlug, string $targetClass): ?object
    {
        $sourceSlug = trim($sourceSlug);

        if ($wordpressId <= 0 && $sourceSlug === '') {
            return null;
        }

        $mapping = WordpressImportMapping::query()
            ->where('source_type', $sourceType)
            ->where('target_type', $targetClass)
            ->where(function ($query) use ($wordpressId, $sourceSlug): void {
                if ($wordpressId > 0) {
                    $query->where('wordpress_id', $wordpressId);
                }

                if ($sourceSlug !== '') {
                    $method = $wordpressId > 0 ? 'orWhere' : 'where';
                    $query->{$method}('source_slug', $sourceSlug);
                }
            })
            ->first();

        return $mapping?->target;
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function dryRunPostSummary(array $post): array
    {
        $wordpressId = (int) ($post['id'] ?? 0);
        $authorData = data_get($post, '_embedded.author.0');
        $categoryData = $this->terms($post)
            ->first(fn (array $term) => ($term['taxonomy'] ?? null) === 'category');
        $featuredMedia = $this->featuredMediaEvidence($post);
        $featuredImageUrl = is_array($featuredMedia) ? ($featuredMedia['source_url'] ?? null) : null;
        $usesFeaturedImageFallback = $this->usesFeaturedImageFallback($post);
        $contentPreview = $this->contentPreviewForPost($post);

        return [
            'wordpress_id' => $wordpressId,
            'title' => $this->cleanText(data_get($post, 'title.rendered')),
            'slug' => (string) ($post['slug'] ?? ''),
            'source_status' => (string) ($post['status'] ?? 'draft'),
            'status' => $this->mapStatus((string) ($post['status'] ?? 'draft')),
            'source_published_at' => $this->parseWordpressDate($post['date_gmt'] ?? null)?->toIso8601String(),
            'source_modified_at' => $this->parseWordpressDate($post['modified_gmt'] ?? null)?->toIso8601String(),
            'old_url' => $post['link'] ?? null,
            'author' => $this->dryRunAuthorSummary(
                is_array($authorData) ? $authorData : [],
                (int) (is_array($authorData) ? ($authorData['id'] ?? $post['author'] ?? 0) : ($post['author'] ?? 0)),
            ),
            'category' => $categoryData ? $this->dryRunTargetSummary(
                'category',
                (int) ($categoryData['id'] ?? 0),
                (string) ($categoryData['slug'] ?? ''),
                $this->cleanText($categoryData['name'] ?? null),
                Category::class,
            ) : [
                'status' => 'fallback',
                'source_id' => null,
                'source_slug' => null,
                'source_name' => null,
                'target_slug' => Category::query()->where('slug', 'vesti')->value('slug'),
            ],
            'tags' => $this->terms($post)
                ->filter(fn (array $term) => ($term['taxonomy'] ?? null) === 'post_tag')
                ->map(fn (array $term): array => $this->dryRunTargetSummary(
                    'tag',
                    (int) ($term['id'] ?? 0),
                    (string) ($term['slug'] ?? ''),
                    $this->cleanText($term['name'] ?? null),
                    Tag::class,
                ))
                ->values()
                ->all(),
            'featured_image' => [
                'status' => $featuredImageUrl ? ($usesFeaturedImageFallback ? 'fallback' : 'present') : 'missing',
                'wordpress_id' => is_array($featuredMedia) ? ($featuredMedia['id'] ?? null) : null,
                'source_url' => $featuredImageUrl ?: null,
            ],
            'warnings' => $featuredImageUrl
                ? ($usesFeaturedImageFallback ? ['trusted_featured_image_fallback'] : [])
                : ['missing_featured_image'],
            'content_preview' => $contentPreview,
        ];
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function dryRunExportRow(array $post): array
    {
        $author = is_array($post['author'] ?? null) ? $post['author'] : [];
        $category = is_array($post['category'] ?? null) ? $post['category'] : [];
        $tags = collect($post['tags'] ?? [])->filter(fn (mixed $tag): bool => is_array($tag));
        $featuredImage = is_array($post['featured_image'] ?? null) ? $post['featured_image'] : [];

        return [
            'wordpress_id' => $post['wordpress_id'] ?? null,
            'title' => $post['title'] ?? '',
            'slug' => $post['slug'] ?? '',
            'status' => $post['status'] ?? '',
            'old_url' => $post['old_url'] ?? null,
            'author_status' => $author['status'] ?? 'missing',
            'author_source_id' => $author['source_id'] ?? null,
            'author_source_slug' => $author['source_slug'] ?? null,
            'author_source_name' => $author['source_name'] ?? null,
            'author_target_slug' => $author['target_slug'] ?? null,
            'author_target_name' => $author['target_name'] ?? null,
            'category_status' => $category['status'] ?? 'missing',
            'category_source_id' => $category['source_id'] ?? null,
            'category_source_slug' => $category['source_slug'] ?? null,
            'category_source_name' => $category['source_name'] ?? null,
            'category_target_slug' => $category['target_slug'] ?? null,
            'category_target_name' => $category['target_name'] ?? null,
            'tag_statuses' => $this->joinDryRunValues($tags, 'status'),
            'tag_source_slugs' => $this->joinDryRunValues($tags, 'source_slug'),
            'tag_target_slugs' => $this->joinDryRunValues($tags, 'target_slug'),
            'featured_image_status' => $featuredImage['status'] ?? 'missing',
            'featured_image_url' => $featuredImage['source_url'] ?? null,
            'warnings' => implode('|', $post['warnings'] ?? []),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $posts
     * @return array<string, mixed>
     */
    private function dryRunSummary(Collection $posts): array
    {
        return [
            'posts' => $posts->count(),
            'mapped_authors' => $posts->where('author.status', 'mapped')->count(),
            'mapped_categories' => $posts->where('category.status', 'mapped')->count(),
            'mapped_tags' => $posts
                ->flatMap(fn (array $post): array => $post['tags'] ?? [])
                ->where('status', 'mapped')
                ->count(),
            'missing_featured_images' => $posts
                ->filter(fn (array $post): bool => in_array('missing_featured_image', $post['warnings'] ?? [], true))
                ->count(),
            'will_create_authors' => $this->willCreateAuthorCount($posts),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $posts
     * @return array<string, mixed>
     */
    private function sourceNormalizationPreview(Collection $posts): array
    {
        $summary = $this->emptyNormalizationSummary();
        $summary['articles_scanned'] = $posts->count();
        $summary['manual_review_articles'] = 0;
        $summary['manual_review_articles_by_warning'] = array_fill_keys(self::CONTENT_REVIEW_WARNINGS, 0);

        foreach ($posts as $post) {
            $contentPreview = is_array($post['content_preview'] ?? null) ? $post['content_preview'] : [];
            $counts = is_array($contentPreview['normalization_counts'] ?? null)
                ? $contentPreview['normalization_counts']
                : [];
            $summary = $this->mergeNormalizationSummary(
                $summary,
                $this->normalizationSummaryFromCounts($counts),
            );
            $reviewWarnings = is_array($contentPreview['manual_review_warnings'] ?? null)
                ? $contentPreview['manual_review_warnings']
                : [];

            if ($reviewWarnings !== []) {
                $summary['manual_review_articles']++;
            }

            foreach ($reviewWarnings as $warning) {
                if (array_key_exists($warning, $summary['manual_review_articles_by_warning'])) {
                    $summary['manual_review_articles_by_warning'][$warning]++;
                }
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function contentPreviewForPost(array $post): array
    {
        $trustedUnavailableMedia = $this->trustedUnavailableMediaEvidence($post);
        $preview = $this->contentSanitizer->preview(
            data_get($post, 'content.rendered'),
            $this->trustedBlobImageRemovalEvidence($post) !== null,
            is_array($trustedUnavailableMedia['inline'] ?? null)
                ? $trustedUnavailableMedia['inline']
                : [],
        );

        if ($this->usesFeaturedImageFallback($post)) {
            $preview['warnings'][] = 'trusted_featured_image_fallback';
            $preview['warnings'] = array_values(array_unique($preview['warnings']));
            $preview['normalization_counts']['trusted_featured_image_fallback'] = 1;
        }

        if ($this->trustedUnavailableFeaturedMediaEvidence($post) !== null) {
            $preview['warnings'][] = 'trusted_unavailable_featured_media';
            $preview['warnings'] = array_values(array_unique($preview['warnings']));
            $preview['normalization_counts']['trusted_unavailable_featured_media'] = 1;
        }

        $reviewWarnings = array_values(array_intersect($preview['warnings'], self::CONTENT_REVIEW_WARNINGS));

        return [
            'warnings' => $preview['warnings'],
            'manual_review_required' => $reviewWarnings !== [],
            'manual_review_warnings' => $reviewWarnings,
            'normalization_counts' => $preview['normalization_counts'],
        ];
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, int>
     */
    private function normalizationSummaryForPost(array $post): array
    {
        $counts = $this->contentPreviewForPost($post)['normalization_counts'];

        return $this->normalizationSummaryFromCounts($counts);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function normalizationSummaryFromCounts(array $counts): array
    {
        return [
            'trusted_blob_images_removed_articles' => (int) ($counts['trusted_blob_image_removed'] ?? 0) > 0 ? 1 : 0,
            'trusted_blob_images_removed_elements' => (int) ($counts['trusted_blob_image_removed'] ?? 0),
            'trusted_divi_shortcodes_normalized_articles' => (int) ($counts['trusted_divi_shortcode_normalized'] ?? 0) > 0 ? 1 : 0,
            'trusted_divi_shortcodes_normalized_elements' => (int) ($counts['trusted_divi_shortcode_normalized'] ?? 0),
            'trusted_embed_scripts_removed_articles' => (int) ($counts['trusted_embed_script_removed'] ?? 0) > 0 ? 1 : 0,
            'trusted_embed_scripts_removed_elements' => (int) ($counts['trusted_embed_script_removed'] ?? 0),
            'trusted_empty_icons_removed_articles' => (int) ($counts['trusted_empty_icon_removed'] ?? 0) > 0 ? 1 : 0,
            'trusted_empty_icons_removed_elements' => (int) ($counts['trusted_empty_icon_removed'] ?? 0),
            'trusted_featured_image_fallbacks_articles' => (int) ($counts['trusted_featured_image_fallback'] ?? 0) > 0 ? 1 : 0,
            'trusted_featured_image_fallbacks_elements' => (int) ($counts['trusted_featured_image_fallback'] ?? 0),
            'trusted_unavailable_featured_media_articles' => (int) ($counts['trusted_unavailable_featured_media'] ?? 0) > 0 ? 1 : 0,
            'trusted_unavailable_featured_media_elements' => (int) ($counts['trusted_unavailable_featured_media'] ?? 0),
            'trusted_unavailable_inline_media_articles' => (int) ($counts['trusted_unavailable_inline_media'] ?? 0) > 0 ? 1 : 0,
            'trusted_unavailable_inline_media_elements' => (int) ($counts['trusted_unavailable_inline_media'] ?? 0),
            'trusted_iframes_hardened_articles' => (int) ($counts['trusted_iframe_hardened'] ?? 0) > 0 ? 1 : 0,
            'trusted_iframes_hardened_elements' => (int) ($counts['trusted_iframe_hardened'] ?? 0),
            'trusted_instagram_fallbacks_normalized_articles' => (int) ($counts['trusted_instagram_fallback_normalized'] ?? 0) > 0 ? 1 : 0,
            'trusted_instagram_fallbacks_normalized_elements' => (int) ($counts['trusted_instagram_fallback_normalized'] ?? 0),
            'trusted_malformed_links_normalized_articles' => (int) ($counts['trusted_malformed_link_normalized'] ?? 0) > 0 ? 1 : 0,
            'trusted_malformed_links_normalized_elements' => (int) ($counts['trusted_malformed_link_normalized'] ?? 0),
            'trusted_nested_links_normalized_articles' => (int) ($counts['trusted_nested_link_normalized'] ?? 0) > 0 ? 1 : 0,
            'trusted_nested_links_normalized_elements' => (int) ($counts['trusted_nested_link_normalized'] ?? 0),
            'trusted_twitter_fallbacks_normalized_articles' => (int) ($counts['trusted_twitter_fallback_normalized'] ?? 0) > 0 ? 1 : 0,
            'trusted_twitter_fallbacks_normalized_elements' => (int) ($counts['trusted_twitter_fallback_normalized'] ?? 0),
            'trusted_tiktok_fallbacks_normalized_articles' => (int) ($counts['trusted_tiktok_fallback_normalized'] ?? 0) > 0 ? 1 : 0,
            'trusted_tiktok_fallbacks_normalized_elements' => (int) ($counts['trusted_tiktok_fallback_normalized'] ?? 0),
            'trusted_wordpress_interactive_normalized_articles' => (int) ($counts['trusted_wordpress_interactive_normalized'] ?? 0) > 0 ? 1 : 0,
            'trusted_wordpress_interactive_normalized_elements' => (int) ($counts['trusted_wordpress_interactive_normalized'] ?? 0),
            'trusted_wordpress_media_wrappers_normalized_articles' => (int) ($counts['trusted_wordpress_media_wrapper_normalized'] ?? 0) > 0 ? 1 : 0,
            'trusted_wordpress_media_wrappers_normalized_elements' => (int) ($counts['trusted_wordpress_media_wrapper_normalized'] ?? 0),
            'trusted_wordpress_oembed_normalized_articles' => (int) ($counts['trusted_wordpress_oembed_normalized'] ?? 0) > 0 ? 1 : 0,
            'trusted_wordpress_oembed_normalized_elements' => (int) ($counts['trusted_wordpress_oembed_normalized'] ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function emptyNormalizationSummary(): array
    {
        return [
            'trusted_blob_images_removed_articles' => 0,
            'trusted_blob_images_removed_elements' => 0,
            'trusted_divi_shortcodes_normalized_articles' => 0,
            'trusted_divi_shortcodes_normalized_elements' => 0,
            'trusted_embed_scripts_removed_articles' => 0,
            'trusted_embed_scripts_removed_elements' => 0,
            'trusted_empty_icons_removed_articles' => 0,
            'trusted_empty_icons_removed_elements' => 0,
            'trusted_featured_image_fallbacks_articles' => 0,
            'trusted_featured_image_fallbacks_elements' => 0,
            'trusted_unavailable_featured_media_articles' => 0,
            'trusted_unavailable_featured_media_elements' => 0,
            'trusted_unavailable_inline_media_articles' => 0,
            'trusted_unavailable_inline_media_elements' => 0,
            'trusted_iframes_hardened_articles' => 0,
            'trusted_iframes_hardened_elements' => 0,
            'trusted_instagram_fallbacks_normalized_articles' => 0,
            'trusted_instagram_fallbacks_normalized_elements' => 0,
            'trusted_malformed_links_normalized_articles' => 0,
            'trusted_malformed_links_normalized_elements' => 0,
            'trusted_nested_links_normalized_articles' => 0,
            'trusted_nested_links_normalized_elements' => 0,
            'trusted_twitter_fallbacks_normalized_articles' => 0,
            'trusted_twitter_fallbacks_normalized_elements' => 0,
            'trusted_tiktok_fallbacks_normalized_articles' => 0,
            'trusted_tiktok_fallbacks_normalized_elements' => 0,
            'trusted_wordpress_interactive_normalized_articles' => 0,
            'trusted_wordpress_interactive_normalized_elements' => 0,
            'trusted_wordpress_media_wrappers_normalized_articles' => 0,
            'trusted_wordpress_media_wrappers_normalized_elements' => 0,
            'trusted_wordpress_oembed_normalized_articles' => 0,
            'trusted_wordpress_oembed_normalized_elements' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, int>  $addition
     * @return array<string, mixed>
     */
    private function mergeNormalizationSummary(array $summary, array $addition): array
    {
        foreach ($this->emptyNormalizationSummary() as $key => $zero) {
            $summary[$key] = (int) ($summary[$key] ?? $zero) + (int) ($addition[$key] ?? 0);
        }

        return $summary;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $posts
     */
    private function willCreateAuthorCount(Collection $posts): int
    {
        return $posts
            ->pluck('author')
            ->filter(fn (mixed $author): bool => is_array($author) && ($author['status'] ?? null) === 'will_create')
            ->map(fn (array $author): string => (string) (
                $author['source_id']
                ?? $author['source_slug']
                ?? $author['source_name']
                ?? ''
            ))
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * @param  array<string, mixed>  $authorData
     * @return array<string, mixed>
     */
    private function dryRunAuthorSummary(array $authorData, int $wordpressId): array
    {
        $sourceSlug = (string) ($authorData['slug'] ?? '');
        $sourceName = $this->cleanText($authorData['name'] ?? null);

        if ($wordpressId <= 0 && $sourceSlug === '' && $sourceName === '') {
            return [
                'status' => 'missing',
                'source_id' => null,
                'source_slug' => null,
                'source_name' => null,
                'target_id' => null,
                'target_slug' => null,
                'target_name' => null,
            ];
        }

        $summary = $this->dryRunTargetSummary('author', $wordpressId, $sourceSlug, $sourceName, Author::class);

        if ($summary['status'] !== 'will_create') {
            return $summary;
        }

        $existing = $this->existingAuthorFromWordpressData($authorData);

        if (! $existing) {
            return $summary;
        }

        return [
            ...$summary,
            'status' => $existing->slug === $sourceSlug ? 'existing_slug' : ($existing->email && strtolower($existing->email) === strtolower((string) ($authorData['email'] ?? '')) ? 'existing_email' : 'existing_name'),
            'target_id' => $existing->id,
            'target_slug' => $existing->slug,
            'target_name' => $existing->name,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function joinDryRunValues(Collection $rows, string $key): string
    {
        return $rows
            ->pluck($key)
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->unique()
            ->implode('|');
    }

    /**
     * @return array<string, mixed>
     */
    private function dryRunTargetSummary(string $sourceType, int $wordpressId, string $sourceSlug, string $sourceName, string $targetClass): array
    {
        $mapped = $this->mappedTarget($sourceType, $wordpressId, $sourceSlug, $targetClass);

        if ($mapped instanceof $targetClass) {
            return [
                'status' => 'mapped',
                'source_id' => $wordpressId ?: null,
                'source_slug' => $sourceSlug ?: null,
                'source_name' => $sourceName ?: null,
                'target_id' => $mapped->id,
                'target_slug' => $mapped->slug,
                'target_name' => $mapped->name,
            ];
        }

        $targetSlug = $this->safeSlug($sourceSlug, $sourceName);
        $existing = $targetSlug !== ''
            ? $targetClass::query()->where('slug', $targetSlug)->first()
            : null;

        return [
            'status' => $existing ? 'existing_slug' : 'will_create',
            'source_id' => $wordpressId ?: null,
            'source_slug' => $sourceSlug ?: null,
            'source_name' => $sourceName ?: null,
            'target_id' => $existing?->id,
            'target_slug' => $existing?->slug ?? $targetSlug,
            'target_name' => $existing?->name ?? $sourceName,
        ];
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function syncSeoMetadata(Article $article, array $post): void
    {
        $yoast = data_get($post, 'yoast_head_json', []);

        SeoMetadata::query()->updateOrCreate(
            [
                'seoable_type' => Article::class,
                'seoable_id' => $article->id,
            ],
            [
                'seo_title' => $this->cleanText($yoast['title'] ?? null) ?: $article->title,
                'meta_description' => $this->cleanText($yoast['description'] ?? null) ?: $article->excerpt,
                'canonical_url' => $article->publicUrl(),
                'og_title' => $this->cleanText($yoast['og_title'] ?? null) ?: null,
                'og_description' => $this->cleanText($yoast['og_description'] ?? null) ?: null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function recordFailure(array $post, Throwable $exception): void
    {
        WordpressImportRecord::query()->updateOrCreate(
            [
                'wordpress_id' => (int) ($post['id'] ?? 0),
                'wordpress_type' => 'post',
            ],
            [
                'old_url' => $post['link'] ?? null,
                'status' => 'failed',
                'source_payload' => $post,
                'warnings' => [],
                'error_message' => $exception->getMessage(),
                'imported_at' => null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $post
     * @return Collection<int, array<string, mixed>>
     */
    private function terms(array $post): Collection
    {
        return collect(data_get($post, '_embedded.wp:term', []))
            ->flatten(1)
            ->filter(fn ($term) => is_array($term))
            ->values();
    }

    private function rewriteInternalLinks(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        foreach ($this->frozenLinkMap as $oldUrl => $newUrl) {
            $oldUrl = rtrim($oldUrl, '/');
            $html = str_replace([
                'href="'.$oldUrl.'"',
                'href="'.$oldUrl.'/"',
            ], 'href="'.$newUrl.'"', $html);
        }

        $links = $this->importedLinkRecords();

        foreach ($links as $link) {
            $oldUrl = rtrim((string) $link->old_url, '/');
            $newUrl = (string) $link->new_url;

            $html = str_replace([
                'href="'.$oldUrl.'"',
                'href="'.$oldUrl.'/"',
            ], 'href="'.$newUrl.'"', $html);
        }

        return $html;
    }

    private function cleanExcerpt(?string $html): ?string
    {
        $text = $this->cleanText($html);

        return $text === '' ? null : $text;
    }

    private function cleanText(mixed $value): string
    {
        return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @param array<string, mixed> $post */
    public function materializedPostTitle(array $post, bool $allowUntitledNonpublic = false): string
    {
        $title = $this->cleanText(data_get($post, 'title.rendered'));

        if ($title !== '' || ! $allowUntitledNonpublic) {
            return $title;
        }

        $wordpressId = (int) ($post['id'] ?? 0);
        $sourceStatus = (string) ($post['status'] ?? '');

        if ($wordpressId < 1 || $sourceStatus !== 'draft') {
            return '';
        }

        return "Untitled WordPress draft #{$wordpressId}";
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'publish' => 'published',
            'future' => 'scheduled',
            'pending' => 'review',
            'trash' => 'archived',
            default => 'draft',
        };
    }

    private function parseWordpressDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC');
    }

    private function uniqueSlug(string $slug, int $wordpressId, ?string $fallback = null): string
    {
        $slug = $this->safeSlug($slug, $fallback ?: "wordpress {$wordpressId}") ?: "wordpress-{$wordpressId}";
        $exists = Article::query()
            ->where('slug', $slug)
            ->where(function ($query) use ($wordpressId): void {
                $query
                    ->where('old_wordpress_id', '!=', $wordpressId)
                    ->orWhereNull('old_wordpress_id');
            })
            ->exists();

        return $exists ? "{$slug}-wp-{$wordpressId}" : $slug;
    }

    private function safeSlug(string $slug, ?string $fallback = null): string
    {
        $decodedSlug = rawurldecode(trim($slug));

        return Str::slug($decodedSlug) ?: Str::slug((string) $fallback);
    }

    private function createRedirectForImport(?string $oldUrl, string $newUrl): void
    {
        if (! $oldUrl || $this->isSelfRedirect($oldUrl, $newUrl)) {
            return;
        }

        Redirect::query()->updateOrCreate(
            ['source_url' => $oldUrl],
            [
                'target_url' => $newUrl,
                'status_code' => 301,
                'is_active' => true,
            ],
        );
    }

    private function isSelfRedirect(?string $oldUrl, ?string $newUrl): bool
    {
        return $this->normalizedPath($oldUrl) !== ''
            && $this->normalizedPath($oldUrl) === $this->normalizedPath($newUrl);
    }

    private function normalizedPath(?string $url): string
    {
        return UrlPath::normalize($url);
    }

    /**
     * @return Collection<int, WordpressImportRecord>
     */
    private function importedLinkRecords(): Collection
    {
        return WordpressImportRecord::query()
            ->whereIn('wordpress_type', WordpressImportRecord::REDIRECTABLE_TYPES)
            ->where('status', 'imported')
            ->whereNotNull('old_url')
            ->whereNotNull('new_url')
            ->get(['old_url', 'new_url']);
    }

    /**
     * @return array<string, mixed>
     */
    private function recordSummary(WordpressImportRecord $record): array
    {
        return [
            'wordpress_id' => $record->wordpress_id,
            'old_url' => $record->old_url,
            'new_url' => $record->new_url,
            'status' => $record->status,
            'warnings' => $record->warnings ?? [],
            'error_message' => $record->error_message,
        ];
    }
}
