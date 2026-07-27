<?php

namespace App\Console\Commands;

use App\Services\Wordpress\WordpressMigrationCompletionService;
use App\Services\Wordpress\WordpressRestImporter;
use Illuminate\Console\Command;

class WordpressMigrationCompletionCommand extends Command
{
    protected $signature = 'wordpress:migration-completion
        {base-url? : WordPress base URL. Defaults to WORDPRESS_BASE_URL.}
        {--per-page=20 : WordPress REST posts per page.}
        {--max-pages= : Optional maximum post pages to audit.}
        {--import-missing : Import source posts that have no local import record.}
        {--refresh-source : Re-import every current source post to refresh status, dates, and content.}
        {--sync-taxonomies : Import all public WordPress categories and tags.}
        {--generate-redirects : Re-run imported link finalization and 301 redirect generation.}
        {--clean-bodies : Clean remaining WordPress shortcodes/classes/styles in imported article bodies.}
        {--json : Print the completion report as JSON.}';

    protected $description = 'Audit WordPress migration completion and optionally run safe import completion steps.';

    public function handle(WordpressMigrationCompletionService $completion, WordpressRestImporter $importer): int
    {
        $baseUrl = $this->argument('base-url') ?? config('services.wordpress.base_url');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            $this->error('Provide a WordPress base URL or set WORDPRESS_BASE_URL.');

            return self::FAILURE;
        }

        $baseUrl = trim($baseUrl);
        $perPage = max(1, min((int) $this->option('per-page'), 100));
        $maxPagesOption = $this->option('max-pages');
        $maxPages = $maxPagesOption === null || $maxPagesOption === '' ? null : max(1, (int) $maxPagesOption);
        $actions = [];
        $actionRequested = collect([
            'sync-taxonomies',
            'import-missing',
            'refresh-source',
            'generate-redirects',
            'clean-bodies',
        ])->contains(fn (string $option): bool => (bool) $this->option($option));
        $preflight = $completion->audit($baseUrl, $perPage, $maxPages);

        if ($actionRequested && ! (bool) $preflight['source']['scan_complete']) {
            $payload = [
                'blocked_reason' => 'source_scan_incomplete',
                'preflight' => $preflight,
                'actions' => [],
                'audit' => $preflight,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('Source scan is incomplete; no mutation actions were run.');
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('sync-taxonomies')) {
            $actions['sync_taxonomies'] = $importer->importTaxonomies($baseUrl);
        }

        if ((bool) $this->option('import-missing')) {
            $actions['import_missing'] = $importer->importMissingPosts($baseUrl, $perPage, $maxPages);
        }

        if ((bool) $this->option('refresh-source')) {
            $actions['refresh_source'] = $importer->importPosts($baseUrl, $perPage);
            $actions['refresh_source']['normalizations_match_preflight'] = $this->normalizationCounts(
                $actions['refresh_source']['normalizations'] ?? [],
            ) === $this->normalizationCounts($preflight['source']['normalization_preview'] ?? []);
        }

        if ((bool) $this->option('generate-redirects')) {
            $actions['generate_redirects'] = $importer->finalizeImportedContent($baseUrl);
        }

        if ((bool) $this->option('clean-bodies')) {
            $actions['clean_bodies'] = $completion->cleanImportedArticleBodies(apply: true);
        }

        $audit = $actionRequested
            ? $completion->audit($baseUrl, $perPage, $maxPages)
            : $preflight;
        $persistedMatchesPreflight = $this->normalizationCounts(
            $audit['local']['persisted_normalizations'] ?? [],
        ) === $this->normalizationCounts(
            $preflight['source']['normalization_expected']
                ?? $preflight['source']['normalization_preview']
                ?? [],
        );
        $refreshRequested = (bool) $this->option('refresh-source');
        $refreshMatchesPreflight = (bool) data_get(
            $actions,
            'refresh_source.normalizations_match_preflight',
            ! $refreshRequested,
        );
        $failureReasons = [];
        $actionFailed = collect($actions)->contains(
            fn (mixed $summary): bool => is_array($summary) && (int) ($summary['failed'] ?? 0) > 0,
        );

        if ($actionFailed) {
            $failureReasons[] = 'action_failed';
        }

        if ($refreshRequested && ! $refreshMatchesPreflight) {
            $failureReasons[] = 'refresh_normalization_mismatch';
        }

        if ($refreshRequested && ! $persistedMatchesPreflight) {
            $failureReasons[] = 'persisted_normalization_mismatch';
        }

        $payload = [
            'preflight' => $preflight,
            'actions' => $actions,
            'audit' => $audit,
            'failure_reasons' => $failureReasons,
            'normalization_comparison' => [
                'persisted_matches_preflight' => $persistedMatchesPreflight,
            ],
        ];
        $commandFailed = $failureReasons !== [];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $audit['ready'] && ! $commandFailed ? self::SUCCESS : self::FAILURE;
        }

        $this->info('WordPress migration completion: '.($audit['ready'] ? 'ready' : 'gaps found'));
        $this->line("Source posts: {$audit['source']['posts']}");
        $this->line("Imported articles: {$audit['local']['imported_articles']}");
        $this->line("Missing import records: {$audit['gaps']['missing_import_records']}");
        $this->line("Recorded but not imported: {$audit['gaps']['recorded_not_imported']}");
        $this->line("Failed import records: {$audit['gaps']['failed_import_records']}");
        $this->line("Missing featured images: {$audit['gaps']['missing_featured_images']}");
        $this->line("Missing author legacy URLs: {$audit['gaps']['missing_author_legacy_urls']}");
        $this->line("Missing redirects: {$audit['gaps']['missing_redirects']}");
        $this->line("Body cleanup candidates: {$audit['gaps']['body_cleanup_candidates']}");
        $this->line("Author mapping conflicts: {$audit['gaps']['author_mapping_conflicts']}");
        $this->line("Source categories without import records: {$audit['gaps']['source_categories_without_records']}");
        $this->line("Source tags without import records: {$audit['gaps']['source_tags_without_records']}");
        $this->line("Imported category records absent from source: {$audit['gaps']['category_records_without_source']}");
        $this->line("Imported tag records absent from source: {$audit['gaps']['tag_records_without_source']}");
        $this->line("Invalid category import records: {$audit['gaps']['invalid_category_import_records']}");
        $this->line("Invalid tag import records: {$audit['gaps']['invalid_tag_import_records']}");
        $this->line("Source publication mismatches: {$audit['gaps']['source_publication_mismatches']}");
        $this->line("Source freshness mismatches: {$audit['gaps']['source_freshness_mismatches']}");
        $this->line("Source normalization mismatches: {$audit['gaps']['source_normalization_mismatches']}");

        if ($actions !== []) {
            $this->newLine();
            $this->line('Actions run:');

            foreach (array_keys($actions) as $action) {
                $this->line('- '.str_replace('_', ' ', $action));
            }
        }

        return $audit['ready'] && ! $commandFailed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return array<string, int>
     */
    private function normalizationCounts(array $counts): array
    {
        return [
            'trusted_blob_images_removed_articles' => (int) ($counts['trusted_blob_images_removed_articles'] ?? 0),
            'trusted_blob_images_removed_elements' => (int) ($counts['trusted_blob_images_removed_elements'] ?? 0),
            'trusted_divi_shortcodes_normalized_articles' => (int) ($counts['trusted_divi_shortcodes_normalized_articles'] ?? 0),
            'trusted_divi_shortcodes_normalized_elements' => (int) ($counts['trusted_divi_shortcodes_normalized_elements'] ?? 0),
            'trusted_embed_scripts_removed_articles' => (int) ($counts['trusted_embed_scripts_removed_articles'] ?? 0),
            'trusted_embed_scripts_removed_elements' => (int) ($counts['trusted_embed_scripts_removed_elements'] ?? 0),
            'trusted_empty_icons_removed_articles' => (int) ($counts['trusted_empty_icons_removed_articles'] ?? 0),
            'trusted_empty_icons_removed_elements' => (int) ($counts['trusted_empty_icons_removed_elements'] ?? 0),
            'trusted_featured_image_fallbacks_articles' => (int) ($counts['trusted_featured_image_fallbacks_articles'] ?? 0),
            'trusted_featured_image_fallbacks_elements' => (int) ($counts['trusted_featured_image_fallbacks_elements'] ?? 0),
            'trusted_iframes_hardened_articles' => (int) ($counts['trusted_iframes_hardened_articles'] ?? 0),
            'trusted_iframes_hardened_elements' => (int) ($counts['trusted_iframes_hardened_elements'] ?? 0),
            'trusted_instagram_fallbacks_normalized_articles' => (int) ($counts['trusted_instagram_fallbacks_normalized_articles'] ?? 0),
            'trusted_instagram_fallbacks_normalized_elements' => (int) ($counts['trusted_instagram_fallbacks_normalized_elements'] ?? 0),
            'trusted_malformed_links_normalized_articles' => (int) ($counts['trusted_malformed_links_normalized_articles'] ?? 0),
            'trusted_malformed_links_normalized_elements' => (int) ($counts['trusted_malformed_links_normalized_elements'] ?? 0),
            'trusted_nested_links_normalized_articles' => (int) ($counts['trusted_nested_links_normalized_articles'] ?? 0),
            'trusted_nested_links_normalized_elements' => (int) ($counts['trusted_nested_links_normalized_elements'] ?? 0),
            'trusted_twitter_fallbacks_normalized_articles' => (int) ($counts['trusted_twitter_fallbacks_normalized_articles'] ?? 0),
            'trusted_twitter_fallbacks_normalized_elements' => (int) ($counts['trusted_twitter_fallbacks_normalized_elements'] ?? 0),
            'trusted_tiktok_fallbacks_normalized_articles' => (int) ($counts['trusted_tiktok_fallbacks_normalized_articles'] ?? 0),
            'trusted_tiktok_fallbacks_normalized_elements' => (int) ($counts['trusted_tiktok_fallbacks_normalized_elements'] ?? 0),
            'trusted_wordpress_interactive_normalized_articles' => (int) ($counts['trusted_wordpress_interactive_normalized_articles'] ?? 0),
            'trusted_wordpress_interactive_normalized_elements' => (int) ($counts['trusted_wordpress_interactive_normalized_elements'] ?? 0),
            'trusted_wordpress_media_wrappers_normalized_articles' => (int) ($counts['trusted_wordpress_media_wrappers_normalized_articles'] ?? 0),
            'trusted_wordpress_media_wrappers_normalized_elements' => (int) ($counts['trusted_wordpress_media_wrappers_normalized_elements'] ?? 0),
            'trusted_wordpress_oembed_normalized_articles' => (int) ($counts['trusted_wordpress_oembed_normalized_articles'] ?? 0),
            'trusted_wordpress_oembed_normalized_elements' => (int) ($counts['trusted_wordpress_oembed_normalized_elements'] ?? 0),
        ];
    }
}
