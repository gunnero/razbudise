<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Services\Operations\AdminAuditLogger;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MediaDuplicateCleanupService
{
    private const MEDIA_REFERENCES = [
        ['table' => 'articles', 'column' => 'featured_image_id'],
        ['table' => 'authors', 'column' => 'avatar_id'],
        ['table' => 'seo_metadata', 'column' => 'og_image_id'],
        ['table' => 'ad_creatives', 'column' => 'image_id'],
        ['table' => 'sponsored_content_details', 'column' => 'sponsor_logo_id'],
        ['table' => 'team_members', 'column' => 'profile_image_id'],
        ['table' => 'ad_booking_line_items', 'column' => 'image_id'],
    ];

    private const EMBEDDED_TEXT_REFERENCES = [
        ['table' => 'articles', 'column' => 'body_html'],
        ['table' => 'authors', 'column' => 'short_bio'],
        ['table' => 'authors', 'column' => 'full_bio'],
        ['table' => 'team_members', 'column' => 'short_bio'],
        ['table' => 'team_members', 'column' => 'full_bio'],
        ['table' => 'ad_creatives', 'column' => 'html'],
        ['table' => 'ad_booking_line_items', 'column' => 'html'],
    ];

    private const EMBEDDED_JSON_REFERENCES = [
        ['table' => 'article_blocks', 'column' => 'data'],
        ['table' => 'articles', 'column' => 'onefivefour_metadata'],
        ['table' => 'articles', 'column' => 'guide_metadata'],
        ['table' => 'seo_metadata', 'column' => 'breadcrumbs'],
        ['table' => 'seo_metadata', 'column' => 'schema_json'],
        ['table' => 'homepage_sections', 'column' => 'configuration'],
        ['table' => 'site_settings', 'column' => 'value'],
        ['table' => 'team_members', 'column' => 'social_links'],
        ['table' => 'team_members', 'column' => 'metadata'],
    ];

    private const MEDIA_ID_KEYS = [
        'media_asset_id',
        'featured_image_id',
        'og_image_id',
        'sponsor_logo_id',
        'image_id',
        'avatar_id',
        'profile_image_id',
    ];

    private const EDITORIAL_COLUMNS = [
        'display_name',
        'title',
        'alt_text',
        'caption',
        'description',
        'photographer',
        'source_name',
        'license',
        'copyright_notice',
        'seo_filename_suggestion',
        'usage_notes',
    ];

    private const EDITORIAL_METADATA_KEYS = [
        'credit',
        'source_url',
        'focal_point',
    ];

    public function __construct(
        private readonly MediaAssetUsageService $usage,
        private readonly MediaAssetDeletionGuard $deletionGuard,
        private readonly MediaAssetStorageService $storage,
        private readonly AdminAuditLogger $auditLogger,
    ) {}

    /**
     * @return array{
     *     duplicate_groups: int,
     *     mergeable_duplicate_assets: int,
     *     skipped_conflicting_groups: int,
     *     skipped_conflicting_assets: int,
     *     merged_duplicate_assets: int,
     *     references_updated: int,
     *     items: array<int, array<string, mixed>>
     * }
     */
    public function repair(bool $apply = false): array
    {
        $summary = [
            'duplicate_groups' => 0,
            'mergeable_duplicate_assets' => 0,
            'skipped_conflicting_groups' => 0,
            'skipped_conflicting_assets' => 0,
            'merged_duplicate_assets' => 0,
            'references_updated' => 0,
            'items' => [],
        ];

        foreach ($this->duplicateGroups() as $group) {
            /** @var array{reason: string, key: string, ids: array<int, int>} $group */
            $assets = MediaAsset::query()
                ->whereIn('id', $group['ids'])
                ->orderBy('id')
                ->get();

            if ($assets->count() < 2) {
                continue;
            }

            $this->repairGroup($assets, $group['reason'], $group['key'], $apply, $summary);
        }

        return $summary;
    }

    /**
     * @return Collection<int, array{reason: string, key: string, ids: array<int, int>}>
     */
    private function duplicateGroups(): Collection
    {
        $assets = MediaAsset::query()->orderBy('id')->get();
        $identityGroups = collect($this->groupsBySourceUrl($assets)->all())
            ->merge($this->groupsByStoragePath($assets)->all());
        $parents = $assets->mapWithKeys(fn (MediaAsset $asset): array => [$asset->id => $asset->id])->all();

        foreach ($identityGroups as $group) {
            $firstId = $group['ids'][0] ?? null;

            if (! is_int($firstId)) {
                continue;
            }

            foreach (array_slice($group['ids'], 1) as $id) {
                $this->union($parents, $firstId, $id);
            }
        }

        $components = collect(array_keys($parents))
            ->groupBy(function (int $id) use (&$parents): int {
                return $this->findRoot($parents, $id);
            })
            ->filter(fn (Collection $ids): bool => $ids->count() > 1);

        return $components
            ->map(function (Collection $ids) use ($identityGroups): array {
                $memberIds = $ids->map(fn ($id): int => (int) $id)->sort()->values()->all();
                $matchingIdentities = $identityGroups->filter(
                    fn (array $group): bool => count(array_intersect($memberIds, $group['ids'])) > 1,
                );

                return [
                    'reason' => $matchingIdentities->pluck('reason')->unique()->implode('+'),
                    'key' => $matchingIdentities->pluck('key')->unique()->implode(' | '),
                    'ids' => $memberIds,
                ];
            })
            ->values();
    }

    /**
     * @param  array<int, int>  $parents
     */
    private function findRoot(array &$parents, int $id): int
    {
        if ($parents[$id] !== $id) {
            $parents[$id] = $this->findRoot($parents, $parents[$id]);
        }

        return $parents[$id];
    }

    /**
     * @param  array<int, int>  $parents
     */
    private function union(array &$parents, int $first, int $second): void
    {
        $firstRoot = $this->findRoot($parents, $first);
        $secondRoot = $this->findRoot($parents, $second);

        if ($firstRoot !== $secondRoot) {
            $parents[$secondRoot] = $firstRoot;
        }
    }

    /**
     * @param  EloquentCollection<int, MediaAsset>  $assets
     * @return Collection<int, array{reason: string, key: string, ids: array<int, int>}>
     */
    private function groupsBySourceUrl(EloquentCollection $assets): Collection
    {
        return $assets
            ->filter(fn (MediaAsset $asset): bool => filled($asset->metadata['source_url'] ?? null))
            ->groupBy(fn (MediaAsset $asset): string => (string) $asset->metadata['source_url'])
            ->filter(fn (EloquentCollection $group): bool => $group->count() > 1)
            ->flatMap(function (EloquentCollection $group, string $sourceUrl): array {
                $pairs = [];
                $items = $group->values();

                for ($first = 0; $first < $items->count(); $first++) {
                    for ($second = $first + 1; $second < $items->count(); $second++) {
                        /** @var MediaAsset $firstAsset */
                        $firstAsset = $items->get($first);
                        /** @var MediaAsset $secondAsset */
                        $secondAsset = $items->get($second);

                        if (! $this->isTrustedSourceDuplicatePair($firstAsset, $secondAsset)) {
                            continue;
                        }

                        $pairs[] = [
                            'reason' => 'duplicate_source_url',
                            'key' => $sourceUrl,
                            'ids' => [$firstAsset->id, $secondAsset->id],
                        ];
                    }
                }

                return $pairs;
            })
            ->values();
    }

    private function isTrustedSourceDuplicatePair(MediaAsset $first, MediaAsset $second): bool
    {
        $firstHash = trim((string) ($first->metadata['content_sha256'] ?? ''));
        $secondHash = trim((string) ($second->metadata['content_sha256'] ?? ''));

        if ($this->isSha256($firstHash)
            && $this->isSha256($secondHash)
            && hash_equals(strtolower($firstHash), strtolower($secondHash))) {
            return true;
        }

        $external = ($first->disk ?: 'public') === 'external' ? $first : $second;
        $local = $external->is($first) ? $second : $first;

        return ($external->disk ?: 'public') === 'external'
            && ($local->disk ?: 'public') !== 'external'
            && ($local->metadata['source'] ?? null) === 'wordpress_old_link_repair'
            && filled($local->metadata['source_url'] ?? null)
            && ($local->metadata['source_url'] ?? null) === ($external->metadata['source_url'] ?? null);
    }

    private function isSha256(string $hash): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/i', $hash) === 1;
    }

    /**
     * @param  EloquentCollection<int, MediaAsset>  $assets
     * @return Collection<int, array{reason: string, key: string, ids: array<int, int>}>
     */
    private function groupsByStoragePath(EloquentCollection $assets): Collection
    {
        return $assets
            ->mapWithKeys(fn (MediaAsset $asset): array => [
                $asset->id => $this->storageIdentity($asset),
            ])
            ->filter()
            ->groupBy(fn (string $identity): string => $identity, preserveKeys: true)
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(fn (Collection $group, string $storagePath): array => [
                'reason' => 'duplicate_storage_path',
                'key' => $storagePath,
                'ids' => $group->keys()->map(fn ($id): int => (int) $id)->values()->all(),
            ])
            ->values();
    }

    private function storageIdentity(MediaAsset $asset): ?string
    {
        $disk = $asset->disk ?: 'public';

        if ($disk === 'external') {
            $url = trim((string) $asset->url);

            return filter_var($url, FILTER_VALIDATE_URL) && filled(parse_url($url, PHP_URL_HOST))
                ? 'external:url:'.$url
                : null;
        }

        return filled($asset->path) ? $disk.':'.$asset->path : null;
    }

    /**
     * @param  EloquentCollection<int, MediaAsset>  $assets
     * @param  array<string, mixed>  $summary
     */
    private function repairGroup(EloquentCollection $assets, string $reason, string $key, bool $apply, array &$summary): void
    {
        $summary['duplicate_groups']++;
        $expectedDuplicateIdentity = $this->duplicateIdentitySnapshot($assets);

        if ($expectedDuplicateIdentity === []) {
            $this->recordSkippedGroup(
                $summary,
                $assets,
                $reason,
                $key,
                'skipped_identity_changed',
                ['duplicate_identity'],
            );

            return;
        }

        $operationalConflicts = $this->conflictingOperationalBoundaries($assets);

        if ($operationalConflicts !== []) {
            $this->recordSkippedGroup(
                $summary,
                $assets,
                $reason,
                $key,
                'skipped_operational_boundary',
                $operationalConflicts,
            );

            return;
        }

        $conflictingFields = $this->conflictingEditorialFields($assets);

        // Matching file identity is not permission to collapse distinct
        // editorial records. A shared source URL or binary can legitimately be
        // described differently in separate contexts. Keep both rows when a
        // merge would discard any intentional metadata and surface the conflict
        // for manual review instead.
        if ($conflictingFields !== []) {
            $this->recordSkippedGroup(
                $summary,
                $assets,
                $reason,
                $key,
                'skipped_editorial_conflict',
                $conflictingFields,
            );

            return;
        }

        $historicallyReferenced = $assets
            ->filter(fn (MediaAsset $asset): bool => $this->isHistoricallyReferenced($asset))
            ->values();

        if ($historicallyReferenced->count() > 1) {
            $this->recordSkippedGroup(
                $summary,
                $assets,
                $reason,
                $key,
                'skipped_historical_conflict',
                ['immutable_history'],
            );

            return;
        }

        $canonical = $historicallyReferenced->first() ?? $this->canonicalAsset($assets);
        $duplicateIds = $assets
            ->reject(fn (MediaAsset $asset): bool => $asset->is($canonical))
            ->pluck('id')
            ->values()
            ->all();

        if ($duplicateIds === []) {
            return;
        }

        $summary['mergeable_duplicate_assets'] += count($duplicateIds);

        $referenceCount = $assets
            ->whereIn('id', $duplicateIds)
            ->sum(fn (MediaAsset $asset): int => $this->usage->count($asset));

        $summary['items'][] = [
            'reason' => $reason,
            'key' => $key,
            'canonical_media_asset_id' => $canonical->id,
            'duplicate_media_asset_ids' => $duplicateIds,
            'references' => $referenceCount,
        ];

        if (! $apply) {
            return;
        }

        /** @var array<int, MediaAsset> $deletedAssets */
        $deletedAssets = DB::transaction(function () use ($canonical, $duplicateIds, $expectedDuplicateIdentity, &$summary): array {
            $lockedAssets = MediaAsset::query()
                ->whereIn('id', [$canonical->id, ...$duplicateIds])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            /** @var MediaAsset $lockedCanonical */
            $lockedCanonical = $lockedAssets->get($canonical->id)
                ?? throw new RuntimeException('The canonical media asset no longer exists.');
            /** @var EloquentCollection<int, MediaAsset> $lockedDuplicates */
            $lockedDuplicates = new EloquentCollection(
                collect($duplicateIds)
                    ->map(fn (int $id): ?MediaAsset => $lockedAssets->get($id))
                    ->filter()
                    ->values()
                    ->all(),
            );

            if ($lockedDuplicates->count() !== count($duplicateIds)) {
                throw new RuntimeException('A duplicate media asset changed while it was being merged.');
            }

            $lockedGroup = new EloquentCollection([
                $lockedCanonical,
                ...$lockedDuplicates->all(),
            ]);
            $lockedHistorical = $lockedGroup
                ->filter(fn (MediaAsset $asset): bool => $this->isHistoricallyReferenced($asset))
                ->values();
            $expectedCanonical = $lockedHistorical->first() ?? $this->canonicalAsset($lockedGroup);

            if ($this->duplicateIdentitySnapshot($lockedGroup) !== $expectedDuplicateIdentity
                || $this->conflictingOperationalBoundaries($lockedGroup) !== []
                || $this->conflictingEditorialFields($lockedGroup) !== []
                || $lockedHistorical->count() > 1
                || ! $expectedCanonical->is($lockedCanonical)) {
                throw new RuntimeException('A duplicate media group changed while it was being merged.');
            }

            $mergedEditorialFields = $this->mergeEditorialMetadata($lockedCanonical, $lockedDuplicates);

            if ($mergedEditorialFields !== []) {
                $this->auditLogger->record(
                    action: 'media_asset.duplicate_metadata_merged',
                    auditable: $lockedCanonical,
                    after: ['changed_fields' => $mergedEditorialFields],
                    metadata: ['duplicate_media_asset_ids' => $duplicateIds],
                );
            }

            $referencesUpdated = $this->moveReferences($duplicateIds, $lockedCanonical->id);
            $referencesUpdated += $this->moveEmbeddedReferences($lockedDuplicates, $lockedCanonical);

            foreach ($lockedDuplicates as $duplicate) {
                $usage = $this->deletionGuard->assertCanDeleteMergedDuplicate($duplicate, $lockedCanonical);

                $this->auditLogger->record(
                    action: 'media_asset.duplicate_merged',
                    auditable: $duplicate,
                    before: [
                        'disk' => $duplicate->disk,
                        'path' => $duplicate->path,
                        'url' => $duplicate->url,
                    ],
                    after: [
                        'canonical_media_asset_id' => $lockedCanonical->id,
                    ],
                    metadata: [
                        'usage_after_transfer' => $usage,
                    ],
                );

                // The regular observer correctly refuses ambiguous shared-file
                // deletion. This domain workflow has already transferred and
                // verified every reference, and performs storage-aware cleanup
                // only after the transaction commits.
                $duplicate->deleteQuietly();
            }

            $summary['merged_duplicate_assets'] += $lockedDuplicates->count();
            $summary['references_updated'] += $referencesUpdated;

            return $lockedDuplicates->all();
        }, 3);

        foreach ($deletedAssets as $deletedAsset) {
            if (! $this->storage->deleteFiles($deletedAsset)) {
                Log::warning('A merged duplicate media row was removed but one or more unshared files remain.', [
                    'media_asset_id' => $deletedAsset->getKey(),
                    'canonical_media_asset_id' => $canonical->getKey(),
                    'disk' => $deletedAsset->disk,
                    'paths' => $this->storage->unsharedPaths($deletedAsset),
                ]);
            }
        }
    }

    /**
     * Return fields for which merging would irreversibly choose between two
     * different, non-empty editorial values.
     *
     * @param  EloquentCollection<int, MediaAsset>  $assets
     * @return array<int, string>
     */
    private function conflictingEditorialFields(EloquentCollection $assets): array
    {
        $conflicts = [];

        foreach (self::EDITORIAL_COLUMNS as $column) {
            $values = $assets
                ->map(fn (MediaAsset $asset) => $column === 'display_name'
                    ? $asset->getRawOriginal('display_name')
                    : $asset->getAttribute($column))
                ->filter(fn ($value): bool => filled($value))
                ->map(fn ($value): string => $this->normalizedEditorialValue($value))
                ->filter()
                ->unique()
                ->values();

            if ($values->count() > 1) {
                $conflicts[] = $column;
            }
        }

        foreach (self::EDITORIAL_METADATA_KEYS as $key) {
            $values = $assets
                ->map(fn (MediaAsset $asset) => $asset->metadata[$key] ?? null)
                ->filter(fn ($value): bool => filled($value))
                ->map(fn ($value): string => $this->normalizedEditorialValue($value))
                ->filter()
                ->unique()
                ->values();

            if ($values->count() > 1) {
                $conflicts[] = 'metadata.'.$key;
            }
        }

        return $conflicts;
    }

    /**
     * Never collapse assets across ownership, advertiser/editorial purpose, a
     * public upload claim, or archive state. These fields are authorization
     * boundaries, not mergeable editorial metadata.
     *
     * @param  EloquentCollection<int, MediaAsset>  $assets
     * @return array<int, string>
     */
    private function conflictingOperationalBoundaries(EloquentCollection $assets): array
    {
        $claimedAssetIds = DB::table('ad_creative_upload_claims')
            ->whereIn('media_asset_id', $assets->modelKeys())
            ->pluck('media_asset_id')
            ->filter()
            ->unique()
            ->values();

        // Upload claims are authorization and lifecycle records. Generic media
        // dedupe must never transfer or null them as a side effect, even when a
        // claim has already expired or been revoked.
        if ($claimedAssetIds->isNotEmpty()) {
            return ['ad_creative_upload_claims'];
        }

        $fields = [
            'uploaded_by' => fn (MediaAsset $asset): mixed => $asset->uploaded_by,
            'metadata.purpose' => fn (MediaAsset $asset): mixed => $asset->metadata['purpose'] ?? null,
            'metadata.ad_creative_upload' => fn (MediaAsset $asset): bool => (bool) ($asset->metadata['ad_creative_upload'] ?? false),
            'metadata.public_booking_upload_token' => fn (MediaAsset $asset): ?string => filled($asset->metadata['public_booking_upload_token'] ?? null)
                ? hash('sha256', (string) $asset->metadata['public_booking_upload_token'])
                : null,
            'metadata.public_booking_upload_status' => fn (MediaAsset $asset): mixed => $asset->metadata['public_booking_upload_status'] ?? null,
            'archived_at' => fn (MediaAsset $asset): bool => $asset->archived_at !== null,
        ];

        return collect($fields)
            ->filter(function (callable $value, string $field) use ($assets): bool {
                return $assets
                    ->map(fn (MediaAsset $asset): string => serialize($value($asset)))
                    ->unique()
                    ->count() > 1;
            })
            ->keys()
            ->values()
            ->all();
    }

    /**
     * Capture the exact trusted identities and edges which made this set a
     * duplicate component. Recomputing this after row locks closes the window
     * where a path, URL, source URL, or content hash changes after discovery.
     *
     * @param  EloquentCollection<int, MediaAsset>  $assets
     * @return array<int, string>
     */
    private function duplicateIdentitySnapshot(EloquentCollection $assets): array
    {
        $groups = $this->groupsBySourceUrl($assets)
            ->merge($this->groupsByStoragePath($assets));

        if ($groups->isEmpty()) {
            return [];
        }

        $parents = $assets
            ->mapWithKeys(fn (MediaAsset $asset): array => [$asset->id => $asset->id])
            ->all();

        foreach ($groups as $group) {
            $ids = collect($group['ids'])
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values();
            $firstId = $ids->first();

            if (! is_int($firstId)) {
                continue;
            }

            foreach ($ids->slice(1) as $id) {
                $this->union($parents, $firstId, $id);
            }
        }

        $roots = collect(array_keys($parents))
            ->map(function (int $id) use (&$parents): int {
                return $this->findRoot($parents, $id);
            })
            ->unique();

        if ($roots->count() !== 1) {
            return [];
        }

        return $groups
            ->map(function (array $group): string {
                $ids = collect($group['ids'])
                    ->map(fn ($id): int => (int) $id)
                    ->sort()
                    ->values()
                    ->implode(',');

                return $group['reason']."\0".$group['key']."\0".$ids;
            })
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  EloquentCollection<int, MediaAsset>  $assets
     * @param  array<int, string>  $conflictingFields
     */
    private function recordSkippedGroup(
        array &$summary,
        EloquentCollection $assets,
        string $reason,
        string $key,
        string $status,
        array $conflictingFields,
    ): void {
        $summary['skipped_conflicting_groups']++;
        $summary['skipped_conflicting_assets'] += $assets->count();
        $summary['items'][] = [
            'reason' => $reason,
            'key' => $key,
            'status' => $status,
            'media_asset_ids' => $assets->pluck('id')->values()->all(),
            'conflicting_fields' => $conflictingFields,
        ];
    }

    private function isHistoricallyReferenced(MediaAsset $asset): bool
    {
        if (DB::table('ad_creative_revisions')->where('image_id', $asset->getKey())->exists()) {
            return true;
        }

        $references = $this->usage->searchableReferences($asset);

        if ($this->textStoreReferencesAsset('ad_creative_revisions', 'html', $references)) {
            return true;
        }

        foreach ([
            ['article_revisions', 'snapshot'],
            ['wordpress_import_records', 'source_payload'],
            ['wordpress_import_records', 'warnings'],
        ] as [$table, $column]) {
            if ($this->jsonStoreReferencesAsset($table, $column, $asset, $references)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $references */
    private function textStoreReferencesAsset(string $table, string $column, array $references): bool
    {
        if ($references === []) {
            return false;
        }

        return DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->orderBy('id')
            ->lazyById(200)
            ->contains(fn (object $row): bool => collect($references)->contains(
                fn (string $reference): bool => str_contains((string) $row->{$column}, $reference),
            ));
    }

    /** @param array<int, string> $references */
    private function jsonStoreReferencesAsset(
        string $table,
        string $column,
        MediaAsset $asset,
        array $references,
    ): bool {
        return DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->orderBy('id')
            ->lazyById(200)
            ->contains(function (object $row) use ($column, $asset, $references): bool {
                $value = json_decode((string) $row->{$column}, true);

                return is_array($value)
                    && $this->historicalValueReferencesAsset($value, $asset, $references);
            });
    }

    /** @param array<int, string> $references */
    private function historicalValueReferencesAsset(
        mixed $value,
        MediaAsset $asset,
        array $references,
        ?string $key = null,
    ): bool {
        if ((is_int($value) || is_string($value))
            && in_array($key, self::MEDIA_ID_KEYS, true)
            && (string) $value === (string) $asset->getKey()) {
            return true;
        }

        if (is_string($value)
            && collect($references)->contains(fn (string $reference): bool => str_contains($value, $reference))) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $childKey => $childValue) {
            if ($this->historicalValueReferencesAsset($childValue, $asset, $references, (string) $childKey)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedEditorialValue(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);

            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value);

        return mb_strtolower($normalized, 'UTF-8');
    }

    /**
     * Preserve meaningful editorial metadata without replacing an intentional
     * value already present on the canonical media row.
     *
     * @param  EloquentCollection<int, MediaAsset>  $duplicates
     * @return array<int, string>
     */
    private function mergeEditorialMetadata(MediaAsset $canonical, EloquentCollection $duplicates): array
    {
        $changes = [];

        foreach (self::EDITORIAL_COLUMNS as $column) {
            $canonicalValue = $column === 'display_name'
                ? $canonical->getRawOriginal('display_name')
                : $canonical->getAttribute($column);

            if (filled($canonicalValue)) {
                continue;
            }

            $duplicateValue = $duplicates
                ->map(fn (MediaAsset $duplicate) => $column === 'display_name'
                    ? $duplicate->getRawOriginal('display_name')
                    : $duplicate->getAttribute($column))
                ->first(fn ($value): bool => filled($value));

            if (! filled($duplicateValue)) {
                continue;
            }

            $canonical->setAttribute($column, $duplicateValue);
            $changes[] = $column;
        }

        $metadata = $canonical->metadata ?? [];

        foreach (self::EDITORIAL_METADATA_KEYS as $key) {
            if (filled($metadata[$key] ?? null)) {
                continue;
            }

            $duplicateValue = $duplicates
                ->map(fn (MediaAsset $duplicate) => $duplicate->metadata[$key] ?? null)
                ->first(fn ($value): bool => filled($value));

            if (! filled($duplicateValue)) {
                continue;
            }

            $metadata[$key] = $duplicateValue;
            $changes[] = 'metadata.'.$key;
        }

        if ($changes !== []) {
            $canonical->setAttribute('metadata', $metadata);
            $canonical->save();
        }

        return $changes;
    }

    /**
     * @param  EloquentCollection<int, MediaAsset>  $assets
     */
    private function canonicalAsset(EloquentCollection $assets): MediaAsset
    {
        return $assets
            ->sort(fn (MediaAsset $first, MediaAsset $second): int => $this->canonicalScore($second) <=> $this->canonicalScore($first)
                ?: $second->id <=> $first->id)
            ->first();
    }

    private function canonicalScore(MediaAsset $asset): int
    {
        $metadata = $asset->metadata ?? [];
        $score = 0;

        if ($this->isLocalStoredAsset($asset)) {
            $score += 1000;
        }

        if (($metadata['source'] ?? null) === 'wordpress_old_link_repair') {
            $score += 250;
        }

        if (! empty($metadata['responsive_variants'])) {
            $score += 150;
        }

        if ($asset->width && $asset->height) {
            $score += 100;
        }

        if ($asset->alt_text || $asset->caption) {
            $score += 25;
        }

        return $score;
    }

    private function isLocalStoredAsset(MediaAsset $asset): bool
    {
        return $asset->disk !== 'external'
            && filled($asset->path)
            && ! str_starts_with((string) $asset->path, '/wp-content/uploads/');
    }

    /**
     * @param  array<int, int>  $duplicateIds
     */
    private function moveReferences(array $duplicateIds, int $canonicalId): int
    {
        $now = now();
        $updated = 0;

        foreach (self::MEDIA_REFERENCES as $reference) {
            $updated += DB::table($reference['table'])
                ->whereIn($reference['column'], $duplicateIds)
                ->update([
                    $reference['column'] => $canonicalId,
                    'updated_at' => $now,
                ]);
        }

        return $updated;
    }

    /**
     * @param  EloquentCollection<int, MediaAsset>  $duplicates
     */
    private function moveEmbeddedReferences(EloquentCollection $duplicates, MediaAsset $canonical): int
    {
        $stringReplacements = $this->stringReplacements($duplicates, $canonical);
        $idReplacements = $duplicates
            ->mapWithKeys(fn (MediaAsset $duplicate): array => [(string) $duplicate->id => $canonical->id])
            ->all();
        $updated = 0;

        foreach (self::EMBEDDED_TEXT_REFERENCES as $reference) {
            $updated += $this->rewriteTextColumn(
                $reference['table'],
                $reference['column'],
                $stringReplacements,
            );
        }

        foreach (self::EMBEDDED_JSON_REFERENCES as $reference) {
            $updated += $this->rewriteJsonColumn(
                $reference['table'],
                $reference['column'],
                $stringReplacements,
                $idReplacements,
            );
        }

        return $updated;
    }

    /**
     * @param  EloquentCollection<int, MediaAsset>  $duplicates
     * @return array<string, string>
     */
    private function stringReplacements(EloquentCollection $duplicates, MediaAsset $canonical): array
    {
        $canonicalVariants = collect($canonical->metadata['responsive_variants'] ?? []);
        $replacements = [];

        foreach ($duplicates as $duplicate) {
            $this->addReferenceReplacement(
                $replacements,
                (string) $duplicate->path,
                (string) $duplicate->url,
                (string) $canonical->path,
                (string) $canonical->url,
            );

            foreach (($duplicate->metadata['responsive_variants'] ?? []) as $variantName => $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $canonicalVariant = $canonicalVariants->get($variantName);
                $this->addReferenceReplacement(
                    $replacements,
                    (string) ($variant['path'] ?? ''),
                    (string) ($variant['url'] ?? ''),
                    is_array($canonicalVariant) ? (string) ($canonicalVariant['path'] ?? $canonical->path) : (string) $canonical->path,
                    is_array($canonicalVariant) ? (string) ($canonicalVariant['url'] ?? $canonical->url) : (string) $canonical->url,
                );
            }

            foreach (($duplicate->metadata['legacy_paths'] ?? []) as $legacyReference) {
                $legacyPath = is_array($legacyReference)
                    ? (string) ($legacyReference['path'] ?? '')
                    : (string) $legacyReference;
                $legacyUrl = is_array($legacyReference)
                    ? (string) ($legacyReference['url'] ?? '')
                    : '';

                $this->addReferenceReplacement(
                    $replacements,
                    $legacyPath,
                    $legacyUrl,
                    (string) $canonical->path,
                    (string) $canonical->url,
                );
            }
        }

        uksort($replacements, fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return $replacements;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function addReferenceReplacement(
        array &$replacements,
        string $oldPath,
        string $oldUrl,
        string $newPath,
        string $newUrl,
    ): void {
        foreach ([
            $oldUrl => $newUrl,
            $oldPath !== '' ? '/storage/'.ltrim($oldPath, '/') : '' => $newPath !== '' ? '/storage/'.ltrim($newPath, '/') : $newUrl,
            $oldPath => $newPath,
        ] as $old => $new) {
            if ($old !== '' && $new !== '' && $old !== $new) {
                $replacements[$old] = $new;
            }
        }
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function rewriteTextColumn(string $table, string $column, array $replacements): int
    {
        if ($replacements === []) {
            return 0;
        }

        $updated = 0;

        DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $column, $replacements, &$updated): void {
                foreach ($rows as $row) {
                    $before = (string) $row->{$column};
                    $after = str_replace(array_keys($replacements), array_values($replacements), $before);

                    if ($before === $after) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update([
                        $column => $after,
                        'updated_at' => now(),
                    ]);
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * @param  array<string, string>  $stringReplacements
     * @param  array<string, int>  $idReplacements
     */
    private function rewriteJsonColumn(
        string $table,
        string $column,
        array $stringReplacements,
        array $idReplacements,
    ): int {
        $updated = 0;

        DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $column, $stringReplacements, $idReplacements, &$updated): void {
                foreach ($rows as $row) {
                    $before = json_decode((string) $row->{$column}, true);

                    if (! is_array($before)) {
                        continue;
                    }

                    $after = $this->rewriteEmbeddedValue($before, $stringReplacements, $idReplacements);

                    if ($before === $after) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update([
                        $column => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * @param  array<string, string>  $stringReplacements
     * @param  array<string, int>  $idReplacements
     */
    private function rewriteEmbeddedValue(
        mixed $value,
        array $stringReplacements,
        array $idReplacements,
        ?string $key = null,
    ): mixed {
        if (is_string($value)) {
            if (in_array($key, self::MEDIA_ID_KEYS, true) && array_key_exists($value, $idReplacements)) {
                return $idReplacements[$value];
            }

            return str_replace(array_keys($stringReplacements), array_values($stringReplacements), $value);
        }

        if (is_int($value)
            && in_array($key, self::MEDIA_ID_KEYS, true)
            && array_key_exists((string) $value, $idReplacements)) {
            return $idReplacements[(string) $value];
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $childKey => $childValue) {
            $value[$childKey] = $this->rewriteEmbeddedValue(
                $childValue,
                $stringReplacements,
                $idReplacements,
                (string) $childKey,
            );
        }

        return $value;
    }
}
