<?php

namespace App\Services\Wordpress;

use App\Models\WordpressImportRecord;
use App\Services\Media\MediaQualityReportService;
use App\Services\Seo\RedirectQaReportService;
use App\Services\Seo\SeoHealthCheckService;

class WordpressMigrationReadinessService
{
    public function __construct(
        private readonly WordpressMigrationReportService $migrationReport,
        private readonly RedirectQaReportService $redirectReport,
        private readonly MediaQualityReportService $mediaReport,
        private readonly SeoHealthCheckService $seoReport,
        private readonly WordpressOldLinkRepairService $oldLinkRepair,
        private readonly WordpressMigrationCompletionService $completionReport,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     totals: array<string, mixed>,
     *     checks: array<int, array<string, mixed>>,
     *     samples: array<string, mixed>
     * }
     */
    public function summary(): array
    {
        $migration = $this->migrationReport->summary();
        $redirects = $this->redirectReport->summary();
        $media = $this->mediaReport->summary();
        $seo = $this->seoReport->summary();
        $oldLinks = $this->oldLinkRepair->repair(apply: false);
        $completion = $this->completionReport->localAudit();
        $blockingMediaIssues = (int) $media['totals']['missing_file']
            + (int) $media['totals']['missing_variants'];
        $approvedUnavailableMedia = $migration['approved_unavailable_media'];
        $approvedUnavailableElements = (int) $approvedUnavailableMedia['trusted_unavailable_featured_media_elements']
            + (int) $approvedUnavailableMedia['trusted_unavailable_inline_media_elements'];

        $checks = [
            $this->check(
                (int) $migration['posts']['failed'] === 0 ? 'pass' : 'fail',
                'failed_wordpress_imports',
                (int) $migration['posts']['failed'] === 0
                    ? 'No failed WordPress post imports.'
                    : "{$migration['posts']['failed']} WordPress post imports are failed.",
                ['count' => (int) $migration['posts']['failed']],
            ),
            $this->check(
                (int) $migration['issues']['manual_review'] === 0 ? 'pass' : 'fail',
                'manual_review_items',
                (int) $migration['issues']['manual_review'] === 0
                    ? 'No imported WordPress posts carry an unresolved manual-review marker.'
                    : "{$migration['issues']['manual_review']} imported WordPress posts still require explicit manual review.",
                ['count' => (int) $migration['issues']['manual_review']],
            ),
            $this->check(
                (int) $migration['issues']['imported_articles_without_featured_images'] === 0 ? 'pass' : 'fail',
                'missing_featured_images',
                (int) $migration['issues']['imported_articles_without_featured_images'] === 0
                    ? 'Every imported article has a featured image or an explicitly approved unavailable-media treatment.'
                    : "{$migration['issues']['imported_articles_without_featured_images']} imported articles have no featured image and no approved unavailable-media treatment.",
                ['count' => (int) $migration['issues']['imported_articles_without_featured_images']],
            ),
            $this->check(
                (int) $migration['issues']['invalid_unavailable_media_evidence'] === 0 ? 'pass' : 'fail',
                'invalid_unavailable_media_evidence',
                (int) $migration['issues']['invalid_unavailable_media_evidence'] === 0
                    ? 'Every trusted unavailable-media marker is bound to the current article materialization.'
                    : "{$migration['issues']['invalid_unavailable_media_evidence']} imported posts have unavailable-media markers without valid current materialization evidence.",
                ['count' => (int) $migration['issues']['invalid_unavailable_media_evidence']],
            ),
            $this->check(
                (int) $migration['issues']['invalid_reconciliation_materializations'] === 0 ? 'pass' : 'fail',
                'invalid_reconciliation_materializations',
                (int) $migration['issues']['invalid_reconciliation_materializations'] === 0
                    ? 'Every imported WordPress post is bound to its current persisted materialization.'
                    : "{$migration['issues']['invalid_reconciliation_materializations']} imported posts lack valid reconciliation materialization for their current body and evidence.",
                ['count' => (int) $migration['issues']['invalid_reconciliation_materializations']],
            ),
            $this->check(
                $approvedUnavailableElements === 0 ? 'pass' : 'warn',
                'approved_unavailable_media',
                $approvedUnavailableElements === 0
                    ? 'No source media required an approved unavailable-media treatment.'
                    : "{$approvedUnavailableElements} source media elements use the approved unavailable-media treatment and remain auditable.",
                $approvedUnavailableMedia,
            ),
            $this->check(
                (int) $completion['gaps']['source_publication_mismatches'] === 0 ? 'pass' : 'fail',
                'source_publication_mismatches',
                (int) $completion['gaps']['source_publication_mismatches'] === 0
                    ? 'WordPress-published posts and local public availability are aligned.'
                    : "{$completion['gaps']['source_publication_mismatches']} WordPress/local publication mismatches require content-lead approval.",
                ['count' => (int) $completion['gaps']['source_publication_mismatches']],
            ),
            $this->check(
                (int) $completion['gaps']['source_freshness_mismatches'] === 0 ? 'pass' : 'fail',
                'source_freshness_mismatches',
                (int) $completion['gaps']['source_freshness_mismatches'] === 0
                    ? 'Stored WordPress status and modified timestamps match the current source inventory.'
                    : "{$completion['gaps']['source_freshness_mismatches']} imported posts are stale against the current WordPress source.",
                ['count' => (int) $completion['gaps']['source_freshness_mismatches']],
            ),
            $this->check(
                $oldLinks['references_found'] === 0 ? 'pass' : 'fail',
                'unresolved_wordpress_links',
                $oldLinks['references_found'] === 0
                    ? 'No imported article bodies contain old WordPress links or upload references.'
                    : "{$oldLinks['references_found']} imported article body references still point to old WordPress uploads.",
                ['count' => $oldLinks['references_found']],
            ),
            $this->check(
                (int) $redirects['totals']['missing'] === 0 ? 'pass' : 'fail',
                'missing_wordpress_redirects',
                (int) $redirects['totals']['missing'] === 0
                    ? 'Imported URL changes have redirect coverage.'
                    : "{$redirects['totals']['missing']} imported URL changes are missing 301 redirects.",
                ['count' => (int) $redirects['totals']['missing']],
            ),
            $this->check(
                (int) $completion['gaps']['missing_author_legacy_urls'] === 0 ? 'pass' : 'fail',
                'missing_author_legacy_urls',
                (int) $completion['gaps']['missing_author_legacy_urls'] === 0
                    ? 'Imported WordPress authors have auditable legacy source URLs.'
                    : "{$completion['gaps']['missing_author_legacy_urls']} imported WordPress authors have no legacy source URL evidence.",
                ['count' => (int) $completion['gaps']['missing_author_legacy_urls']],
            ),
            $this->check(
                ((int) $completion['gaps']['invalid_category_import_records']
                    + (int) $completion['gaps']['invalid_tag_import_records']) === 0 ? 'pass' : 'fail',
                'invalid_taxonomy_import_records',
                ((int) $completion['gaps']['invalid_category_import_records']
                    + (int) $completion['gaps']['invalid_tag_import_records']) === 0
                    ? 'Imported WordPress taxonomy records reference valid local category and tag models.'
                    : 'Imported WordPress taxonomy records contain wrong-type, missing, or dangling local mappings.',
                [
                    'categories' => (int) $completion['gaps']['invalid_category_import_records'],
                    'tags' => (int) $completion['gaps']['invalid_tag_import_records'],
                ],
            ),
            $this->check(
                ((int) $redirects['totals']['inactive'] + (int) $redirects['totals']['non_301']) === 0 ? 'pass' : 'fail',
                'unsafe_redirects',
                ((int) $redirects['totals']['inactive'] + (int) $redirects['totals']['non_301']) === 0
                    ? 'No inactive or non-301 redirects were found.'
                    : "{$redirects['totals']['inactive']} inactive and {$redirects['totals']['non_301']} non-301 redirects need review.",
                [
                    'inactive' => (int) $redirects['totals']['inactive'],
                    'non_301' => (int) $redirects['totals']['non_301'],
                ],
            ),
            $this->check(
                (int) $migration['issues']['review_queue'] === 0 ? 'pass' : 'warn',
                'migration_review_queue',
                (int) $migration['issues']['review_queue'] === 0
                    ? 'No imported posts are waiting in the editorial review queue.'
                    : "{$migration['issues']['review_queue']} imported post review items need editorial follow-up.",
                ['count' => (int) $migration['issues']['review_queue']],
            ),
            $this->check(
                (int) $completion['gaps']['body_cleanup_candidates'] === 0 ? 'pass' : 'warn',
                'wordpress_body_cleanup',
                (int) $completion['gaps']['body_cleanup_candidates'] === 0
                    ? 'Imported article bodies have no remaining WordPress shortcode/class/style cleanup candidates.'
                    : "{$completion['gaps']['body_cleanup_candidates']} imported article bodies still have WordPress cleanup candidates.",
                ['count' => (int) $completion['gaps']['body_cleanup_candidates']],
            ),
            $this->check(
                $blockingMediaIssues > 0
                    ? 'fail'
                    : ((int) $media['totals']['issues'] === 0 ? 'pass' : 'warn'),
                'media_quality_issues',
                $blockingMediaIssues > 0
                    ? "{$blockingMediaIssues} stored media file or responsive variant gaps block migration readiness."
                    : ((int) $media['totals']['issues'] === 0
                    ? 'Imported media quality checks are clean.'
                    : "{$media['totals']['issues']} non-blocking media metadata issues need cleanup."),
                [
                    ...$media['totals'],
                    'blocking_missing_files_or_variants' => $blockingMediaIssues,
                ],
            ),
            $this->check(
                (int) $seo['totals']['issues_by_severity']['critical'] > 0
                    ? 'fail'
                    : ((int) $seo['totals']['issues'] === 0 ? 'pass' : 'warn'),
                'public_seo_issues',
                (int) $seo['totals']['issues_by_severity']['critical'] > 0
                    ? "{$seo['totals']['issues_by_severity']['critical']} critical public SEO issues block migration readiness."
                    : ((int) $seo['totals']['issues'] === 0
                    ? 'Public SEO checks are clean.'
                    : "{$seo['totals']['issues']} non-critical public SEO issues need cleanup."),
                $seo['totals'],
            ),
        ];

        return [
            'ready' => collect($checks)->doesntContain(fn (array $check): bool => $check['status'] === 'fail'),
            'totals' => [
                'posts' => $migration['posts'],
                'migration_issues' => $migration['issues'],
                'migration_normalizations' => $completion['local']['persisted_normalizations'],
                'global_migration_normalizations' => $migration['normalizations'],
                'approved_unavailable_media' => $approvedUnavailableMedia,
                'redirects' => $redirects['totals'],
                'media' => $media['totals'],
                'seo' => $seo['totals'],
                'unresolved_wordpress_links' => $oldLinks['references_found'],
                'old_link_repair' => [
                    'articles_checked' => $oldLinks['articles_checked'],
                    'articles_with_references' => $oldLinks['articles_with_references'],
                    'references_found' => $oldLinks['references_found'],
                    'failed_repair_warnings' => $this->failedRepairWarnings(),
                    'shortcodes_remaining' => $oldLinks['shortcodes_removed'],
                    'next_repair_command' => 'php artisan wordpress:repair-old-links --apply --limit=400 --skip-known-failed',
                    'cleanup_command' => 'php artisan media:cleanup-quality --apply',
                    'export_command' => 'php artisan wordpress:old-link-export',
                ],
                'completion' => [
                    'imported_articles' => $completion['local']['imported_articles'],
                    'post_records' => $completion['local']['post_records']['total'],
                    'failed_import_records' => $completion['gaps']['failed_import_records'],
                    'recorded_not_imported' => $completion['gaps']['recorded_not_imported'],
                    'missing_featured_images' => $completion['gaps']['missing_featured_images'],
                    'invalid_unavailable_media_evidence' => $completion['gaps']['invalid_unavailable_media_evidence'],
                    'invalid_reconciliation_materializations' => $completion['gaps']['invalid_reconciliation_materializations'],
                    'approved_unavailable_media' => $completion['local']['approved_unavailable_media'],
                    'missing_author_legacy_urls' => $completion['gaps']['missing_author_legacy_urls'],
                    'missing_redirects' => $completion['gaps']['missing_redirects'],
                    'body_cleanup_candidates' => $completion['gaps']['body_cleanup_candidates'],
                    'prospective_normalizations' => $completion['body_cleanup']['prospective_normalizations'],
                    'author_mapping_conflicts' => $completion['gaps']['author_mapping_conflicts'],
                    'source_publication_mismatches' => $completion['gaps']['source_publication_mismatches'],
                    'source_freshness_mismatches' => $completion['gaps']['source_freshness_mismatches'],
                    'source_categories_without_records' => $completion['gaps']['source_categories_without_records'],
                    'source_tags_without_records' => $completion['gaps']['source_tags_without_records'],
                    'category_records_without_source' => $completion['gaps']['category_records_without_source'],
                    'tag_records_without_source' => $completion['gaps']['tag_records_without_source'],
                    'invalid_category_import_records' => $completion['gaps']['invalid_category_import_records'],
                    'invalid_tag_import_records' => $completion['gaps']['invalid_tag_import_records'],
                    'audit_command' => 'php artisan wordpress:migration-completion --json',
                    'completion_command' => 'php artisan wordpress:migration-completion --sync-taxonomies --refresh-source --generate-redirects --json',
                ],
            ],
            'checks' => $checks,
            'samples' => [
                'unresolved_wordpress_links' => array_slice($oldLinks['items'], 0, 25),
                'latest_failures' => $migration['latest_failures'],
                'redirects_missing' => array_slice($redirects['missing'], 0, 10),
                'media_issues' => array_slice($media['issues'], 0, 10),
                'seo_issues' => array_slice($seo['issues'], 0, 10),
                'completion_body_cleanup' => array_slice($completion['samples']['body_cleanup_candidates'], 0, 10),
                'completion_author_conflicts' => array_slice($completion['samples']['author_mapping_conflicts'], 0, 10),
                'invalid_category_import_records' => array_slice($completion['samples']['invalid_category_import_records'], 0, 10),
                'invalid_tag_import_records' => array_slice($completion['samples']['invalid_tag_import_records'], 0, 10),
                'source_publication_mismatches' => array_slice($completion['samples']['source_publication_mismatches'], 0, 10),
                'source_freshness_mismatches' => array_slice($completion['samples']['source_freshness_mismatches'], 0, 10),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function check(string $status, string $code, string $message, array $context = []): array
    {
        return [
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }

    private function failedRepairWarnings(): int
    {
        return WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->whereJsonContains('warnings', 'old_link_repair_failed')
            ->count();
    }
}
