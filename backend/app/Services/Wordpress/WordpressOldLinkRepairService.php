<?php

namespace App\Services\Wordpress;

use App\Models\Article;
use App\Models\MediaAsset;
use App\Models\WordpressImportRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class WordpressOldLinkRepairService
{
    public function __construct(private readonly WordpressContentSanitizer $contentSanitizer) {}

    public function hasLocalUploadReferences(Article $article): bool
    {
        return $this->referencesForArticle($article) !== [];
    }

    /**
     * @return array{
     *     articles_checked: int,
     *     articles_with_references: int,
     *     references_found: int,
     *     downloaded_media: int,
     *     reused_media: int,
     *     failed_downloads: int,
     *     rewritten_articles: int,
     *     shortcodes_removed: int,
     *     skipped_known_failed_articles: int,
     *     broken_sources: array<int, array<string, mixed>>,
     *     items: array<int, array<string, mixed>>
     * }
     */
    public function repair(
        bool $apply = false,
        ?int $limit = null,
        bool $skipKnownFailed = false,
        ?int $progressEvery = null,
        ?callable $onProgress = null,
    ): array {
        $summary = [
            'articles_checked' => 0,
            'articles_with_references' => 0,
            'references_found' => 0,
            'downloaded_media' => 0,
            'reused_media' => 0,
            'failed_downloads' => 0,
            'rewritten_articles' => 0,
            'shortcodes_removed' => 0,
            'skipped_known_failed_articles' => 0,
            'broken_sources' => [],
            'items' => [],
        ];

        $query = Article::query()
            ->whereNotNull('old_wordpress_id')
            ->whereNotNull('body_html')
            ->with('wordpressImportRecord')
            ->orderBy('id');

        if ($skipKnownFailed) {
            $summary['skipped_known_failed_articles'] = (clone $query)
                ->whereHas('wordpressImportRecord', fn ($recordQuery) => $recordQuery->whereJsonContains('warnings', 'old_link_repair_failed'))
                ->count();

            $query->whereDoesntHave(
                'wordpressImportRecord',
                fn ($recordQuery) => $recordQuery->whereJsonContains('warnings', 'old_link_repair_failed'),
            );
        }

        $limit = $limit === null ? null : max(1, $limit);
        $totalArticles = (clone $query)->count();
        $totalArticles = $limit === null ? $totalArticles : min($limit, $totalArticles);
        $processedArticles = 0;

        $query->chunkById(100, function ($articles) use ($apply, $limit, $progressEvery, $onProgress, $totalArticles, &$processedArticles, &$summary): bool {
            foreach ($articles as $article) {
                if ($limit !== null && $processedArticles >= $limit) {
                    return false;
                }

                $processedArticles++;
                $this->repairArticle($article, $apply, $progressEvery, $onProgress, $totalArticles, $summary);
            }

            return $limit === null || $processedArticles < $limit;
        });

        $summary['broken_sources'] = $this->brokenSources($summary['items']);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function repairArticle(
        Article $article,
        bool $apply,
        ?int $progressEvery,
        ?callable $onProgress,
        int $totalArticles,
        array &$summary,
    ): void {
        $summary['articles_checked']++;
        $references = $this->referencesForArticle($article);
        $body = (string) $article->body_html;
        $record = $article->wordpressImportRecord;
        $hasKnownRepairFailure = in_array('old_link_repair_failed', $record?->warnings ?? [], true);
        $changed = false;

        if ($references !== []) {
            $summary['articles_with_references']++;
        }

        $failedDownloadForArticle = false;

        foreach ($references as $reference) {
            $summary['references_found']++;
            $item = [
                'article_id' => $article->id,
                'wordpress_id' => $article->old_wordpress_id,
                'title' => $article->title,
                'slug' => $article->slug,
                'old_url' => $record?->old_url,
                'new_url' => $record?->new_url,
                'source_url' => $reference['source_url'],
                'literal' => $reference['literal'],
                'reason' => $hasKnownRepairFailure ? 'old_link_repair_failed' : 'unresolved_old_wordpress_upload',
                'status' => $hasKnownRepairFailure ? 'old_link_repair_failed' : 'pending',
                'message' => $hasKnownRepairFailure
                    ? 'Old WordPress upload repair previously failed; source link remains for manual review.'
                    : 'Old WordPress upload reference is unresolved.',
                'attempted_urls' => [],
                'failure_reasons' => [],
            ];

            if ($apply) {
                $media = $this->resolveReferenceMedia($reference);
                $item['attempted_urls'] = $media['attempted_urls'];
                $item['failure_reasons'] = $media['failure_reasons'];

                if ($media['asset'] instanceof MediaAsset) {
                    $asset = $media['asset'];
                    $body = str_replace($reference['literal'], (string) $asset->url, $body);
                    $changed = true;
                    $item['status'] = $media['existing'] ? 'reused' : 'repaired';
                    $item['reason'] = $media['existing'] ? 'existing_media_reused' : 'media_downloaded';
                    $item['message'] = $media['existing'] ? 'Existing downloaded media was reused.' : 'Media downloaded and article body rewritten.';
                    $item['media_asset_id'] = $asset->id;
                    $item['resolved_source_url'] = $media['resolved_source_url'];
                    $media['existing'] ? $summary['reused_media']++ : $summary['downloaded_media']++;
                } else {
                    $item['status'] = 'failed';
                    $item['reason'] = 'download_failed';
                    $item['message'] = 'Media could not be downloaded: '.$this->failureReasonSummary($media['failure_reasons']);
                    $summary['failed_downloads']++;
                    $failedDownloadForArticle = true;
                }
            }

            $summary['items'][] = $item;
        }

        if (str_contains(mb_strtolower($body), '[post_tags]')) {
            $body = preg_replace('/\[post_tags\]/i', '', $body) ?? $body;
            $changed = true;
            $summary['shortcodes_removed']++;
        }

        if ($apply && $changed && $body !== $article->body_html) {
            $article->forceFill(['body_html' => trim($body)])->save();
            $summary['rewritten_articles']++;
        }

        if ($apply && $failedDownloadForArticle) {
            $this->addRecordWarnings($article->wordpressImportRecord, ['old_link_repair_failed', 'manual_review']);
        } elseif ($apply && ! $this->containsOldUploadReference($body, $article->wordpressImportRecord)) {
            $this->clearRepairWarning($article->wordpressImportRecord);
        }

        if ($this->shouldReportProgress($summary['articles_checked'], $totalArticles, $progressEvery, $onProgress)) {
            $onProgress($this->progressPayload($article, $totalArticles, $summary));
        }
    }

    private function shouldReportProgress(int $articlesChecked, int $totalArticles, ?int $progressEvery, ?callable $onProgress): bool
    {
        if ($onProgress === null || $totalArticles === 0) {
            return false;
        }

        $progressEvery = max(1, (int) ($progressEvery ?? 50));

        return $articlesChecked % $progressEvery === 0 || $articlesChecked === $totalArticles;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function progressPayload(Article $article, int $totalArticles, array $summary): array
    {
        return [
            'article_id' => $article->id,
            'wordpress_id' => $article->old_wordpress_id,
            'slug' => $article->slug,
            'title' => $article->title,
            'articles_checked' => $summary['articles_checked'],
            'total_articles' => $totalArticles,
            'articles_with_references' => $summary['articles_with_references'],
            'references_found' => $summary['references_found'],
            'downloaded_media' => $summary['downloaded_media'],
            'reused_media' => $summary['reused_media'],
            'failed_downloads' => $summary['failed_downloads'],
            'rewritten_articles' => $summary['rewritten_articles'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(): array
    {
        return collect($this->repair(apply: false)['items'])
            ->map(fn (array $item): array => [
                'article_id' => $item['article_id'],
                'wordpress_id' => $item['wordpress_id'],
                'title' => $item['title'],
                'slug' => $item['slug'],
                'old_url' => $item['old_url'],
                'new_url' => $item['new_url'],
                'source_url' => $item['source_url'],
                'literal' => $item['literal'],
                'reason' => $item['reason'],
                'status' => $item['status'],
                'message' => $item['message'],
            ])
            ->all();
    }

    /**
     * @return array{article_id: int, media_asset_id: int, source_url: string, replacement_url: string, replaced_references: int, remaining_old_upload_references: bool}
     */
    public function replaceOldUploadReference(Article $article, string $oldUrl, MediaAsset $replacement): array
    {
        $article->loadMissing('wordpressImportRecord');

        $body = (string) $article->body_html;
        $record = $article->wordpressImportRecord;
        $targetSourceUrl = $this->canonicalUploadSourceUrl($this->absoluteSourceUrl($oldUrl, $record));
        $replacementUrl = $this->publicMediaUrl($replacement);
        $replacedReferences = 0;

        foreach ($this->referencesForArticle($article) as $reference) {
            if (
                $reference['source_url'] !== $targetSourceUrl
                && $reference['literal'] !== $oldUrl
                && ($reference['literal_source_url'] ?? null) !== $targetSourceUrl
            ) {
                continue;
            }

            $body = str_replace($reference['literal'], $replacementUrl, $body, $count);
            $replacedReferences += $count;
        }

        if ($replacedReferences > 0 && $body !== $article->body_html) {
            $article->forceFill(['body_html' => trim($body)])->save();
        }

        $remainingOldUploadReferences = $this->containsOldUploadReference($body, $record);

        if (! $remainingOldUploadReferences) {
            $this->clearRepairWarning($record);
        }

        return [
            'article_id' => $article->id,
            'media_asset_id' => $replacement->id,
            'source_url' => $targetSourceUrl,
            'replacement_url' => $replacementUrl,
            'replaced_references' => $replacedReferences,
            'remaining_old_upload_references' => $remainingOldUploadReferences,
        ];
    }

    /**
     * @return array{article_id: int, source_url: string, removed_references: int, remaining_old_upload_references: bool}
     */
    public function removeOldUploadReference(Article $article, string $oldUrl): array
    {
        $article->loadMissing('wordpressImportRecord');

        $body = (string) $article->body_html;
        $record = $article->wordpressImportRecord;
        $targetSourceUrl = $this->canonicalUploadSourceUrl($this->absoluteSourceUrl($oldUrl, $record));
        $removedReferences = 0;

        foreach ($this->referencesForArticle($article) as $reference) {
            if (
                $reference['source_url'] !== $targetSourceUrl
                && $reference['literal'] !== $oldUrl
                && ($reference['literal_source_url'] ?? null) !== $targetSourceUrl
            ) {
                continue;
            }

            [$body, $removed] = $this->removeReferenceLiteral($body, $reference['literal']);
            $removedReferences += $removed;
        }

        if ($removedReferences > 0 && $body !== $article->body_html) {
            $article->forceFill(['body_html' => trim($body)])->save();
        }

        $remainingOldUploadReferences = $this->containsOldUploadReference($body, $record);

        if (! $remainingOldUploadReferences) {
            $this->clearRepairWarning($record);
        }

        return [
            'article_id' => $article->id,
            'source_url' => $targetSourceUrl,
            'removed_references' => $removedReferences,
            'remaining_old_upload_references' => $remainingOldUploadReferences,
        ];
    }

    /**
     * @return array{records_checked: int, records_fixable: int, records_updated: int, skipped_with_old_upload_refs: int, dry_run: bool}
     */
    public function resolveMediaReviewWarnings(bool $apply = false): array
    {
        $summary = [
            'records_checked' => 0,
            'records_fixable' => 0,
            'records_updated' => 0,
            'skipped_with_old_upload_refs' => 0,
            'dry_run' => ! $apply,
        ];

        WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'imported')
            ->whereJsonContains('warnings', 'inline_image_download_failed')
            ->with('importable')
            ->orderBy('wordpress_id')
            ->get()
            ->each(function (WordpressImportRecord $record) use ($apply, &$summary): void {
                $summary['records_checked']++;

                if (! $record->importable instanceof Article) {
                    return;
                }

                if ($this->containsOldUploadReference((string) $record->importable->body_html, $record)) {
                    $summary['skipped_with_old_upload_refs']++;

                    return;
                }

                $warnings = $this->resolvedMediaReviewWarnings($record);

                if ($warnings === ($record->warnings ?? [])) {
                    return;
                }

                $summary['records_fixable']++;

                if (! $apply) {
                    return;
                }

                $record->forceFill(['warnings' => $warnings])->save();
                $summary['records_updated']++;
            });

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function brokenUploadReportRows(): array
    {
        return collect($this->repair(apply: false)['items'])
            ->groupBy(fn (array $item): string => (string) ($item['source_url'] ?? $item['literal'] ?? 'unknown-source'))
            ->map(function (Collection $group, string $sourceUrl): array {
                $hasKnownRepairFailure = $group->contains(
                    fn (array $item): bool => ($item['status'] ?? null) === 'old_link_repair_failed'
                        || ($item['reason'] ?? null) === 'old_link_repair_failed',
                );

                return [
                    'article_id' => $this->reportColumnValue($group->pluck('article_id')),
                    'wordpress_id' => $this->reportColumnValue($group->pluck('wordpress_id')),
                    'slug' => $this->reportColumnValue($group->pluck('slug')),
                    'old_url' => $this->reportColumnValue($group->pluck('old_url')),
                    'new_url' => $this->reportColumnValue($group->pluck('new_url')),
                    'source_url' => $sourceUrl,
                    'literal_url' => $this->reportColumnValue($group->pluck('literal')),
                    'reason' => $hasKnownRepairFailure ? 'old_link_repair_failed' : 'unresolved_old_wordpress_upload',
                    'status' => $hasKnownRepairFailure ? 'old_link_repair_failed' : 'pending',
                    'message' => $hasKnownRepairFailure
                        ? 'One or more matching old WordPress upload repairs previously failed; source links remain for manual review.'
                        : 'Old WordPress upload reference is unresolved.',
                    'occurrence_count' => $group->count(),
                    'article_count' => $group->pluck('article_id')->unique()->count(),
                ];
            })
            ->sortBy(fn (array $row): string => (($row['status'] ?? null) === 'old_link_repair_failed' ? '0' : '1').'|'.($row['source_url'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{literal: string, source_url: string, alt_text: string|null}>
     */
    private function referencesForArticle(Article $article): array
    {
        $body = (string) $article->body_html;
        $record = $article->wordpressImportRecord;
        $references = collect();

        foreach ($this->attributeReferences($body) as $reference) {
            $references->push([
                ...$reference,
                'source_url' => $this->absoluteSourceUrl($reference['literal'], $record),
            ]);
        }

        foreach ($this->rawReferences($body) as $literal) {
            $references->push([
                'literal' => $literal,
                'alt_text' => null,
                'source_url' => $this->absoluteSourceUrl($literal, $record),
            ]);
        }

        return $references
            ->filter(fn (array $reference): bool => $this->isWordpressUploadUrl($reference['source_url'], $record))
            ->map(fn (array $reference): array => [
                ...$reference,
                'literal_source_url' => $reference['source_url'],
                'source_url' => $this->canonicalUploadSourceUrl($reference['source_url']),
            ])
            ->unique(fn (array $reference): string => $reference['literal'].'|'.$reference['source_url'])
            ->values()
            ->all();
    }

    private function reportColumnValue(Collection $values): mixed
    {
        $filtered = $values
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->unique()
            ->values();

        return $filtered->count() === 1 ? $filtered->first() : $filtered->all();
    }

    /**
     * @return Collection<int, array{literal: string, alt_text: string|null}>
     */
    private function attributeReferences(string $body): Collection
    {
        preg_match_all('/<(?P<tag>\w+)\b(?P<attributes>[^>]*)\b(?:src|href)=(["\'])(?P<url>[^"\']+)\3[^>]*>/i', $body, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $match): array {
                $literal = html_entity_decode((string) $match['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return [
                    'literal' => $literal,
                    'alt_text' => strtolower((string) $match['tag']) === 'img' ? $this->attributeValue($match[0], 'alt') : null,
                ];
            })
            ->filter(fn (array $reference): bool => str_contains($reference['literal'], '/wp-content/uploads/'))
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function rawReferences(string $body): array
    {
        preg_match_all('/https?:\/\/[^\s"\')<]+\/wp-content\/uploads\/[^\s"\')<]+/i', $body, $absoluteMatches);
        preg_match_all('/(?<!:)\/wp-content\/uploads\/[^\s"\')<]+/i', $body, $pathMatches);
        $absoluteUrls = collect($absoluteMatches[0] ?? [])
            ->map(fn (string $url): string => $this->cleanRawReferenceUrl($url))
            ->values();
        $pathUrls = collect($pathMatches[0] ?? [])
            ->map(fn (string $url): string => $this->cleanRawReferenceUrl($url))
            ->reject(fn (string $path): bool => $absoluteUrls->contains(fn (string $absolute): bool => str_contains($absolute, $path)))
            ->values();

        return $absoluteUrls
            ->merge($pathUrls)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function cleanRawReferenceUrl(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[.,;"\'”’“‘]+$/u', '', $url) ?? $url;
    }

    private function absoluteSourceUrl(string $literal, ?WordpressImportRecord $record): string
    {
        if (str_starts_with($literal, '//')) {
            return 'https:'.$literal;
        }

        if (preg_match('/^https?:\/\//i', $literal)) {
            return $literal;
        }

        $baseUrl = $record?->old_url ?: config('services.wordpress.base_url');
        $scheme = parse_url((string) $baseUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url((string) $baseUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return $literal;
        }

        return $scheme.'://'.$host.'/'.ltrim($literal, '/');
    }

    private function isWordpressUploadUrl(string $url, ?WordpressImportRecord $record): bool
    {
        if (preg_match('/^https?:\/\/.+\/wp-content\/uploads\//i', $url) !== 1) {
            return false;
        }

        $sourceHost = $this->normalizedHost($record?->old_url ?: config('services.wordpress.base_url'));
        $urlHost = $this->normalizedHost($url);

        return $sourceHost !== null && $urlHost === $sourceHost;
    }

    private function normalizedHost(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./i', '', strtolower(rtrim($host, '.')));
    }

    private function canonicalUploadSourceUrl(string $url): string
    {
        return preg_replace('/-\d+x\d+(?=\.[a-z0-9]{2,5}(?:[?#]|$))/iu', '', $url) ?? $url;
    }

    private function existingDownloadedMedia(string $sourceUrl): ?MediaAsset
    {
        return MediaAsset::query()
            ->where('disk', 'public')
            ->where('metadata->source_url', $sourceUrl)
            ->first();
    }

    private function publicMediaUrl(MediaAsset $asset): string
    {
        if ($asset->url) {
            return $asset->url;
        }

        $url = Storage::disk($asset->disk ?: 'public')->url($asset->path);

        return str_starts_with($url, '/') ? $url : '/'.ltrim($url, '/');
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function removeReferenceLiteral(string $body, string $literal): array
    {
        $removed = 0;
        $quotedLiteral = preg_quote($literal, '/');

        $body = preg_replace(
            '/<figure\b[^>]*>.*?(?:src|href)=["\']'.$quotedLiteral.'["\'].*?<\/figure>/is',
            '',
            $body,
            -1,
            $count,
        ) ?? $body;
        $removed += $count;

        $body = preg_replace(
            '/<img\b[^>]*(?:src|href)=["\']'.$quotedLiteral.'["\'][^>]*>/i',
            '',
            $body,
            -1,
            $count,
        ) ?? $body;
        $removed += $count;

        $body = preg_replace(
            '/<a\b[^>]*href=["\']'.$quotedLiteral.'["\'][^>]*>(.*?)<\/a>/is',
            '$1',
            $body,
            -1,
            $count,
        ) ?? $body;
        $removed += $count;

        $body = str_replace($literal, '', $body, $count);
        $removed += $count;

        return [$body, $removed];
    }

    /**
     * @param  array{source_url: string, literal_source_url?: string, alt_text: string|null}  $reference
     * @return array{asset: MediaAsset|null, existing: bool, attempted_urls: array<int, string>, failure_reasons: array<string, string>, resolved_source_url: string|null}
     */
    private function resolveReferenceMedia(array $reference): array
    {
        $sourceUrls = collect([
            $reference['source_url'],
            $reference['literal_source_url'] ?? null,
        ])
            ->filter(fn (?string $sourceUrl): bool => is_string($sourceUrl) && $sourceUrl !== '')
            ->unique()
            ->values();
        $attemptedUrls = [];
        $failureReasons = [];

        foreach ($sourceUrls as $sourceUrl) {
            $attemptedUrls[] = $sourceUrl;
            $existing = $this->existingDownloadedMedia($sourceUrl);

            if ($existing) {
                return [
                    'asset' => $existing,
                    'existing' => true,
                    'attempted_urls' => $attemptedUrls,
                    'failure_reasons' => $failureReasons,
                    'resolved_source_url' => $sourceUrl,
                ];
            }

            $asset = $this->contentSanitizer->downloadMedia($sourceUrl, [
                'alt_text' => $reference['alt_text'],
                'source' => 'wordpress_old_link_repair',
            ]);

            if ($asset) {
                return [
                    'asset' => $asset,
                    'existing' => false,
                    'attempted_urls' => $attemptedUrls,
                    'failure_reasons' => $failureReasons,
                    'resolved_source_url' => $sourceUrl,
                ];
            }

            $failureReasons[$sourceUrl] = $this->contentSanitizer->lastDownloadError() ?? 'Media could not be downloaded.';
        }

        return [
            'asset' => null,
            'existing' => false,
            'attempted_urls' => $attemptedUrls,
            'failure_reasons' => $failureReasons,
            'resolved_source_url' => null,
        ];
    }

    /**
     * @param  array<string, string>  $failureReasons
     */
    private function failureReasonSummary(array $failureReasons): string
    {
        $reasons = array_map(
            fn (string $reason): string => rtrim($reason, '.'),
            array_values(array_unique(array_filter($failureReasons))),
        );

        return $reasons === []
            ? 'source link was kept for manual review.'
            : implode('; ', $reasons).'. Source link was kept for manual review.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function brokenSources(array $items): array
    {
        return collect($items)
            ->where('status', 'failed')
            ->groupBy(fn (array $item): string => (string) ($item['source_url'] ?? $item['literal'] ?? 'unknown-source'))
            ->map(function ($group, string $sourceUrl): array {
                $failureReasons = $group
                    ->flatMap(fn (array $item): array => array_values($item['failure_reasons'] ?? []))
                    ->filter()
                    ->unique()
                    ->values();

                return [
                    'source_url' => $sourceUrl,
                    'reason' => (string) ($failureReasons->first() ?: 'Media could not be downloaded.'),
                    'occurrence_count' => $group->count(),
                    'article_count' => $group->pluck('article_id')->unique()->count(),
                    'sample_articles' => $group->pluck('slug')->filter()->unique()->take(5)->values()->all(),
                    'wordpress_ids' => $group->pluck('wordpress_id')->filter()->unique()->values()->all(),
                    'attempted_urls' => $group
                        ->flatMap(fn (array $item): array => $item['attempted_urls'] ?? [])
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'failure_reasons' => $failureReasons->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function addRecordWarnings(?WordpressImportRecord $record, array $warnings): void
    {
        if (! $record) {
            return;
        }

        $record->forceFill([
            'warnings' => collect($record->warnings ?? [])
                ->merge($warnings)
                ->unique()
                ->values()
                ->all(),
        ])->save();
    }

    private function clearRepairWarning(?WordpressImportRecord $record): void
    {
        if (! $record) {
            return;
        }

        $record->forceFill(['warnings' => $this->resolvedMediaReviewWarnings($record)])->save();
    }

    /**
     * @return array<int, string>
     */
    private function resolvedMediaReviewWarnings(WordpressImportRecord $record): array
    {
        $warnings = collect($record->warnings ?? [])
            ->reject(fn (string $warning): bool => in_array($warning, [
                'inline_image_download_failed',
                'old_link_repair_failed',
            ], true))
            ->values();

        $manualReviewWarnings = [
            'broken_embed',
            'missing_featured_image',
            'unsafe_html_removed',
            'unsupported_shortcode',
            'untrusted_iframe_removed',
        ];

        if ($warnings->intersect($manualReviewWarnings)->isEmpty()) {
            $warnings = $warnings
                ->reject(fn (string $warning): bool => $warning === 'manual_review')
                ->values();
        }

        return $warnings->all();
    }

    private function containsOldUploadReference(string $body, ?WordpressImportRecord $record): bool
    {
        $article = new Article(['body_html' => $body]);
        $article->setRelation('wordpressImportRecord', $record);

        return $this->referencesForArticle($article) !== [];
    }

    private function attributeValue(string $tag, string $attribute): ?string
    {
        if (! preg_match('/\s'.preg_quote($attribute, '/').'=(["\'])(.*?)\1/i', $tag, $matches)) {
            return null;
        }

        return trim(html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
    }
}
