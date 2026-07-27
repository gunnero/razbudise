<?php

namespace App\Services\Wordpress;

use App\Models\Article;
use App\Models\WordpressImportRecord;

class WordpressMigrationReportService
{
    public function __construct(
        private readonly WordpressFrozenContentDecisionProvenance $contentDecisionProvenance,
        private readonly WordpressRestImporter $importer,
    ) {}

    /** @var array<int, string> */
    private const array REVIEW_WARNINGS = [
        'broken_embed',
        'featured_image_download_failed',
        'inline_image_download_failed',
        'manual_review',
        'missing_featured_image',
        'unsafe_html_removed',
        'unsupported_shortcode',
        'untrusted_iframe_removed',
    ];

    /** @var array<int, string> */
    private const array NORMALIZATION_WARNINGS = [
        'trusted_blob_image_removed',
        'trusted_divi_shortcode_normalized',
        'trusted_embed_script_removed',
        'trusted_empty_icon_removed',
        'trusted_featured_image_fallback',
        'trusted_unavailable_featured_media',
        'trusted_unavailable_inline_media',
        'trusted_iframe_hardened',
        'trusted_instagram_fallback_normalized',
        'trusted_malformed_link_normalized',
        'trusted_nested_link_normalized',
        'trusted_twitter_fallback_normalized',
        'trusted_tiktok_fallback_normalized',
        'trusted_wordpress_media_wrapper_normalized',
        'trusted_wordpress_interactive_normalized',
        'trusted_wordpress_oembed_normalized',
    ];

    /** @var array<int, string> */
    private const array EXACT_NORMALIZATION_COUNT_WARNINGS = [
        'trusted_unavailable_featured_media',
        'trusted_unavailable_inline_media',
    ];

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $postRecords = WordpressImportRecord::query()->where('wordpress_type', 'post');

        $latestFailures = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'failed')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (WordpressImportRecord $record): array => $this->recordSummary($record))
            ->all();

        $importedWithoutFeaturedImages = $this->countImportedArticlesWithoutFeaturedImages();
        $approvedUnavailableMedia = $this->approvedUnavailableMediaSummary();
        $invalidUnavailableMediaEvidence = $this->countInvalidUnavailableMediaEvidence();
        $invalidReconciliationMaterializations = $this->countInvalidReconciliationMaterializations();

        $reviewItems = $this->reviewItems();

        return [
            'posts' => [
                'total' => (clone $postRecords)->count(),
                'imported' => (clone $postRecords)->where('status', 'imported')->count(),
                'failed' => (clone $postRecords)->where('status', 'failed')->count(),
                'pending' => (clone $postRecords)->where('status', 'pending')->count(),
                'skipped' => (clone $postRecords)->where('status', 'skipped')->count(),
            ],
            'issues' => [
                'missing_featured_images' => $this->countWarning('post', 'missing_featured_image'),
                'featured_image_download_failures' => $this->countWarning('post', 'featured_image_download_failed'),
                'inline_image_download_failures' => $this->countWarning('post', 'inline_image_download_failed'),
                'broken_embeds' => $this->countWarning('post', 'broken_embed'),
                'unsafe_html_removed' => $this->countWarning('post', 'unsafe_html_removed'),
                'untrusted_iframes_removed' => $this->countWarning('post', 'untrusted_iframe_removed'),
                'manual_review' => $this->countUnresolvedManualReviewWarnings(),
                'unmapped_authors' => WordpressImportRecord::query()
                    ->where('wordpress_type', 'author')
                    ->where(function ($query): void {
                        $query
                            ->whereJsonContains('warnings', 'unmapped_author')
                            ->orWhere('status', 'pending');
                    })
                    ->count(),
                'imported_articles_without_featured_images' => $importedWithoutFeaturedImages,
                'invalid_unavailable_media_evidence' => $invalidUnavailableMediaEvidence,
                'invalid_reconciliation_materializations' => $invalidReconciliationMaterializations,
                'review_queue' => $this->reviewIssueCount(),
                'review_items_sampled' => count($reviewItems),
            ],
            'normalizations' => [
                'trusted_blob_images_removed_articles' => $this->countWarning('post', 'trusted_blob_image_removed'),
                'trusted_blob_images_removed_elements' => $this->sumNormalizationCount('trusted_blob_image_removed'),
                'trusted_divi_shortcodes_normalized_articles' => $this->countWarning('post', 'trusted_divi_shortcode_normalized'),
                'trusted_divi_shortcodes_normalized_elements' => $this->sumNormalizationCount('trusted_divi_shortcode_normalized'),
                'trusted_embed_scripts_removed_articles' => $this->countWarning('post', 'trusted_embed_script_removed'),
                'trusted_embed_scripts_removed_elements' => $this->sumNormalizationCount('trusted_embed_script_removed'),
                'trusted_empty_icons_removed_articles' => $this->countWarning('post', 'trusted_empty_icon_removed'),
                'trusted_empty_icons_removed_elements' => $this->sumNormalizationCount('trusted_empty_icon_removed'),
                'trusted_featured_image_fallbacks_articles' => $this->countWarning('post', 'trusted_featured_image_fallback'),
                'trusted_featured_image_fallbacks_elements' => $this->sumNormalizationCount('trusted_featured_image_fallback'),
                'trusted_unavailable_featured_media_articles' => $this->countWarning('post', 'trusted_unavailable_featured_media'),
                'trusted_unavailable_featured_media_elements' => $this->sumNormalizationCount('trusted_unavailable_featured_media'),
                'trusted_unavailable_inline_media_articles' => $this->countWarning('post', 'trusted_unavailable_inline_media'),
                'trusted_unavailable_inline_media_elements' => $this->sumNormalizationCount('trusted_unavailable_inline_media'),
                'trusted_iframes_hardened_articles' => $this->countWarning('post', 'trusted_iframe_hardened'),
                'trusted_iframes_hardened_elements' => $this->sumNormalizationCount('trusted_iframe_hardened'),
                'trusted_instagram_fallbacks_normalized_articles' => $this->countWarning('post', 'trusted_instagram_fallback_normalized'),
                'trusted_instagram_fallbacks_normalized_elements' => $this->sumNormalizationCount('trusted_instagram_fallback_normalized'),
                'trusted_malformed_links_normalized_articles' => $this->countWarning('post', 'trusted_malformed_link_normalized'),
                'trusted_malformed_links_normalized_elements' => $this->sumNormalizationCount('trusted_malformed_link_normalized'),
                'trusted_nested_links_normalized_articles' => $this->countWarning('post', 'trusted_nested_link_normalized'),
                'trusted_nested_links_normalized_elements' => $this->sumNormalizationCount('trusted_nested_link_normalized'),
                'trusted_twitter_fallbacks_normalized_articles' => $this->countWarning('post', 'trusted_twitter_fallback_normalized'),
                'trusted_twitter_fallbacks_normalized_elements' => $this->sumNormalizationCount('trusted_twitter_fallback_normalized'),
                'trusted_tiktok_fallbacks_normalized_articles' => $this->countWarning('post', 'trusted_tiktok_fallback_normalized'),
                'trusted_tiktok_fallbacks_normalized_elements' => $this->sumNormalizationCount('trusted_tiktok_fallback_normalized'),
                'trusted_wordpress_interactive_normalized_articles' => $this->countWarning('post', 'trusted_wordpress_interactive_normalized'),
                'trusted_wordpress_interactive_normalized_elements' => $this->sumNormalizationCount('trusted_wordpress_interactive_normalized'),
                'trusted_wordpress_media_wrappers_normalized_articles' => $this->countWarning('post', 'trusted_wordpress_media_wrapper_normalized'),
                'trusted_wordpress_media_wrappers_normalized_elements' => $this->sumNormalizationCount('trusted_wordpress_media_wrapper_normalized'),
                'trusted_wordpress_oembed_normalized_articles' => $this->countWarning('post', 'trusted_wordpress_oembed_normalized'),
                'trusted_wordpress_oembed_normalized_elements' => $this->sumNormalizationCount('trusted_wordpress_oembed_normalized'),
            ],
            'approved_unavailable_media' => $approvedUnavailableMedia,
            'latest_failures' => $latestFailures,
            'review_items' => $reviewItems,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reviewItems(): array
    {
        $items = [];

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'failed')
            ->latest()
            ->limit(50)
            ->get()
            ->each(function (WordpressImportRecord $record) use (&$items): void {
                $this->addReviewItem($items, $record, 'failed_import');
            });

        foreach (['featured_image_download_failed', 'unsafe_html_removed', 'untrusted_iframe_removed'] as $warning) {
            WordpressImportRecord::query()
                ->where('wordpress_type', 'post')
                ->where('status', 'imported')
                ->whereJsonContains('warnings', $warning)
                ->with('importable')
                ->latest()
                ->limit(25)
                ->get()
                ->each(function (WordpressImportRecord $record) use (&$items, $warning): void {
                    if ($record->importable instanceof Article) {
                        $this->addReviewItem($items, $record, $warning, $record->importable);
                    }
                });
        }

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->with('importable')
            ->latest()
            ->limit(100)
            ->get()
            ->each(function (WordpressImportRecord $record) use (&$items): void {
                if (! $record->importable instanceof Article) {
                    return;
                }

                foreach ($this->articleIssues($record, $record->importable) as $issue) {
                    $this->addReviewItem($items, $record, $issue, $record->importable);
                }
            });

        return array_slice(array_values($items), 0, 100);
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     */
    private function addReviewItem(array &$items, WordpressImportRecord $record, string $issue, ?Article $article = null): void
    {
        $items[$record->id.'|'.$issue] = $this->reviewItemFromRecord($record, $issue, $article);
    }

    /**
     * @return array<int, string>
     */
    private function articleIssues(WordpressImportRecord $record, Article $article): array
    {
        $issues = [];

        if (! $article->author_id) {
            $issues[] = 'missing_author';
        }

        if (! $article->category_id) {
            $issues[] = 'missing_category';
        }

        foreach ($record->warnings ?? [] as $warning) {
            if ($warning === 'manual_review' && $this->hasVerifiedManualReviewDecision($record)) {
                continue;
            }

            if (in_array($warning, self::REVIEW_WARNINGS, true)) {
                $issues[] = $warning;
            }
        }

        if (! $article->featured_image_id
            && $issues === []
            && ! $this->hasTrustedUnavailableFeaturedMedia($record, $article)
            && $this->sourceRequiresFeaturedImage($record, $article)) {
            $issues[] = 'missing_featured_image';
        }

        return array_values(array_unique($issues));
    }

    private function reviewIssueCount(): int
    {
        $count = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'failed')
            ->count();

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->with('importable')
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$count): void {
                foreach ($records as $record) {
                    if ($record->importable instanceof Article) {
                        $count += count($this->articleIssues($record, $record->importable));
                    }
                }
            });

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewItemFromRecord(WordpressImportRecord $record, string $issue, ?Article $article = null): array
    {
        return [
            'id' => $article?->id,
            'title' => $article?->title,
            'slug' => $article?->slug,
            'old_wordpress_id' => $record->wordpress_id,
            'old_url' => $record->old_url,
            'new_url' => $record->new_url,
            'status' => $record->status,
            'issue' => $issue,
            'warnings' => $record->warnings ?? [],
            'error_message' => $record->error_message,
        ];
    }

    private function countWarning(string $wordpressType, string $warning): int
    {
        return WordpressImportRecord::query()
            ->where('wordpress_type', $wordpressType)
            ->whereJsonContains('warnings', $warning)
            ->count();
    }

    private function countUnresolvedManualReviewWarnings(): int
    {
        $count = 0;

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->whereJsonContains('warnings', 'manual_review')
            ->with('importable')
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$count): void {
                foreach ($records as $record) {
                    if ($record->importable instanceof Article
                        && $record->importable->status === 'published'
                        && $record->importable->published_at?->lte(now())
                        && ! $this->hasVerifiedManualReviewDecision($record)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function hasVerifiedManualReviewDecision(WordpressImportRecord $record): bool
    {
        if (! $record->importable instanceof Article) {
            return false;
        }

        $payload = is_array($record->source_payload) ? $record->source_payload : [];
        $warnings = is_array($record->warnings) ? $record->warnings : [];
        $counts = data_get($payload, '_razbudise.normalization_counts', []);
        $counts = is_array($counts) ? $counts : [];
        $decisions = $this->importer->contentMigrationDecisions();
        $decision = is_array($decisions)
            ? data_get($decisions, 'blob_image_removals.'.(int) $record->wordpress_id)
            : null;
        $evidence = data_get($payload, '_razbudise.blob_image_removal');

        return is_array($decision)
            && is_array($evidence)
            && $this->contentDecisionProvenance->isValid($payload)
            && $this->contentDecisionProvenance->hasValidMaterialization(
                $payload,
                (string) $record->importable->body_html,
                $warnings,
                $counts,
            )
            && ($evidence['strategy'] ?? null) === 'remove_nonpersistent_blob_images'
            && (int) ($evidence['post_id'] ?? 0) === (int) $record->wordpress_id
            && ($evidence['decision_sha256'] ?? null) === ($decisions['sha256'] ?? null)
            && ($evidence['raw_post_content_sha256'] ?? null) === ($decision['raw_post_content_sha256'] ?? null)
            && (int) ($evidence['expected_blob_image_count'] ?? 0) === (int) ($decision['expected_blob_image_count'] ?? -1)
            && (int) ($counts['trusted_blob_image_removed'] ?? 0) === (int) ($decision['expected_blob_image_count'] ?? -1)
            && in_array('trusted_blob_image_removed', $warnings, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function reviewExportRows(): array
    {
        return collect($this->allReviewItems())
            ->map(fn (array $item): array => [
                'issue' => $item['issue'],
                'wordpress_id' => $item['old_wordpress_id'],
                'title' => $item['title'],
                'slug' => $item['slug'],
                'old_url' => $item['old_url'],
                'new_url' => $item['new_url'],
                'error_message' => $item['error_message'],
                'warnings' => implode(', ', $item['warnings'] ?? []),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalizationExportRows(): array
    {
        $rows = [];

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->where(function ($query): void {
                foreach (self::NORMALIZATION_WARNINGS as $index => $warning) {
                    $method = $index === 0 ? 'whereJsonContains' : 'orWhereJsonContains';
                    $query->{$method}('warnings', $warning);
                }
            })
            ->with('importable')
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$rows): void {
                foreach ($records as $record) {
                    $article = $record->importable instanceof Article ? $record->importable : null;

                    foreach (array_intersect($record->warnings ?? [], self::NORMALIZATION_WARNINGS) as $warning) {
                        $rows[] = [
                            'normalization' => $warning,
                            'elements' => $this->normalizationElementCount($record, $warning),
                            'wordpress_id' => $record->wordpress_id,
                            'title' => $article?->title,
                            'slug' => $article?->slug,
                            'old_url' => $record->old_url,
                            'new_url' => $record->new_url,
                        ];
                    }
                }
            });

        return $rows;
    }

    private function sumNormalizationCount(string $warning): int
    {
        $count = 0;

        $query = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->whereJsonContains('warnings', $warning);

        $query
            ->select(['id', 'source_payload'])
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$count, $warning): void {
                foreach ($records as $record) {
                    $count += $this->normalizationElementCount($record, $warning);
                }
            });

        return $count;
    }

    private function normalizationElementCount(WordpressImportRecord $record, string $warning): int
    {
        $count = max(0, (int) data_get(
            $record->source_payload,
            "_razbudise.normalization_counts.{$warning}",
            0,
        ));

        return in_array($warning, self::EXACT_NORMALIZATION_COUNT_WARNINGS, true)
            ? $count
            : max(1, $count);
    }

    private function countImportedArticlesWithoutFeaturedImages(): int
    {
        $count = 0;

        Article::query()
            ->whereNotNull('old_wordpress_id')
            ->whereNull('featured_image_id')
            ->with('wordpressImportRecord')
            ->orderBy('id')
            ->chunkById(100, function ($articles) use (&$count): void {
                foreach ($articles as $article) {
                    $record = $article->wordpressImportRecord;

                    if (! $this->hasTrustedUnavailableFeaturedMedia($record, $article)
                        && $this->sourceRequiresFeaturedImage($record, $article)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function sourceRequiresFeaturedImage(
        ?WordpressImportRecord $record,
        Article $article,
    ): bool {
        if ($record === null
            || $record->wordpress_type !== 'post'
            || $record->status !== 'imported'
            || (int) $record->wordpress_id !== (int) $article->old_wordpress_id) {
            return true;
        }

        $payload = is_array($record->source_payload) ? $record->source_payload : [];

        if ((int) ($payload['id'] ?? 0) !== (int) $record->wordpress_id
            || ! array_key_exists('featured_media', $payload)
            || ! is_int($payload['featured_media'])
            || $payload['featured_media'] < 0) {
            return true;
        }

        if (is_array(data_get($payload, '_razbudise.featured_image_fallback'))
            || in_array('trusted_featured_image_fallback', $record->warnings ?? [], true)) {
            return true;
        }

        return $payload['featured_media'] > 0;
    }

    private function hasTrustedUnavailableFeaturedMedia(
        ?WordpressImportRecord $record,
        Article $article,
    ): bool {
        return $this->trustedUnavailableMediaCounts($record, $article)['featured'] === 1;
    }

    /** @return array<string, int> */
    private function approvedUnavailableMediaSummary(): array
    {
        $summary = [
            'trusted_unavailable_featured_media_articles' => 0,
            'trusted_unavailable_featured_media_elements' => 0,
            'trusted_unavailable_inline_media_articles' => 0,
            'trusted_unavailable_inline_media_elements' => 0,
        ];

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->with('importable')
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$summary): void {
                foreach ($records as $record) {
                    if (! $record->importable instanceof Article) {
                        continue;
                    }

                    $counts = $this->trustedUnavailableMediaCounts($record, $record->importable);

                    if ($counts['featured'] > 0) {
                        $summary['trusted_unavailable_featured_media_articles']++;
                        $summary['trusted_unavailable_featured_media_elements'] += $counts['featured'];
                    }

                    if ($counts['inline'] > 0) {
                        $summary['trusted_unavailable_inline_media_articles']++;
                        $summary['trusted_unavailable_inline_media_elements'] += $counts['inline'];
                    }
                }
            });

        return $summary;
    }

    private function countInvalidUnavailableMediaEvidence(): int
    {
        $invalid = 0;

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->with('importable')
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$invalid): void {
                foreach ($records as $record) {
                    if (! $this->requiresUnavailableMediaValidation($record)) {
                        continue;
                    }

                    if (! $record->importable instanceof Article) {
                        $invalid++;

                        continue;
                    }

                    $counts = $this->trustedUnavailableMediaCounts($record, $record->importable);

                    if ($counts['featured'] === 0 && $counts['inline'] === 0) {
                        $invalid++;
                    }
                }
            });

        return $invalid;
    }

    private function countInvalidReconciliationMaterializations(): int
    {
        $invalid = 0;

        Article::query()
            ->whereNotNull('old_wordpress_id')
            ->with('wordpressImportRecord')
            ->orderBy('id')
            ->chunkById(100, function ($articles) use (&$invalid): void {
                foreach ($articles as $article) {
                    $record = $article->wordpressImportRecord;

                    if (! $record instanceof WordpressImportRecord
                        || $record->wordpress_type !== 'post'
                        || $record->status !== 'imported'
                        || (int) $record->wordpress_id !== (int) $article->old_wordpress_id) {
                        $invalid++;

                        continue;
                    }

                    $payload = is_array($record->source_payload) ? $record->source_payload : [];
                    $warnings = is_array($record->warnings) ? $record->warnings : [];
                    $counts = data_get($payload, '_razbudise.normalization_counts', []);
                    $counts = is_array($counts) ? $counts : [];

                    if (! $this->contentDecisionProvenance->hasValidReconciliationPayload($payload)
                        || ! $this->contentDecisionProvenance->hasValidMaterialization(
                            $payload,
                            (string) $article->body_html,
                            $warnings,
                            $counts,
                        )) {
                        $invalid++;
                    }
                }
            });

        return $invalid;
    }

    private function requiresUnavailableMediaValidation(WordpressImportRecord $record): bool
    {
        $payload = is_array($record->source_payload) ? $record->source_payload : [];
        $warnings = is_array($record->warnings) ? $record->warnings : [];
        $materializedWarnings = data_get(
            $payload,
            '_razbudise.frozen_materialization_provenance.warnings',
            [],
        );
        $materializedWarnings = is_array($materializedWarnings) ? $materializedWarnings : [];
        $trustedWarnings = [
            'trusted_unavailable_featured_media',
            'trusted_unavailable_inline_media',
        ];

        if (is_array(data_get($payload, '_razbudise.unavailable_media'))
            || array_intersect($trustedWarnings, $warnings) !== []
            || array_intersect($trustedWarnings, $materializedWarnings) !== []) {
            return true;
        }

        return is_array(data_get($payload, '_razbudise.frozen_reconciliation_provenance'))
            && ! $this->contentDecisionProvenance->hasValidReconciliationPayload($payload);
    }

    /** @return array{featured: int, inline: int} */
    private function trustedUnavailableMediaCounts(
        ?WordpressImportRecord $record,
        Article $article,
    ): array {
        $empty = ['featured' => 0, 'inline' => 0];

        if ($record === null
            || $record->wordpress_type !== 'post'
            || $record->status !== 'imported'
            || (int) $record->wordpress_id !== (int) $article->old_wordpress_id) {
            return $empty;
        }

        $payload = is_array($record->source_payload) ? $record->source_payload : [];

        if ((int) ($payload['id'] ?? 0) !== (int) $record->wordpress_id
            || ! $this->contentDecisionProvenance->hasValidReconciliationPayload($payload)) {
            return $empty;
        }

        $evidence = $this->importer->trustedUnavailableMediaEvidence($payload);

        if (! is_array($evidence)) {
            return $empty;
        }

        $featured = $evidence['featured'] ?? null;
        $inline = $evidence['inline'] ?? null;

        if ($featured === null && $inline === null) {
            return $empty;
        }

        $inlineOccurrences = is_array($inline)
            ? array_sum(array_map(fn (array $entry): int => $entry['occurrences'], $inline))
            : 0;

        $warnings = is_array($record->warnings) ? $record->warnings : [];
        $counts = data_get($payload, '_razbudise.normalization_counts', []);
        $counts = is_array($counts) ? $counts : [];

        if (! $this->contentDecisionProvenance->hasValidMaterialization(
            $payload,
            (string) $article->body_html,
            $warnings,
            $counts,
        )) {
            return $empty;
        }

        $hasFeaturedWarning = in_array('trusted_unavailable_featured_media', $warnings, true);
        $hasInlineWarning = in_array('trusted_unavailable_inline_media', $warnings, true);

        if (($featured !== null) !== $hasFeaturedWarning
            || ($inline !== null) !== $hasInlineWarning
            || ($hasFeaturedWarning
                && (! is_int($counts['trusted_unavailable_featured_media'] ?? null)
                    || $counts['trusted_unavailable_featured_media'] !== 1))
            || ($hasInlineWarning
                && (! is_int($counts['trusted_unavailable_inline_media'] ?? null)
                    || $counts['trusted_unavailable_inline_media'] !== $inlineOccurrences))) {
            return $empty;
        }

        return [
            'featured' => $hasFeaturedWarning ? 1 : 0,
            'inline' => $hasInlineWarning ? $inlineOccurrences : 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allReviewItems(): array
    {
        $items = [];

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'failed')
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$items): void {
                foreach ($records as $record) {
                    $items[] = $this->reviewItemFromRecord($record, 'failed_import');
                }
            });

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->with('importable')
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$items): void {
                foreach ($records as $record) {
                    if (! $record->importable instanceof Article) {
                        continue;
                    }

                    foreach ($this->articleIssues($record, $record->importable) as $issue) {
                        $items[] = $this->reviewItemFromRecord($record, $issue, $record->importable);
                    }
                }
            });

        return $items;
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
