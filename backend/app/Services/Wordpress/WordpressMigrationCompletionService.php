<?php

namespace App\Services\Wordpress;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Tag;
use App\Models\WordpressImportRecord;
use App\Services\Seo\RedirectQaReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class WordpressMigrationCompletionService
{
    /** @var array<string, array{articles: string, elements: string}> */
    private const array NORMALIZATION_FAMILIES = [
        'trusted_blob_image_removed' => ['articles' => 'trusted_blob_images_removed_articles', 'elements' => 'trusted_blob_images_removed_elements'],
        'trusted_divi_shortcode_normalized' => ['articles' => 'trusted_divi_shortcodes_normalized_articles', 'elements' => 'trusted_divi_shortcodes_normalized_elements'],
        'trusted_embed_script_removed' => ['articles' => 'trusted_embed_scripts_removed_articles', 'elements' => 'trusted_embed_scripts_removed_elements'],
        'trusted_empty_icon_removed' => ['articles' => 'trusted_empty_icons_removed_articles', 'elements' => 'trusted_empty_icons_removed_elements'],
        'trusted_featured_image_fallback' => ['articles' => 'trusted_featured_image_fallbacks_articles', 'elements' => 'trusted_featured_image_fallbacks_elements'],
        'trusted_unavailable_featured_media' => ['articles' => 'trusted_unavailable_featured_media_articles', 'elements' => 'trusted_unavailable_featured_media_elements'],
        'trusted_unavailable_inline_media' => ['articles' => 'trusted_unavailable_inline_media_articles', 'elements' => 'trusted_unavailable_inline_media_elements'],
        'trusted_iframe_hardened' => ['articles' => 'trusted_iframes_hardened_articles', 'elements' => 'trusted_iframes_hardened_elements'],
        'trusted_instagram_fallback_normalized' => ['articles' => 'trusted_instagram_fallbacks_normalized_articles', 'elements' => 'trusted_instagram_fallbacks_normalized_elements'],
        'trusted_malformed_link_normalized' => ['articles' => 'trusted_malformed_links_normalized_articles', 'elements' => 'trusted_malformed_links_normalized_elements'],
        'trusted_nested_link_normalized' => ['articles' => 'trusted_nested_links_normalized_articles', 'elements' => 'trusted_nested_links_normalized_elements'],
        'trusted_twitter_fallback_normalized' => ['articles' => 'trusted_twitter_fallbacks_normalized_articles', 'elements' => 'trusted_twitter_fallbacks_normalized_elements'],
        'trusted_tiktok_fallback_normalized' => ['articles' => 'trusted_tiktok_fallbacks_normalized_articles', 'elements' => 'trusted_tiktok_fallbacks_normalized_elements'],
        'trusted_wordpress_interactive_normalized' => ['articles' => 'trusted_wordpress_interactive_normalized_articles', 'elements' => 'trusted_wordpress_interactive_normalized_elements'],
        'trusted_wordpress_media_wrapper_normalized' => ['articles' => 'trusted_wordpress_media_wrappers_normalized_articles', 'elements' => 'trusted_wordpress_media_wrappers_normalized_elements'],
        'trusted_wordpress_oembed_normalized' => ['articles' => 'trusted_wordpress_oembed_normalized_articles', 'elements' => 'trusted_wordpress_oembed_normalized_elements'],
    ];

    public function __construct(
        private readonly WordpressRestImporter $importer,
        private readonly WordpressMigrationReportService $migrationReport,
        private readonly RedirectQaReportService $redirectReport,
        private readonly WordpressContentSanitizer $contentSanitizer,
        private readonly WordpressFrozenContentDecisionProvenance $contentDecisionProvenance,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(?string $baseUrl = null, int $perPage = 20, ?int $maxPages = null): array
    {
        $baseUrl = $this->baseUrl($baseUrl);
        $source = $this->sourceSummary($baseUrl, $perPage, $maxPages);
        $migration = $this->migrationReport->summary();
        $redirects = $this->redirectReport->summary();
        $cleanup = $this->cleanImportedArticleBodies(apply: false);
        $sourceIds = collect($source['post_ids']);
        $importedArticleIds = $this->importedArticleIds();
        $recordIds = $this->postRecordIds();
        $safeImportMissingIds = $sourceIds
            ->diff($recordIds)
            ->diff($importedArticleIds)
            ->values()
            ->all();
        $recordedNotImported = $this->recordedNotImportedRows($sourceIds);
        $slugChanges = $this->slugChanges($source['posts']);
        $authorConflicts = $this->authorMappingConflicts();
        $sourceCategoryIds = collect($source['category_ids']);
        $sourceTagIds = collect($source['tag_ids']);
        $categoryRecordAudit = $this->termRecordAudit('category');
        $tagRecordAudit = $this->termRecordAudit('tag');
        $categoryRecordsWithoutSource = $source['available'] && $source['taxonomy_scan_complete']
            ? $categoryRecordAudit['wordpress_ids']->diff($sourceCategoryIds)->values()
            : collect();
        $tagRecordsWithoutSource = $source['available'] && $source['taxonomy_scan_complete']
            ? $tagRecordAudit['wordpress_ids']->diff($sourceTagIds)->values()
            : collect();
        $parity = $this->sourceParity($source['parity_posts'], $source['available']);
        $normalizationSourceIds = $source['available']
            ? $sourceIds
            : collect($source['parity_posts'])
                ->where('source_status', 'publish')
                ->pluck('wordpress_id')
                ->map(fn (mixed $id): int => (int) $id);
        $sourcePersistedNormalizations = $this->persistedNormalizationsForSourceIds(
            $normalizationSourceIds,
        );
        $decisionNormalizationEvidence = $source['available'] && $source['scan_complete']
            ? $this->decisionNormalizationEvidence($sourceIds)
            : $this->emptyDecisionNormalizationEvidence();
        $sourceNormalizationExpected = $this->normalizationCounts(
            is_array($source['normalization_preview'] ?? null)
                ? $source['normalization_preview']
                : [],
        );

        foreach ($decisionNormalizationEvidence['expected'] as $key => $count) {
            $sourceNormalizationExpected[$key] = $count;
        }

        $sourceNormalizationMatches = ! $source['available']
            || ! $source['scan_complete']
            || ($decisionNormalizationEvidence['valid']
                && $this->normalizationCounts($sourcePersistedNormalizations)
                    === $sourceNormalizationExpected);

        $gaps = [
            'source_scan_incomplete' => $source['available'] && ! $source['scan_complete'] ? 1 : 0,
            'source_normalization_mismatches' => $sourceNormalizationMatches ? 0 : 1,
            'missing_import_records' => count($safeImportMissingIds),
            'not_imported_source_posts' => $sourceIds->diff($importedArticleIds)->count(),
            'recorded_not_imported' => count($recordedNotImported),
            'failed_import_records' => (int) $migration['posts']['failed'],
            'pending_import_records' => (int) $migration['posts']['pending'],
            'skipped_import_records' => (int) $migration['posts']['skipped'],
            'missing_featured_images' => (int) $migration['issues']['imported_articles_without_featured_images'],
            'invalid_unavailable_media_evidence' => (int) $migration['issues']['invalid_unavailable_media_evidence'],
            'invalid_reconciliation_materializations' => (int) $migration['issues']['invalid_reconciliation_materializations'],
            'missing_author_legacy_urls' => $this->missingAuthorLegacyUrlCount(),
            'missing_redirects' => (int) $redirects['totals']['missing'],
            'unsafe_redirects' => (int) $redirects['totals']['inactive'] + (int) $redirects['totals']['non_301'],
            'body_cleanup_candidates' => (int) $cleanup['cleanup_candidates'],
            'author_mapping_conflicts' => count($authorConflicts),
            'imported_articles_without_author' => Article::query()
                ->whereNotNull('old_wordpress_id')
                ->whereNull('author_id')
                ->count(),
            'source_categories_without_records' => $sourceCategoryIds->diff($categoryRecordAudit['wordpress_ids'])->count(),
            'source_tags_without_records' => $sourceTagIds->diff($tagRecordAudit['wordpress_ids'])->count(),
            'category_records_without_source' => $categoryRecordsWithoutSource->count(),
            'tag_records_without_source' => $tagRecordsWithoutSource->count(),
            'invalid_category_import_records' => count($categoryRecordAudit['invalid_records']),
            'invalid_tag_import_records' => count($tagRecordAudit['invalid_records']),
            'slug_changes' => count($slugChanges),
            'source_publication_mismatches' => count($parity['publication']),
            'source_freshness_mismatches' => count($parity['freshness']),
        ];

        return [
            'ready' => $this->isReady($gaps),
            'source' => [
                'available' => $source['available'],
                'base_url' => $baseUrl,
                'posts' => $source['posts_count'],
                'pages_scanned' => $source['pages_scanned'],
                'total_pages' => $source['total_pages'],
                'categories' => $source['categories'],
                'tags' => $source['tags'],
                'status_scope' => $source['status_scope'],
                'scan_complete' => $source['scan_complete'],
                'post_scan_complete' => $source['post_scan_complete'],
                'taxonomy_scan_complete' => $source['taxonomy_scan_complete'],
                'expected_posts' => $source['expected_posts'],
                'unique_posts' => $source['unique_posts'],
                'total_posts_header_present' => $source['total_posts_header_present'],
                'pagination_metadata_consistent' => $source['pagination_metadata_consistent'],
                'category_pages_scanned' => $source['category_pages_scanned'],
                'category_total_pages' => $source['category_total_pages'],
                'expected_categories' => $source['expected_categories'],
                'unique_categories' => $source['unique_categories'],
                'category_total_header_present' => $source['category_total_header_present'],
                'category_total_pages_header_present' => $source['category_total_pages_header_present'],
                'category_pagination_metadata_consistent' => $source['category_pagination_metadata_consistent'],
                'category_cardinality_consistent' => $source['category_cardinality_consistent'],
                'tag_pages_scanned' => $source['tag_pages_scanned'],
                'tag_total_pages' => $source['tag_total_pages'],
                'expected_tags' => $source['expected_tags'],
                'unique_tags' => $source['unique_tags'],
                'tag_total_header_present' => $source['tag_total_header_present'],
                'tag_total_pages_header_present' => $source['tag_total_pages_header_present'],
                'tag_pagination_metadata_consistent' => $source['tag_pagination_metadata_consistent'],
                'tag_cardinality_consistent' => $source['tag_cardinality_consistent'],
                'normalization_preview' => $source['normalization_preview'],
                'normalization_expected' => $sourceNormalizationExpected,
                'decision_normalization_evidence' => $decisionNormalizationEvidence,
                'normalization_matches_persisted' => $sourceNormalizationMatches,
                'published_posts' => collect($source['parity_posts'])
                    ->where('source_status', 'publish')
                    ->count(),
            ],
            'local' => [
                'imported_articles' => $importedArticleIds->count(),
                'post_records' => [
                    'total' => (int) $migration['posts']['total'],
                    'imported' => (int) $migration['posts']['imported'],
                    'failed' => (int) $migration['posts']['failed'],
                    'pending' => (int) $migration['posts']['pending'],
                    'skipped' => (int) $migration['posts']['skipped'],
                ],
                'author_records' => $this->recordCount('author'),
                'category_records' => $this->recordCount('category'),
                'tag_records' => $this->recordCount('tag'),
                'authors' => Author::query()->count(),
                'categories' => Category::query()->count(),
                'tags' => Tag::query()->count(),
                'persisted_normalizations' => $sourcePersistedNormalizations,
                'global_persisted_normalizations' => $migration['normalizations'],
                'approved_unavailable_media' => $migration['approved_unavailable_media'],
            ],
            'body_cleanup' => [
                'cleanup_candidates' => (int) $cleanup['cleanup_candidates'],
                'prospective_normalizations' => $cleanup['normalizations'],
            ],
            'gaps' => $gaps,
            'samples' => [
                'safe_import_missing_ids' => array_slice($safeImportMissingIds, 0, 50),
                'recorded_not_imported' => array_slice($recordedNotImported, 0, 25),
                'slug_changes' => array_slice($slugChanges, 0, 25),
                'body_cleanup_candidates' => array_slice($cleanup['samples'], 0, 25),
                'author_mapping_conflicts' => array_slice($authorConflicts, 0, 25),
                'category_records_without_source' => $categoryRecordsWithoutSource->take(25)->all(),
                'tag_records_without_source' => $tagRecordsWithoutSource->take(25)->all(),
                'invalid_category_import_records' => array_slice($categoryRecordAudit['invalid_records'], 0, 25),
                'invalid_tag_import_records' => array_slice($tagRecordAudit['invalid_records'], 0, 25),
                'missing_redirects' => array_slice($redirects['missing'], 0, 25),
                'source_publication_mismatches' => array_slice($parity['publication'], 0, 25),
                'source_freshness_mismatches' => array_slice($parity['freshness'], 0, 25),
                'source_content_manual_review' => collect($source['posts'])
                    ->filter(fn (array $post): bool => (bool) data_get($post, 'content_preview.manual_review_required', false))
                    ->take(25)
                    ->map(fn (array $post): array => [
                        'wordpress_id' => $post['wordpress_id'] ?? null,
                        'slug' => $post['slug'] ?? null,
                        'warnings' => data_get($post, 'content_preview.manual_review_warnings', []),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function localAudit(): array
    {
        return $this->audit('');
    }

    /**
     * @return array{
     *     articles_checked: int,
     *     cleanup_candidates: int,
     *     cleaned_articles: int,
     *     warnings_added: int,
     *     normalizations: array<string, int>,
     *     samples: array<int, array<string, mixed>>
     * }
     */
    public function cleanImportedArticleBodies(bool $apply = false, ?int $limit = null): array
    {
        $summary = [
            'articles_checked' => 0,
            'cleanup_candidates' => 0,
            'cleaned_articles' => 0,
            'warnings_added' => 0,
            'normalizations' => $this->emptyNormalizationSummary(),
            'samples' => [],
        ];

        $query = Article::query()
            ->select(['id', 'old_wordpress_id', 'slug', 'body_html'])
            ->whereNotNull('old_wordpress_id')
            ->whereNotNull('body_html')
            ->with('wordpressImportRecord')
            ->orderBy('id');

        $processedArticles = 0;

        $query->chunkById(100, function ($articles) use ($apply, $limit, &$processedArticles, &$summary): bool {
            foreach ($articles as $article) {
                if ($limit !== null && $processedArticles >= max(1, $limit)) {
                    return false;
                }

                $processedArticles++;
                $summary['articles_checked']++;
                $cleaned = $this->contentSanitizer->preview($article->body_html);
                $cleanHtml = $cleaned['html'];

                if ($cleanHtml === $article->body_html) {
                    continue;
                }

                if ($this->hasValidCurrentReconciliationMaterialization($article)) {
                    continue;
                }

                $summary['cleanup_candidates']++;
                $this->addNormalizationCounts(
                    $summary['normalizations'],
                    $cleaned['normalization_counts'],
                );

                $summary['samples'][] = [
                    'article_id' => $article->id,
                    'wordpress_id' => $article->old_wordpress_id,
                    'slug' => $article->slug,
                    'warnings' => $cleaned['warnings'],
                ];

                if (! $apply) {
                    continue;
                }

                $article->forceFill(['body_html' => $cleanHtml])->save();
                $summary['cleaned_articles']++;
                $record = $article->wordpressImportRecord()->first();
                $summary['warnings_added'] += $this->mergeRecordWarnings($record, $cleaned['warnings']);
                $this->mergeNormalizationCounts($record, $cleaned['normalization_counts']);
            }

            return true;
        });

        return $summary;
    }

    private function hasValidCurrentReconciliationMaterialization(Article $article): bool
    {
        $record = $article->wordpressImportRecord;

        if (! $record instanceof WordpressImportRecord
            || $record->wordpress_type !== 'post'
            || $record->status !== 'imported'
            || (int) $record->wordpress_id !== (int) $article->old_wordpress_id) {
            return false;
        }

        $payload = is_array($record->source_payload) ? $record->source_payload : [];
        $warnings = is_array($record->warnings) ? $record->warnings : [];
        $counts = data_get($payload, '_razbudise.normalization_counts', []);
        $counts = is_array($counts) ? $counts : [];

        return $this->contentDecisionProvenance->hasValidReconciliationPayload($payload)
            && $this->contentDecisionProvenance->hasValidMaterialization(
                $payload,
                (string) $article->body_html,
                $warnings,
                $counts,
            );
    }

    private function baseUrl(?string $baseUrl): ?string
    {
        $baseUrl ??= config('services.wordpress.base_url');

        return is_string($baseUrl) && trim($baseUrl) !== '' ? trim($baseUrl) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceSummary(?string $baseUrl, int $perPage, ?int $maxPages): array
    {
        if ($baseUrl === null) {
            $storedPosts = $this->storedSourcePostSummaries();

            return [
                'available' => false,
                'posts_count' => 0,
                'pages_scanned' => 0,
                'total_pages' => 0,
                'categories' => 0,
                'tags' => 0,
                'post_ids' => [],
                'category_ids' => [],
                'tag_ids' => [],
                'posts' => [],
                'parity_posts' => $storedPosts,
                'status_scope' => 'stored',
                'scan_complete' => null,
                'post_scan_complete' => null,
                'taxonomy_scan_complete' => null,
                'expected_posts' => null,
                'unique_posts' => count($storedPosts),
                'total_posts_header_present' => null,
                'pagination_metadata_consistent' => null,
                'category_pages_scanned' => 0,
                'category_total_pages' => 0,
                'expected_categories' => null,
                'unique_categories' => 0,
                'category_total_header_present' => null,
                'category_total_pages_header_present' => null,
                'category_pagination_metadata_consistent' => null,
                'category_cardinality_consistent' => null,
                'tag_pages_scanned' => 0,
                'tag_total_pages' => 0,
                'expected_tags' => null,
                'unique_tags' => 0,
                'tag_total_header_present' => null,
                'tag_total_pages_header_present' => null,
                'tag_pagination_metadata_consistent' => null,
                'tag_cardinality_consistent' => null,
                'normalization_preview' => null,
            ];
        }

        $posts = $this->importer->dryRunPosts($baseUrl, $perPage, $maxPages);
        $taxonomies = $this->importer->taxonomyInventory($baseUrl, maxPages: $maxPages);

        return [
            'available' => true,
            'posts_count' => (int) $posts['summary']['posts'],
            'pages_scanned' => $posts['pages_scanned'],
            'total_pages' => $posts['total_pages'],
            'status_scope' => $posts['status_scope'],
            'scan_complete' => $posts['scan_complete'] && $taxonomies['scan_complete'],
            'post_scan_complete' => $posts['scan_complete'],
            'taxonomy_scan_complete' => $taxonomies['scan_complete'],
            'expected_posts' => $posts['expected_posts'],
            'unique_posts' => $posts['unique_posts'],
            'total_posts_header_present' => $posts['total_posts_header_present'],
            'pagination_metadata_consistent' => $posts['pagination_metadata_consistent'],
            'category_pages_scanned' => $taxonomies['category_pages_scanned'],
            'category_total_pages' => $taxonomies['category_total_pages'],
            'expected_categories' => $taxonomies['expected_categories'],
            'unique_categories' => $taxonomies['unique_categories'],
            'category_total_header_present' => $taxonomies['category_total_header_present'],
            'category_total_pages_header_present' => $taxonomies['category_total_pages_header_present'],
            'category_pagination_metadata_consistent' => $taxonomies['category_pagination_metadata_consistent'],
            'category_cardinality_consistent' => $taxonomies['category_cardinality_consistent'],
            'tag_pages_scanned' => $taxonomies['tag_pages_scanned'],
            'tag_total_pages' => $taxonomies['tag_total_pages'],
            'expected_tags' => $taxonomies['expected_tags'],
            'unique_tags' => $taxonomies['unique_tags'],
            'tag_total_header_present' => $taxonomies['tag_total_header_present'],
            'tag_total_pages_header_present' => $taxonomies['tag_total_pages_header_present'],
            'tag_pagination_metadata_consistent' => $taxonomies['tag_pagination_metadata_consistent'],
            'tag_cardinality_consistent' => $taxonomies['tag_cardinality_consistent'],
            'normalization_preview' => $posts['normalization_preview'],
            'categories' => $taxonomies['categories'],
            'tags' => $taxonomies['tags'],
            'post_ids' => collect($posts['posts'])->pluck('wordpress_id')->map(fn (mixed $id): int => (int) $id)->all(),
            'category_ids' => $taxonomies['category_ids'],
            'tag_ids' => $taxonomies['tag_ids'],
            'posts' => $posts['posts'],
            'parity_posts' => $posts['posts'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourcePosts
     * @return array{publication: array<int, array<string, mixed>>, freshness: array<int, array<string, mixed>>}
     */
    private function sourceParity(array $sourcePosts, bool $sourceAvailable): array
    {
        $sourceById = collect($sourcePosts)
            ->filter(fn (array $post): bool => (int) ($post['wordpress_id'] ?? 0) > 0)
            ->keyBy(fn (array $post): int => (int) $post['wordpress_id']);
        $records = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->get(['wordpress_id', 'source_payload'])
            ->keyBy('wordpress_id');
        $articles = Article::query()
            ->whereNotNull('old_wordpress_id')
            ->get(['id', 'old_wordpress_id', 'slug', 'status', 'published_at', 'scheduled_at'])
            ->keyBy('old_wordpress_id');
        $ids = $sourceById->keys();

        if ($sourceAvailable) {
            $ids = $ids->merge($articles->keys());
        }

        $publication = [];
        $freshness = [];

        foreach ($ids->map(fn (mixed $id): int => (int) $id)->filter()->unique()->sort()->values() as $wordpressId) {
            $source = $sourceById->get($wordpressId);
            $record = $records->get($wordpressId);
            $article = $articles->get($wordpressId);
            $sourceStatus = is_array($source) ? (string) ($source['source_status'] ?? '') : null;
            $sourceIsPublished = $sourceStatus === 'publish';
            $localIsPublic = $article instanceof Article && $this->isPublicArticle($article);
            $publicationReasons = [];

            if ($sourceIsPublished && ! $localIsPublic) {
                $publicationReasons[] = ! $article instanceof Article
                    ? 'missing_local_article'
                    : $this->localPublicationFailure($article);
            }

            if ($sourceAvailable && ! $sourceIsPublished && $localIsPublic) {
                $publicationReasons[] = $source === null
                    ? 'local_public_article_absent_from_source'
                    : 'local_public_article_not_public_in_source';
            }

            if ($publicationReasons !== []) {
                $publication[] = $this->parityRow(
                    $wordpressId,
                    $source,
                    $article instanceof Article ? $article : null,
                    $record?->source_payload,
                    $publicationReasons,
                );
            }

            if (! $sourceAvailable || ! is_array($source)) {
                continue;
            }

            $freshnessReasons = [];
            $expectedLocalStatus = $this->mapSourceStatus($sourceStatus ?? 'draft');

            if ($article instanceof Article && $article->status !== $expectedLocalStatus) {
                $freshnessReasons[] = 'local_status_differs_from_source';
            }

            if ($sourceStatus === 'publish'
                && $article instanceof Article
                && $this->normalizedTimestamp($source['source_published_at'] ?? null)
                    !== $this->normalizedTimestamp($article->published_at?->toIso8601String())) {
                $freshnessReasons[] = 'local_published_at_differs_from_source';
            }

            if ($sourceStatus === 'future'
                && $article instanceof Article
                && $this->normalizedTimestamp($source['source_published_at'] ?? null)
                    !== $this->normalizedTimestamp($article->scheduled_at?->toIso8601String())) {
                $freshnessReasons[] = 'local_scheduled_at_differs_from_source';
            }

            $storedPayload = is_array($record?->source_payload) ? $record->source_payload : null;

            if ($storedPayload !== null) {
                if ((string) ($storedPayload['status'] ?? '') !== $sourceStatus) {
                    $freshnessReasons[] = 'source_status_changed_since_import';
                }

                if ($this->normalizedTimestamp($storedPayload['modified_gmt'] ?? null)
                    !== $this->normalizedTimestamp($source['source_modified_at'] ?? null)) {
                    $freshnessReasons[] = 'source_modified_since_import';
                }
            }

            if ($freshnessReasons !== []) {
                $freshness[] = $this->parityRow(
                    $wordpressId,
                    $source,
                    $article instanceof Article ? $article : null,
                    $storedPayload,
                    $freshnessReasons,
                );
            }
        }

        return ['publication' => $publication, 'freshness' => $freshness];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedSourcePostSummaries(): array
    {
        $posts = [];

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->whereNotNull('source_payload')
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$posts): void {
                foreach ($records as $record) {
                    $payload = is_array($record->source_payload) ? $record->source_payload : [];
                    $sourceStatus = (string) ($payload['status'] ?? 'draft');

                    $posts[] = [
                        'wordpress_id' => (int) $record->wordpress_id,
                        'slug' => (string) ($payload['slug'] ?? ''),
                        'source_status' => $sourceStatus,
                        'status' => $this->mapSourceStatus($sourceStatus),
                        'source_published_at' => $this->normalizedTimestamp($payload['date_gmt'] ?? null),
                        'source_modified_at' => $this->normalizedTimestamp($payload['modified_gmt'] ?? null),
                        'old_url' => $payload['link'] ?? $record->old_url,
                    ];
                }
            });

        return $posts;
    }

    private function isPublicArticle(Article $article): bool
    {
        return $article->status === 'published'
            && $article->published_at !== null
            && $article->published_at->lte(now());
    }

    private function localPublicationFailure(Article $article): string
    {
        if ($article->status !== 'published') {
            return 'local_status_not_published';
        }

        if ($article->published_at === null) {
            return 'local_published_at_missing';
        }

        return 'local_published_at_in_future';
    }

    private function mapSourceStatus(string $status): string
    {
        return match ($status) {
            'publish' => 'published',
            'future' => 'scheduled',
            'pending' => 'review',
            'trash' => 'archived',
            default => 'draft',
        };
    }

    private function normalizedTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $source
     * @param  array<string, mixed>|null  $storedPayload
     * @param  array<int, string>  $reasons
     * @return array<string, mixed>
     */
    private function parityRow(
        int $wordpressId,
        ?array $source,
        ?Article $article,
        ?array $storedPayload,
        array $reasons,
    ): array {
        return [
            'wordpress_id' => $wordpressId,
            'article_id' => $article?->id,
            'slug' => $article?->slug ?? ($source['slug'] ?? null),
            'source_status' => $source['source_status'] ?? null,
            'local_status' => $article?->status,
            'local_published_at' => $article?->published_at?->toIso8601String(),
            'local_scheduled_at' => $article?->scheduled_at?->toIso8601String(),
            'source_published_at' => $source['source_published_at'] ?? null,
            'source_modified_at' => $source['source_modified_at'] ?? null,
            'imported_source_modified_at' => $this->normalizedTimestamp($storedPayload['modified_gmt'] ?? null),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function importedArticleIds(): Collection
    {
        return Article::query()
            ->whereNotNull('old_wordpress_id')
            ->pluck('old_wordpress_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function postRecordIds(): Collection
    {
        return WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->pluck('wordpress_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, int>  $sourceIds
     * @return array<int, array<string, mixed>>
     */
    private function recordedNotImportedRows(Collection $sourceIds): array
    {
        if ($sourceIds->isEmpty()) {
            return [];
        }

        return WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->whereIn('wordpress_id', $sourceIds->all())
            ->where('status', '!=', 'imported')
            ->orderBy('wordpress_id')
            ->get()
            ->map(fn (WordpressImportRecord $record): array => [
                'wordpress_id' => $record->wordpress_id,
                'status' => $record->status,
                'old_url' => $record->old_url,
                'error_message' => $record->error_message,
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourcePosts
     * @return array<int, array<string, mixed>>
     */
    private function slugChanges(array $sourcePosts): array
    {
        $articles = Article::query()
            ->whereIn('old_wordpress_id', collect($sourcePosts)->pluck('wordpress_id')->all())
            ->get(['id', 'old_wordpress_id', 'slug'])
            ->keyBy('old_wordpress_id');

        return collect($sourcePosts)
            ->map(function (array $post) use ($articles): ?array {
                $sourceSlug = (string) ($post['slug'] ?? '');
                $article = $articles->get((int) ($post['wordpress_id'] ?? 0));

                if (! $article || $sourceSlug === '' || $article->slug === $sourceSlug) {
                    return null;
                }

                return [
                    'wordpress_id' => (int) $post['wordpress_id'],
                    'source_slug' => $sourceSlug,
                    'local_slug' => $article->slug,
                    'article_id' => $article->id,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function authorMappingConflicts(): array
    {
        $groups = [];

        WordpressImportRecord::query()
            ->select(['id', 'wordpress_id', 'importable_id', 'source_payload'])
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->where('importable_type', Article::class)
            ->whereNotNull('importable_id')
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$groups): void {
                $articles = Article::query()
                    ->whereIn('id', $records->pluck('importable_id')->filter()->unique()->values())
                    ->get(['id', 'author_id', 'slug'])
                    ->keyBy('id');

                foreach ($records as $record) {
                    $article = $articles->get((int) $record->importable_id);

                    if (! $article instanceof Article) {
                        continue;
                    }

                    $wordpressAuthorId = (int) (data_get($record->source_payload, '_embedded.author.0.id') ?: data_get($record->source_payload, 'author'));

                    if ($wordpressAuthorId <= 0) {
                        continue;
                    }

                    $groups[$wordpressAuthorId][] = [
                        'article_id' => $article->id,
                        'article_slug' => $article->slug,
                        'author_id' => $article->author_id,
                        'wordpress_id' => $record->wordpress_id,
                    ];
                }
            });

        return collect($groups)
            ->map(function (array $rows, int $wordpressAuthorId): ?array {
                $localAuthorIds = collect($rows)
                    ->pluck('author_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (count($localAuthorIds) <= 1) {
                    return null;
                }

                return [
                    'wordpress_author_id' => $wordpressAuthorId,
                    'local_author_ids' => $localAuthorIds,
                    'articles' => $rows,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function recordCount(string $wordpressType): int
    {
        return WordpressImportRecord::query()
            ->where('wordpress_type', $wordpressType)
            ->where('status', 'imported')
            ->count();
    }

    private function missingAuthorLegacyUrlCount(): int
    {
        return WordpressImportRecord::query()
            ->where('wordpress_type', 'author')
            ->where('status', 'imported')
            ->whereNotNull('new_url')
            ->where(function ($query): void {
                $query->whereNull('old_url')->orWhereRaw("TRIM(old_url) = ''");
            })
            ->count();
    }

    /**
     * @return array{
     *     wordpress_ids: Collection<int, int>,
     *     invalid_records: array<int, array<string, mixed>>
     * }
     */
    private function termRecordAudit(string $wordpressType): array
    {
        $modelClass = match ($wordpressType) {
            'category' => Category::class,
            'tag' => Tag::class,
            default => throw new \InvalidArgumentException("Unsupported WordPress term type: {$wordpressType}"),
        };
        $records = WordpressImportRecord::query()
            ->where('wordpress_type', $wordpressType)
            ->where('status', 'imported')
            ->get(['id', 'wordpress_id', 'wordpress_type', 'importable_type', 'importable_id', 'new_url']);
        $candidateTargetIds = $records
            ->where('importable_type', $modelClass)
            ->pluck('importable_id')
            ->filter(fn (mixed $id): bool => (int) $id > 0)
            ->unique()
            ->all();
        $existingTargetIds = $modelClass::query()
            ->whereIn('id', $candidateTargetIds)
            ->pluck('id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true]);
        $publicTargetIds = $wordpressType === 'category'
            ? Category::query()
                ->whereIn('id', $candidateTargetIds)
                ->where('is_active', true)
                ->pluck('id')
                ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
            : $existingTargetIds;
        $validRecords = $records->filter(
            fn (WordpressImportRecord $record): bool => (int) $record->wordpress_id > 0
                && $record->importable_type === $modelClass
                && (int) $record->importable_id > 0
                && $publicTargetIds->has((int) $record->importable_id),
        );
        $invalidRecords = $records
            ->reject(fn (WordpressImportRecord $record): bool => $validRecords->contains('id', $record->id))
            ->map(function (WordpressImportRecord $record) use ($modelClass, $existingTargetIds, $publicTargetIds, $wordpressType): array {
                $reason = match (true) {
                    (int) $record->wordpress_id <= 0 => 'invalid_wordpress_id',
                    $record->importable_type !== $modelClass => 'wrong_importable_type',
                    (int) $record->importable_id <= 0 => 'missing_importable_id',
                    ! $existingTargetIds->has((int) $record->importable_id) => 'dangling_importable_id',
                    $wordpressType === 'category' && ! $publicTargetIds->has((int) $record->importable_id) => 'inactive_category',
                    default => 'invalid_mapping',
                };

                return [
                    'record_id' => $record->id,
                    'wordpress_id' => (int) $record->wordpress_id,
                    'wordpress_type' => $record->wordpress_type,
                    'importable_type' => $record->importable_type,
                    'importable_id' => $record->importable_id,
                    'new_url' => $record->new_url,
                    'reason' => $reason,
                ];
            })
            ->values()
            ->all();

        return [
            'wordpress_ids' => $validRecords
                ->pluck('wordpress_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values(),
            'invalid_records' => $invalidRecords,
        ];
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function mergeRecordWarnings(?WordpressImportRecord $record, array $warnings): int
    {
        if (! $record || $warnings === []) {
            return 0;
        }

        $before = $record->warnings ?? [];
        $after = array_values(array_unique(array_merge($before, $warnings)));

        if ($after === $before) {
            return 0;
        }

        $record->forceFill(['warnings' => $after])->save();

        return count($after) - count($before);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function mergeNormalizationCounts(?WordpressImportRecord $record, array $counts): void
    {
        if (! $record || $counts === []) {
            return;
        }

        $payload = is_array($record->source_payload) ? $record->source_payload : [];
        $existing = data_get($payload, '_razbudise.normalization_counts', []);
        $existing = is_array($existing) ? $existing : [];

        foreach ($counts as $warning => $count) {
            $existing[$warning] = (int) ($existing[$warning] ?? 0) + max(0, (int) $count);
        }

        $payload['_razbudise']['normalization_counts'] = $existing;
        $record->forceFill(['source_payload' => $payload])->save();
    }

    /**
     * @param  Collection<int, int>  $sourceIds
     * @return array<string, int>
     */
    private function persistedNormalizationsForSourceIds(Collection $sourceIds): array
    {
        $summary = $this->emptyNormalizationSummary();

        foreach ($sourceIds->unique()->chunk(500) as $ids) {
            WordpressImportRecord::query()
                ->where('wordpress_type', 'post')
                ->where('status', 'imported')
                ->whereIn('wordpress_id', $ids->all())
                ->orderBy('id')
                ->chunkById(100, function ($records) use (&$summary): void {
                    foreach ($records as $record) {
                        $this->addPersistedNormalizationCounts(
                            $summary,
                            is_array($record->warnings) ? $record->warnings : [],
                            is_array(data_get($record->source_payload, '_razbudise.normalization_counts'))
                                ? data_get($record->source_payload, '_razbudise.normalization_counts')
                                : [],
                        );
                    }
                });
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return array<string, int>
     */
    private function normalizationCounts(array $counts): array
    {
        $normalized = $this->emptyNormalizationSummary();

        foreach ($normalized as $key => $zero) {
            $normalized[$key] = (int) ($counts[$key] ?? $zero);
        }

        return $normalized;
    }

    /**
     * @param  Collection<int, int>  $sourceIds
     * @return array{
     *     applicable: bool,
     *     valid: bool,
     *     expected: array<string, int>,
     *     verified_wordpress_ids: array<int, int>,
     *     errors: array<int, string>
     * }
     */
    private function decisionNormalizationEvidence(Collection $sourceIds): array
    {
        $result = $this->emptyDecisionNormalizationEvidence();
        $decisions = $this->importer->contentMigrationDecisions();

        if (! is_array($decisions)) {
            $result['valid'] = false;
            $result['errors'][] = 'content_migration_decisions_invalid';

            return $result;
        }

        $sourceIdSet = array_fill_keys(
            $sourceIds->unique()->map(fn (mixed $id): int => (int) $id)->all(),
            true,
        );
        $expectedById = [];

        foreach ($decisions['featured_image_fallbacks'] as $postId => $attachmentId) {
            if (! isset($sourceIdSet[$postId])) {
                continue;
            }

            $expectedById[$postId]['featured_image_fallback'] = $attachmentId;
            $result['expected']['trusted_featured_image_fallbacks_articles']++;
            $result['expected']['trusted_featured_image_fallbacks_elements']++;
        }

        foreach ($decisions['blob_image_removals'] as $postId => $decision) {
            if (! isset($sourceIdSet[$postId])) {
                continue;
            }

            $expectedById[$postId]['blob_image_removal'] = $decision;
            $result['expected']['trusted_blob_images_removed_articles']++;
            $result['expected']['trusted_blob_images_removed_elements'] +=
                (int) $decision['expected_blob_image_count'];
        }

        $unavailableRecords = collect();

        foreach ($sourceIds->unique()->chunk(500) as $ids) {
            $unavailableRecords = $unavailableRecords->merge(
                WordpressImportRecord::query()
                    ->where('wordpress_type', 'post')
                    ->where('status', 'imported')
                    ->whereIn('wordpress_id', $ids->all())
                    ->with('importable')
                    ->get(),
            );
        }

        $unavailableRecords = $unavailableRecords
            ->filter(function (WordpressImportRecord $record): bool {
                $payload = is_array($record->source_payload) ? $record->source_payload : [];
                $warnings = is_array($record->warnings) ? $record->warnings : [];
                $materializedWarnings = data_get(
                    $payload,
                    '_razbudise.frozen_materialization_provenance.warnings',
                    [],
                );
                $materializedWarnings = is_array($materializedWarnings)
                    ? $materializedWarnings
                    : [];
                $trustedWarnings = [
                    'trusted_unavailable_featured_media',
                    'trusted_unavailable_inline_media',
                ];

                return is_array(data_get($payload, '_razbudise.unavailable_media'))
                    || array_intersect($trustedWarnings, $warnings) !== []
                    || array_intersect($trustedWarnings, $materializedWarnings) !== []
                    || (is_array(data_get(
                        $payload,
                        '_razbudise.frozen_reconciliation_provenance',
                    )) && ! $this->contentDecisionProvenance
                        ->hasValidReconciliationPayload($payload));
            })
            ->values();

        $result['applicable'] = $expectedById !== [] || $unavailableRecords->isNotEmpty();

        $records = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->whereIn('wordpress_id', array_keys($expectedById))
            ->get()
            ->keyBy('wordpress_id');

        foreach ($expectedById as $postId => $expected) {
            $record = $records->get($postId);

            if (! $record instanceof WordpressImportRecord) {
                $result['errors'][] = "{$postId}:imported_record_missing";

                continue;
            }

            $payload = is_array($record->source_payload) ? $record->source_payload : [];
            $warnings = is_array($record->warnings) ? $record->warnings : [];
            $counts = data_get($payload, '_razbudise.normalization_counts', []);
            $counts = is_array($counts) ? $counts : [];
            $valid = true;

            if (! $this->contentDecisionProvenance->isValid($payload)) {
                $valid = false;
                $result['errors'][] = "{$postId}:frozen_content_decision_provenance_invalid";
            }

            if (isset($expected['featured_image_fallback'])) {
                $attachmentId = (int) $expected['featured_image_fallback'];
                $evidence = data_get($payload, '_razbudise.featured_image_fallback');
                $media = is_array($evidence) ? ($evidence['media'] ?? null) : null;

                if (! is_array($evidence)
                    || array_is_list($evidence)
                    || ! is_array($media)
                    || ($evidence['strategy'] ?? null) !== 'explicit_inline_attachment'
                    || (int) ($evidence['post_id'] ?? 0) !== $postId
                    || (int) ($evidence['attachment_id'] ?? 0) !== $attachmentId
                    || ($evidence['decision_sha256'] ?? null) !== $decisions['sha256']
                    || (int) ($media['id'] ?? 0) !== $attachmentId
                    || (int) ($media['post'] ?? 0) !== $postId
                    || ! str_starts_with(strtolower((string) ($media['mime_type'] ?? '')), 'image/')
                    || trim((string) ($media['source_url'] ?? '')) === ''
                    || (int) ($payload['featured_media'] ?? 0) !== 0
                    || ! $this->bodyReferencesAttachmentImage(
                        (string) data_get($payload, 'content.rendered', ''),
                        $attachmentId,
                        (string) $media['source_url'],
                    )
                    || (int) ($counts['trusted_featured_image_fallback'] ?? 0) !== 1
                    || ! in_array('trusted_featured_image_fallback', $warnings, true)) {
                    $valid = false;
                    $result['errors'][] = "{$postId}:featured_image_fallback_evidence_invalid";
                }
            }

            if (isset($expected['blob_image_removal'])) {
                $decision = $expected['blob_image_removal'];
                $evidence = data_get($payload, '_razbudise.blob_image_removal');
                $expectedCount = (int) $decision['expected_blob_image_count'];
                $preview = $this->contentSanitizer->preview(
                    data_get($payload, 'content.rendered'),
                    true,
                );

                if (! is_array($evidence)
                    || array_is_list($evidence)
                    || ($evidence['strategy'] ?? null) !== 'remove_nonpersistent_blob_images'
                    || (int) ($evidence['post_id'] ?? 0) !== $postId
                    || ($evidence['decision_sha256'] ?? null) !== $decisions['sha256']
                    || ($evidence['raw_post_content_sha256'] ?? null)
                        !== $decision['raw_post_content_sha256']
                    || (int) ($evidence['expected_blob_image_count'] ?? 0) !== $expectedCount
                    || (int) data_get(
                        $preview,
                        'normalization_counts.trusted_blob_image_removed',
                        0,
                    ) !== $expectedCount
                    || (int) ($counts['trusted_blob_image_removed'] ?? 0) !== $expectedCount
                    || ! in_array('trusted_blob_image_removed', $warnings, true)) {
                    $valid = false;
                    $result['errors'][] = "{$postId}:blob_image_removal_evidence_invalid";
                }
            }

            if ($valid) {
                $result['verified_wordpress_ids'][] = $postId;
            }
        }

        foreach ($unavailableRecords as $record) {
            $evidence = $this->verifiedUnavailableNormalizationEvidence($record);
            $postId = (int) $record->wordpress_id;

            if (! $evidence['valid']) {
                $result['errors'][] = "{$postId}:{$evidence['error']}";

                continue;
            }

            foreach ($evidence['expected'] as $key => $count) {
                $result['expected'][$key] += $count;
            }

            $result['verified_wordpress_ids'][] = $postId;
        }

        $result['verified_wordpress_ids'] = array_values(array_unique($result['verified_wordpress_ids']));
        sort($result['verified_wordpress_ids'], SORT_NUMERIC);
        $result['valid'] = $result['errors'] === [];

        return $result;
    }

    /**
     * @return array{
     *     applicable: bool,
     *     valid: bool,
     *     expected: array<string, int>,
     *     verified_wordpress_ids: array<int, int>,
     *     errors: array<int, string>
     * }
     */
    private function emptyDecisionNormalizationEvidence(): array
    {
        return [
            'applicable' => false,
            'valid' => true,
            'expected' => [
                'trusted_blob_images_removed_articles' => 0,
                'trusted_blob_images_removed_elements' => 0,
                'trusted_featured_image_fallbacks_articles' => 0,
                'trusted_featured_image_fallbacks_elements' => 0,
                'trusted_unavailable_featured_media_articles' => 0,
                'trusted_unavailable_featured_media_elements' => 0,
                'trusted_unavailable_inline_media_articles' => 0,
                'trusted_unavailable_inline_media_elements' => 0,
            ],
            'verified_wordpress_ids' => [],
            'errors' => [],
        ];
    }

    /**
     * @return array{valid: bool, expected: array<string, int>, error: string|null}
     */
    private function verifiedUnavailableNormalizationEvidence(WordpressImportRecord $record): array
    {
        $invalid = fn (string $error): array => [
            'valid' => false,
            'expected' => [],
            'error' => $error,
        ];
        $payload = is_array($record->source_payload) ? $record->source_payload : [];

        if (! $this->contentDecisionProvenance->hasValidReconciliationPayload($payload)) {
            return $invalid('frozen_reconciliation_provenance_invalid');
        }

        $article = $record->importable;

        if (! $article instanceof Article
            || (int) $article->old_wordpress_id !== (int) $record->wordpress_id) {
            return $invalid('unavailable_media_article_mapping_invalid');
        }

        $evidence = $this->importer->trustedUnavailableMediaEvidence($payload);

        if (! is_array($evidence)) {
            return $invalid('unavailable_media_evidence_invalid');
        }

        $warnings = is_array($record->warnings) ? $record->warnings : [];
        $counts = data_get($payload, '_razbudise.normalization_counts', []);
        $counts = is_array($counts) ? $counts : [];

        $hasFeaturedEvidence = is_array($evidence['featured'] ?? null);
        $hasInlineEvidence = is_array($evidence['inline'] ?? null);
        $hasFeaturedWarning = in_array('trusted_unavailable_featured_media', $warnings, true);
        $hasInlineWarning = in_array('trusted_unavailable_inline_media', $warnings, true);

        if ($hasFeaturedEvidence !== $hasFeaturedWarning) {
            return $invalid('unavailable_featured_media_warning_mismatch');
        }

        if ($hasInlineEvidence !== $hasInlineWarning) {
            return $invalid('unavailable_inline_media_warning_mismatch');
        }

        $expected = [];

        if ($hasFeaturedEvidence) {
            $count = $counts['trusted_unavailable_featured_media'] ?? null;

            if (! is_int($count) || $count !== 1) {
                return $invalid('unavailable_featured_media_count_mismatch');
            }

            $expected['trusted_unavailable_featured_media_articles'] = 1;
            $expected['trusted_unavailable_featured_media_elements'] = 1;
        }

        if ($hasInlineEvidence) {
            $occurrences = array_sum(array_map(
                fn (array $entry): int => (int) $entry['occurrences'],
                $evidence['inline'],
            ));
            $count = $counts['trusted_unavailable_inline_media'] ?? null;

            if (! is_int($count) || $count !== $occurrences) {
                return $invalid('unavailable_inline_media_count_mismatch');
            }

            $expected['trusted_unavailable_inline_media_articles'] = 1;
            $expected['trusted_unavailable_inline_media_elements'] = $occurrences;
        }

        if ($expected === []) {
            return $invalid('unavailable_media_evidence_empty');
        }

        if (! $this->contentDecisionProvenance->hasValidMaterialization(
            $payload,
            (string) $article->body_html,
            $warnings,
            $counts,
        )) {
            return $invalid('frozen_materialization_provenance_invalid');
        }

        return [
            'valid' => true,
            'expected' => $expected,
            'error' => null,
        ];
    }

    private function bodyReferencesAttachmentImage(
        string $html,
        int $attachmentId,
        string $sourceUrl,
    ): bool {
        if ($attachmentId < 1 || stripos($html, '<img') === false) {
            return false;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
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
                if (! $image instanceof \DOMElement) {
                    continue;
                }

                $classes = preg_split('/\s+/u', trim($image->getAttribute('class'))) ?: [];

                if (! in_array($expectedClass, $classes, true)) {
                    continue;
                }

                $candidates = [trim($image->getAttribute('src'))];

                foreach (explode(',', $image->getAttribute('srcset')) as $candidate) {
                    $parts = preg_split('/\s+/u', trim($candidate), 2) ?: [];
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

    /** @return array<string, int> */
    private function emptyNormalizationSummary(): array
    {
        $summary = [];

        foreach (self::NORMALIZATION_FAMILIES as $keys) {
            $summary[$keys['articles']] = 0;
            $summary[$keys['elements']] = 0;
        }

        return $summary;
    }

    /**
     * @param  array<string, int>  $summary
     * @param  array<string, int>  $counts
     */
    private function addNormalizationCounts(array &$summary, array $counts): void
    {
        foreach (self::NORMALIZATION_FAMILIES as $warning => $keys) {
            $count = max(0, (int) ($counts[$warning] ?? 0));

            if ($count > 0) {
                $summary[$keys['articles']]++;
                $summary[$keys['elements']] += $count;
            }
        }
    }

    /**
     * @param  array<string, int>  $summary
     * @param  array<int, string>  $warnings
     * @param  array<string, int>  $counts
     */
    private function addPersistedNormalizationCounts(array &$summary, array $warnings, array $counts): void
    {
        foreach (self::NORMALIZATION_FAMILIES as $warning => $keys) {
            if (in_array($warning, $warnings, true)) {
                $summary[$keys['articles']]++;
            }

            $summary[$keys['elements']] += max(0, (int) ($counts[$warning] ?? 0));
        }
    }

    /**
     * @param  array<string, int>  $gaps
     */
    private function isReady(array $gaps): bool
    {
        foreach ([
            'source_scan_incomplete',
            'source_normalization_mismatches',
            'missing_import_records',
            'not_imported_source_posts',
            'recorded_not_imported',
            'failed_import_records',
            'pending_import_records',
            'skipped_import_records',
            'missing_featured_images',
            'invalid_unavailable_media_evidence',
            'invalid_reconciliation_materializations',
            'missing_author_legacy_urls',
            'missing_redirects',
            'unsafe_redirects',
            'body_cleanup_candidates',
            'author_mapping_conflicts',
            'imported_articles_without_author',
            'source_categories_without_records',
            'source_tags_without_records',
            'category_records_without_source',
            'tag_records_without_source',
            'invalid_category_import_records',
            'invalid_tag_import_records',
            'source_publication_mismatches',
            'source_freshness_mismatches',
        ] as $blocker) {
            if (($gaps[$blocker] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }
}
