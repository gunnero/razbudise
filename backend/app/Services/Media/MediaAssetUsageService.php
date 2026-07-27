<?php

namespace App\Services\Media;

use App\Models\Article;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MediaAssetUsageService
{
    public function __construct(private readonly MediaAssetStorageService $storage) {}

    private const RELATIONAL_REFERENCES = [
        'featured_articles' => ['articles', 'featured_image_id'],
        'author_avatars' => ['authors', 'avatar_id'],
        'team_member_profiles' => ['team_members', 'profile_image_id'],
        'seo_images' => ['seo_metadata', 'og_image_id'],
        'ad_creatives' => ['ad_creatives', 'image_id'],
        'sponsor_logos' => ['sponsored_content_details', 'sponsor_logo_id'],
        'ad_booking_items' => ['ad_booking_line_items', 'image_id'],
        'ad_creative_revisions' => ['ad_creative_revisions', 'image_id'],
        'ad_creative_upload_claims' => ['ad_creative_upload_claims', 'media_asset_id'],
    ];

    /**
     * Embedded stores inspected by the deletion guard and usage counters.
     *
     * @var array<int, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    private const EMBEDDED_REFERENCES = [
        ['articles', 'body_html', false, false],
        ['authors', 'short_bio', false, false],
        ['authors', 'full_bio', false, false],
        ['team_members', 'short_bio', false, false],
        ['team_members', 'full_bio', false, false],
        ['team_members', 'social_links', false, true],
        ['team_members', 'metadata', true, true],
        ['article_blocks', 'data', true, true],
        ['article_revisions', 'snapshot', true, true],
        ['articles', 'onefivefour_metadata', true, true],
        ['articles', 'guide_metadata', true, true],
        ['seo_metadata', 'breadcrumbs', true, true],
        ['seo_metadata', 'schema_json', true, true],
        ['homepage_sections', 'configuration', true, true],
        ['site_settings', 'value', true, true],
        ['wordpress_import_records', 'source_payload', true, true],
        ['wordpress_import_records', 'warnings', true, true],
        ['ad_creatives', 'html', false, false],
        ['ad_booking_line_items', 'html', false, false],
        ['ad_creative_revisions', 'html', false, false],
    ];

    /**
     * @return array{
     *     total: int,
     *     published_content: int,
     *     relations: array<string, int>
     * }
     */
    public function summary(MediaAsset $asset): array
    {
        $relations = [];

        foreach (self::RELATIONAL_REFERENCES as $key => [$table, $column]) {
            $relations[$key] = DB::table($table)->where($column, $asset->getKey())->count();
        }

        $relations['inline_articles'] = $this->inlineArticleQuery($asset)->count();
        $relations['author_biographies'] = $this->textReferenceCount('authors', 'short_bio', $asset)
            + $this->textReferenceCount('authors', 'full_bio', $asset);
        $relations['team_member_biographies'] = $this->textReferenceCount('team_members', 'short_bio', $asset)
            + $this->textReferenceCount('team_members', 'full_bio', $asset);
        $relations['team_member_social_links'] = $this->jsonReferenceCount('team_members', 'social_links', $asset);
        $relations['team_member_metadata'] = $this->jsonReferenceCount('team_members', 'metadata', $asset, matchIds: true);
        $relations['article_blocks'] = $this->jsonReferenceCount('article_blocks', 'data', $asset, matchIds: true);
        $relations['article_revisions'] = $this->jsonReferenceCount('article_revisions', 'snapshot', $asset, matchIds: true);
        $relations['article_import_metadata'] = $this->jsonReferenceCount('articles', 'onefivefour_metadata', $asset, matchIds: true)
            + $this->jsonReferenceCount('articles', 'guide_metadata', $asset, matchIds: true);
        $relations['seo_embedded_data'] = $this->jsonReferenceCount('seo_metadata', 'schema_json', $asset, matchIds: true)
            + $this->jsonReferenceCount('seo_metadata', 'breadcrumbs', $asset, matchIds: true);
        $relations['embedded_ad_creatives'] = $this->textReferenceCount('ad_creatives', 'html', $asset)
            + $this->textReferenceCount('ad_booking_line_items', 'html', $asset)
            + $this->textReferenceCount('ad_creative_revisions', 'html', $asset);
        $relations['homepage_sections'] = $this->jsonReferenceCount('homepage_sections', 'configuration', $asset, matchIds: true);
        $relations['site_settings'] = $this->jsonReferenceCount('site_settings', 'value', $asset, matchIds: true);
        $relations['wordpress_import_history'] = $this->jsonReferenceCount('wordpress_import_records', 'source_payload', $asset, matchIds: true)
            + $this->jsonReferenceCount('wordpress_import_records', 'warnings', $asset, matchIds: true);

        return [
            'total' => array_sum($relations),
            'published_content' => $this->publishedArticles($asset)->count()
                + $this->jsonReferenceCount('homepage_sections', 'configuration', $asset, matchIds: true, constraints: ['is_active' => true])
                + $this->jsonReferenceCount('site_settings', 'value', $asset, matchIds: true, constraints: ['is_public' => true]),
            'relations' => $relations,
        ];
    }

    public function count(MediaAsset $asset): int
    {
        return $this->summary($asset)['total'];
    }

    public function isUsed(MediaAsset $asset): bool
    {
        return $this->count($asset) > 0;
    }

    public function isUsedByPublishedContent(MediaAsset $asset): bool
    {
        return $this->summary($asset)['published_content'] > 0;
    }

    public function sharedStorageOwnerCount(MediaAsset $asset): int
    {
        $paths = $this->storage->paths($asset);

        if ($paths === []) {
            return 0;
        }

        return MediaAsset::query()
            ->whereKeyNot($asset->getKey())
            ->where('disk', $asset->disk ?: 'public')
            ->get()
            ->filter(fn (MediaAsset $candidate): bool => array_intersect($paths, $this->storage->paths($candidate)) !== [])
            ->count();
    }

    /**
     * Return every current article that uses the asset as its featured image,
     * SEO image, sponsor logo, inline HTML, or a structured block.
     *
     * @return Collection<int, Article>
     */
    public function usedInArticles(MediaAsset $asset): Collection
    {
        return $this->articleUsageQuery($asset)
            ->with(['author:id,name', 'category:id,name'])
            ->latest('updated_at')
            ->get();
    }

    public function withUsageCount(Builder $query): Builder
    {
        return $query
            ->addSelect('media_assets.*')
            ->selectRaw('('.$this->usageCountExpression().') as usage_count');
    }

    public function whereUsed(Builder $query, bool $used = true): Builder
    {
        return $query->whereRaw('('.$this->usageCountExpression().') '.($used ? '>' : '=').' 0');
    }

    /**
     * Attach fast usage totals to an already-loaded gallery page without
     * issuing one full usage query for every card.
     *
     * This method is intentionally limited to indexed relational references.
     * The full deletion guard and detail screens still use summary() for deep
     * embedded reference checks, but the picker cannot scan imported HTML/JSON
     * history while authors are waiting for the gallery modal to open.
     *
     * @param  Collection<int, mixed>  $records
     */
    public function hydrateUsageCounts(Collection $records): void
    {
        /** @var Collection<int, MediaAsset> $assets */
        $assets = $records
            ->filter(fn (mixed $record): bool => $record instanceof MediaAsset)
            ->filter(fn (MediaAsset $asset): bool => ! array_key_exists('usage_count', $asset->getAttributes()))
            ->values();

        if ($assets->isEmpty()) {
            return;
        }

        $assetIds = $assets->map(fn (MediaAsset $asset): int => (int) $asset->getKey())->all();
        $counts = array_fill_keys($assetIds, 0);

        foreach (self::RELATIONAL_REFERENCES as [$table, $column]) {
            DB::table($table)
                ->select($column, DB::raw('count(*) as aggregate'))
                ->whereIn($column, $assetIds)
                ->groupBy($column)
                ->get()
                ->each(function ($row) use ($column, &$counts): void {
                    $assetId = (int) $row->{$column};

                    if (array_key_exists($assetId, $counts)) {
                        $counts[$assetId] += (int) $row->aggregate;
                    }
                });
        }

        foreach ($assets as $asset) {
            $asset->setAttribute('usage_count', $counts[(int) $asset->getKey()] ?? 0);
        }
    }

    private function publishedArticles(MediaAsset $asset): Builder
    {
        return $this->articleUsageQuery($asset)->published();
    }

    private function articleUsageQuery(MediaAsset $asset): Builder
    {
        $references = $this->searchableReferences($asset);
        $blockArticleIds = $this->articleIdsFromJsonColumn('article_blocks', 'article_id', 'data', $asset, $references);
        $metadataArticleIds = collect([
            ...$this->articleIdsFromJsonColumn('articles', 'id', 'onefivefour_metadata', $asset, $references, true),
            ...$this->articleIdsFromJsonColumn('articles', 'id', 'guide_metadata', $asset, $references, true),
            ...$this->articleIdsFromSeoEmbeddedData($asset, $references),
        ])->unique()->all();

        return Article::query()->where(function (Builder $query) use ($asset, $blockArticleIds, $metadataArticleIds): void {
            $query
                ->where('featured_image_id', $asset->getKey())
                ->orWhereHas('seoMetadata', fn (Builder $seoQuery) => $seoQuery->where('og_image_id', $asset->getKey()))
                ->orWhereHas('sponsoredContentDetail', fn (Builder $sponsorQuery) => $sponsorQuery->where('sponsor_logo_id', $asset->getKey()))
                ->orWhere(fn (Builder $inlineQuery) => $this->whereContainsAssetReference($inlineQuery, 'body_html', $asset));

            $embeddedArticleIds = collect($blockArticleIds)
                ->merge($metadataArticleIds)
                ->unique()
                ->all();

            if ($embeddedArticleIds !== []) {
                $query->orWhereIn('id', $embeddedArticleIds);
            }
        });
    }

    private function inlineArticleQuery(MediaAsset $asset): Builder
    {
        return Article::query()
            ->where(fn (Builder $query) => $this->whereContainsAssetReference($query, 'body_html', $asset));
    }

    private function textReferenceCount(string $table, string $column, MediaAsset $asset): int
    {
        $references = $this->searchableReferences($asset);

        if ($references === []) {
            return 0;
        }

        return DB::table($table)
            ->whereNotNull($column)
            ->where(function ($query) use ($column, $references): void {
                foreach ($references as $reference) {
                    $query->orWhereRaw($this->literalContainsExpression($column), [$reference]);
                }
            })
            ->count();
    }

    private function whereContainsAssetReference(mixed $query, string $column, MediaAsset $asset): void
    {
        $references = $this->searchableReferences($asset);

        if ($references === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        foreach ($references as $reference) {
            $query->orWhereRaw($this->literalContainsExpression($column), [$reference]);
        }
    }

    /**
     * Return every URL/path alias that can identify this media item in
     * embedded content, including generated variants and safe-rename aliases.
     *
     * @return array<int, string>
     */
    public function searchableReferences(MediaAsset $asset): array
    {
        $filesystem = ($asset->disk ?: 'public') === 'external'
            ? null
            : Storage::disk($asset->disk ?: 'public');
        $variantReferences = collect($asset->metadata['responsive_variants'] ?? [])
            ->flatMap(fn ($variant): array => is_array($variant)
                ? [$variant['url'] ?? null, $variant['path'] ?? null]
                : []);
        $legacyReferences = collect($asset->metadata['legacy_paths'] ?? [])
            ->flatMap(fn ($reference): array => is_array($reference)
                ? [$reference['url'] ?? null, $reference['path'] ?? null]
                : [$reference]);

        return collect([
            $asset->url,
            $asset->path,
        ])
            ->merge($variantReferences)
            ->merge($legacyReferences)
            ->filter(fn ($reference): bool => is_string($reference) && $reference !== '')
            ->flatMap(function (string $reference) use ($filesystem): array {
                $isPath = ! filter_var($reference, FILTER_VALIDATE_URL)
                    && ! str_starts_with($reference, '/storage/');

                return [
                    $reference,
                    $isPath ? '/storage/'.ltrim($reference, '/') : null,
                    $isPath && $filesystem !== null
                        ? $filesystem->url($reference)
                        : null,
                ];
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $references
     * @return array<int, int>
     */
    private function articleIdsFromJsonColumn(
        string $table,
        string $idColumn,
        string $jsonColumn,
        MediaAsset $asset,
        array $references,
        bool $matchIds = false,
    ): array {
        return DB::table($table)
            ->whereNotNull($jsonColumn)
            ->get([$idColumn, $jsonColumn])
            ->filter(function ($row) use ($jsonColumn, $asset, $references, $matchIds): bool {
                $value = json_decode((string) $row->{$jsonColumn}, true);

                return is_array($value)
                    && $this->valueReferencesAsset($value, $asset->getKey(), $references, $matchIds);
            })
            ->pluck($idColumn)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $references
     * @return array<int, int>
     */
    private function articleIdsFromSeoEmbeddedData(MediaAsset $asset, array $references): array
    {
        return DB::table('seo_metadata')
            ->where('seoable_type', Article::class)
            ->whereNotNull('seoable_id')
            ->where(fn ($query) => $query
                ->whereNotNull('breadcrumbs')
                ->orWhereNotNull('schema_json'))
            ->get(['seoable_id', 'breadcrumbs', 'schema_json'])
            ->filter(function ($row) use ($asset, $references): bool {
                foreach (['breadcrumbs', 'schema_json'] as $column) {
                    $value = $this->decodeJsonValue($row->{$column});

                    if ($this->valueReferencesAsset($value, $asset->getKey(), $references, true)) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('seoable_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function usageCountExpression(?string $driver = null): string
    {
        $driver ??= DB::getDriverName();
        $parts = collect(self::RELATIONAL_REFERENCES)
            ->map(fn (array $reference): string => sprintf(
                '(select count(*) from %s where %s.%s = media_assets.id)',
                $reference[0],
                $reference[0],
                $reference[1],
            ));

        foreach (self::EMBEDDED_REFERENCES as [$table, $column, $matchesIds]) {
            $conditions = [$this->aliasMatchExpression($table.'.'.$column, $driver)];

            if ($matchesIds) {
                $conditions[] = $this->embeddedIdMatchExpression($table.'.'.$column, $driver);
            }

            $parts->push(sprintf(
                '(select count(*) from %s where %s.%s is not null and (%s))',
                $table,
                $table,
                $column,
                implode(' or ', $conditions),
            ));
        }

        return $parts->implode(' + ');
    }

    private function aliasMatchExpression(string $contentColumn, string $driver): string
    {
        $content = $this->textCastExpression($contentColumn, $driver);
        $contains = $this->dynamicContainsExpression(
            $content,
            'media_reference_alias.reference',
            $driver,
        );

        return sprintf(
            'exists (select 1 from media_asset_reference_aliases media_reference_alias where media_reference_alias.media_asset_id = media_assets.id and %s)',
            $contains,
        );
    }

    private function embeddedIdMatchExpression(string $contentColumn, string $driver): string
    {
        $content = $this->textCastExpression($contentColumn, $driver);
        $characterFunction = $driver === 'pgsql' ? 'chr' : 'char';
        $normalizedContent = "replace(replace(replace(replace({$content}, ' ', ''), {$characterFunction}(9), ''), {$characterFunction}(10), ''), {$characterFunction}(13), '')";
        $assetId = $driver === 'pgsql' ? 'cast(media_assets.id as text)' : 'media_assets.id';
        $patterns = collect([
            'media_asset_id',
            'featured_image_id',
            'og_image_id',
            'sponsor_logo_id',
            'image_id',
            'avatar_id',
            'profile_image_id',
        ])->flatMap(function (string $key) use ($normalizedContent, $assetId, $driver): array {
            $numericPrefix = '"'.$key.'":';
            $stringPrefix = '"'.$key.'":"';

            return collect([
                [$numericPrefix, ','],
                [$numericPrefix, '}'],
                [$stringPrefix, '",'],
                [$stringPrefix, '"}'],
            ])->map(function (array $parts) use ($normalizedContent, $assetId, $driver): string {
                [$prefix, $suffix] = $parts;
                $needle = match ($driver) {
                    'mysql', 'mariadb' => "concat('{$prefix}', {$assetId}, '{$suffix}')",
                    default => "('{$prefix}' || {$assetId} || '{$suffix}')",
                };

                return $this->dynamicContainsExpression($normalizedContent, $needle, $driver);
            })->all();
        });

        return '('.$patterns->implode(' or ').')';
    }

    private function textCastExpression(string $column, string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => "cast({$column} as char)",
            default => "cast({$column} as text)",
        };
    }

    private function literalContainsExpression(string $column): string
    {
        $driver = DB::getDriverName();
        $content = $this->textCastExpression($column, $driver);

        return $this->dynamicContainsExpression($content, '?', $driver);
    }

    private function dynamicContainsExpression(string $content, string $needle, string $driver): string
    {
        return match ($driver) {
            'pgsql' => "strpos({$content}, {$needle}) > 0",
            default => "instr({$content}, {$needle}) > 0",
        };
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return json_decode($value, true);
    }

    /**
     * @param  array<string, mixed>  $constraints
     */
    private function jsonReferenceCount(
        string $table,
        string $column,
        MediaAsset $asset,
        bool $matchIds = false,
        array $constraints = [],
    ): int {
        $references = $this->searchableReferences($asset);
        $query = DB::table($table)->whereNotNull($column);

        foreach ($constraints as $constraintColumn => $value) {
            $query->where($constraintColumn, $value);
        }

        return $query
            ->get([$column])
            ->filter(function ($row) use ($column, $asset, $references, $matchIds): bool {
                $value = json_decode((string) $row->{$column}, true);

                return is_array($value) && $this->valueReferencesAsset($value, $asset->getKey(), $references, $matchIds);
            })
            ->count();
    }

    /**
     * @param  array<int, string>  $references
     */
    private function valueReferencesAsset(mixed $value, int|string $assetId, array $references, bool $matchIds, ?string $key = null): bool
    {
        if ($matchIds
            && in_array($key, ['media_asset_id', 'featured_image_id', 'og_image_id', 'sponsor_logo_id', 'image_id', 'avatar_id', 'profile_image_id'], true)
            && (string) $value === (string) $assetId) {
            return true;
        }

        if (is_string($value)) {
            foreach ($references as $reference) {
                if (str_contains($value, $reference)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $childKey => $childValue) {
            if ($this->valueReferencesAsset($childValue, $assetId, $references, $matchIds, (string) $childKey)) {
                return true;
            }
        }

        return false;
    }
}
