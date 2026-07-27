<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Operations\AdminAuditLogger;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaAssetRenameService
{
    public function __construct(private readonly AdminAuditLogger $auditLogger) {}

    public function rename(MediaAsset $asset, string $requestedFilename, ?User $actor = null): MediaAsset
    {
        $requestedFilename = trim($requestedFilename);
        $asset = $asset->fresh();
        $this->validateRequestedFilename($asset, $requestedFilename);

        $reservedManifest = $this->manifest($asset, $requestedFilename);

        if ($reservedManifest === []) {
            return $asset;
        }

        $targetLocks = $this->acquireTargetLocks($asset, $reservedManifest);
        $operationDisk = $asset->disk ?: 'public';

        $createdPaths = [];

        try {
            $renamed = DB::transaction(function () use ($asset, $requestedFilename, $actor, $reservedManifest, &$createdPaths): MediaAsset {
                /** @var MediaAsset $locked */
                $locked = MediaAsset::query()->lockForUpdate()->findOrFail($asset->getKey());
                $manifest = $this->manifest($locked, $requestedFilename);

                if ($this->targetIdentities($locked, $manifest) !== $this->targetIdentities($asset, $reservedManifest)) {
                    throw ValidationException::withMessages([
                        'filename' => 'The media item changed while its filename was being reserved. Please retry.',
                    ]);
                }

                $filesystem = Storage::disk($locked->disk ?: 'public');
                $this->preflight($locked, $manifest);

                foreach ($manifest as $item) {
                    $copied = $filesystem->copy($item['old_path'], $item['new_path']);

                    if ($filesystem->exists($item['new_path'])) {
                        $createdPaths[] = $item['new_path'];
                    }

                    if (! $copied
                        || ! $filesystem->exists($item['new_path'])
                        || $filesystem->size($item['old_path']) !== $filesystem->size($item['new_path'])) {
                        throw ValidationException::withMessages([
                            'filename' => 'The media file could not be renamed safely. No changes were saved.',
                        ]);
                    }

                }

                $before = $this->auditSnapshot($locked);
                $metadata = $locked->metadata ?? [];
                $variants = $metadata['responsive_variants'] ?? [];
                $replacements = [];
                $targetsByVariant = collect($manifest)
                    ->keyBy(fn (array $item): string => $this->variantKey($item['variant']));

                foreach ($manifest as $item) {
                    $replacements += $this->referenceReplacements(
                        $item['old_path'],
                        $item['old_url'],
                        $item['new_path'],
                        $item['new_url'],
                    );

                    if ($item['variant'] !== null) {
                        $variants[$item['variant']]['path'] = $item['new_path'];
                        $variants[$item['variant']]['url'] = $item['new_url'];
                    }
                }

                foreach ($this->legacyReferences($metadata, array_keys($variants)) as $reference) {
                    /** @var array{variant: string|null, old_path: string, old_url: string, new_path: string, new_url: string}|null $target */
                    $target = $targetsByVariant->get($this->variantKey($reference['variant']))
                        ?? $targetsByVariant->get($this->variantKey(null));

                    if (! is_array($target)) {
                        continue;
                    }

                    $replacements += $this->referenceReplacements(
                        $reference['path'],
                        $reference['url'],
                        $target['new_path'],
                        $target['new_url'],
                    );

                    if ($reference['path'] !== '') {
                        $generatedLegacyUrl = (string) $filesystem->url($reference['path']);
                        $replacements += $this->referenceReplacements(
                            $reference['path'],
                            $generatedLegacyUrl,
                            $target['new_path'],
                            $target['new_url'],
                        );
                    }
                }

                $original = collect($manifest)->firstWhere('variant', null);
                $legacyPaths = collect($this->legacyReferences($metadata, array_keys($variants)))
                    ->map(fn (array $reference): array => [
                        'variant' => $reference['variant'],
                        'path' => $reference['path'] ?: null,
                        'url' => $reference['url'] ?: null,
                    ])
                    ->merge(collect($manifest)->map(fn (array $item): array => [
                        'variant' => $item['variant'],
                        'path' => $item['old_path'],
                        'url' => $item['old_url'],
                    ]))
                    ->filter(fn (array $reference): bool => filled($reference['path']) || filled($reference['url']))
                    ->unique(fn (array $reference): string => ($reference['path'] ?? '').'|'.($reference['url'] ?? ''))
                    ->values()
                    ->all();

                $metadata['responsive_variants'] = $variants;
                $metadata['legacy_paths'] = $legacyPaths;
                $metadata['last_renamed_at'] = now()->toIso8601String();

                $locked->forceFill([
                    'path' => $original['new_path'],
                    'url' => $original['new_url'],
                    'metadata' => $metadata,
                ])->save();

                $referencesUpdated = $this->rewriteCurrentContentReferences($replacements);
                $after = $this->auditSnapshot($locked->refresh());

                $this->auditLogger->record(
                    action: 'media_asset.file_renamed',
                    auditable: $locked,
                    actor: $actor,
                    before: $before,
                    after: $after,
                    metadata: [
                        'changed_fields' => ['path', 'url', 'responsive_variants'],
                        'files_copied' => count($manifest),
                        'content_references_updated' => $referencesUpdated,
                        'compatibility_aliases_retained' => count($manifest),
                    ],
                );

                return $locked->refresh();
            }, 3);
        } catch (Throwable $exception) {
            if ($createdPaths !== []) {
                Storage::disk($operationDisk)->delete(array_values(array_unique($createdPaths)));
            }

            throw $exception;
        } finally {
            $this->releaseTargetLocks($targetLocks);
        }

        return $renamed;
    }

    private function validateRequestedFilename(MediaAsset $asset, string $requestedFilename): void
    {
        if (($asset->disk ?: 'public') === 'external') {
            throw ValidationException::withMessages([
                'filename' => 'External media cannot be physically renamed.',
            ]);
        }

        if ($requestedFilename === ''
            || $requestedFilename !== basename($requestedFilename)
            || str_contains($requestedFilename, "\0")
            || in_array($requestedFilename, ['.', '..'], true)) {
            throw ValidationException::withMessages([
                'filename' => 'Enter a safe filename without a folder path.',
            ]);
        }

        $currentExtension = strtolower((string) pathinfo((string) $asset->path, PATHINFO_EXTENSION));
        $requestedExtension = strtolower((string) pathinfo($requestedFilename, PATHINFO_EXTENSION));

        if ($currentExtension === '') {
            throw ValidationException::withMessages([
                'filename' => 'The current media file has no safe extension to preserve.',
            ]);
        }

        if ($requestedExtension !== '' && $requestedExtension !== $currentExtension) {
            throw ValidationException::withMessages([
                'filename' => 'Changing the file extension is not allowed. Replace the file to change its format.',
            ]);
        }
    }

    /**
     * @return array<int, array{variant: string|null, old_path: string, old_url: string, new_path: string, new_url: string}>
     */
    private function manifest(MediaAsset $asset, string $requestedFilename): array
    {
        $filesystem = Storage::disk($asset->disk ?: 'public');
        $extension = strtolower((string) pathinfo((string) $asset->path, PATHINFO_EXTENSION));
        $requestedStem = pathinfo($requestedFilename, PATHINFO_FILENAME);
        $stem = Str::slug($requestedStem);

        if ($stem === '') {
            throw ValidationException::withMessages([
                'filename' => 'Enter a descriptive filename using letters or numbers.',
            ]);
        }

        $directory = trim((string) pathinfo((string) $asset->path, PATHINFO_DIRNAME), '.');
        $newPath = trim(($directory !== '' ? $directory.'/' : '').$stem.'.'.$extension, '/');

        if ($newPath === $asset->path) {
            return [];
        }

        $manifest = [[
            'variant' => null,
            'old_path' => (string) $asset->path,
            'old_url' => (string) ($asset->url ?: $filesystem->url((string) $asset->path)),
            'new_path' => $newPath,
            'new_url' => $filesystem->url($newPath),
        ]];

        foreach (($asset->metadata['responsive_variants'] ?? []) as $variant => $details) {
            $oldPath = is_array($details) ? ($details['path'] ?? null) : null;

            if (! is_string($oldPath) || $oldPath === '') {
                continue;
            }

            $variantDirectory = trim((string) pathinfo($oldPath, PATHINFO_DIRNAME), '.');
            $variantExtension = strtolower((string) pathinfo($oldPath, PATHINFO_EXTENSION)) ?: $extension;
            $newVariantPath = trim(
                ($variantDirectory !== '' ? $variantDirectory.'/' : '').$stem.'-'.Str::slug((string) $variant).'.'.$variantExtension,
                '/',
            );

            $manifest[] = [
                'variant' => (string) $variant,
                'old_path' => $oldPath,
                'old_url' => (string) ($details['url'] ?? $filesystem->url($oldPath)),
                'new_path' => $newVariantPath,
                'new_url' => $filesystem->url($newVariantPath),
            ];
        }

        return $manifest;
    }

    /**
     * @param  array<int, array{variant: string|null, old_path: string, old_url: string, new_path: string, new_url: string}>  $manifest
     */
    private function preflight(MediaAsset $asset, array $manifest): void
    {
        $filesystem = Storage::disk($asset->disk ?: 'public');
        $newPaths = collect($manifest)->pluck('new_path');

        if ($newPaths->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['filename' => 'The requested filename creates duplicate derivative paths.']);
        }

        foreach ($manifest as $item) {
            if (! $filesystem->exists($item['old_path'])) {
                throw ValidationException::withMessages([
                    'filename' => 'A source file or derivative is missing. Repair the media item before renaming it.',
                ]);
            }

            if ($filesystem->exists($item['new_path'])) {
                throw ValidationException::withMessages([
                    'filename' => 'A file with this name already exists.',
                ]);
            }
        }

        if (MediaAsset::query()
            ->where('disk', $asset->disk ?: 'public')
            ->where('path', $manifest[0]['new_path'])
            ->whereKeyNot($asset->getKey())
            ->exists()) {
            throw ValidationException::withMessages([
                'filename' => 'Another media item already uses this filename.',
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function referenceReplacements(string $oldPath, string $oldUrl, string $newPath, string $newUrl): array
    {
        return array_filter([
            $oldUrl => $newUrl,
            '/storage/'.ltrim($oldPath, '/') => '/storage/'.ltrim($newPath, '/'),
            $oldPath => $newPath,
        ], fn ($new, $old): bool => $old !== '' && $old !== $new, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    public function rewriteCurrentContentReferences(array $replacements): int
    {
        if ($replacements === []) {
            return 0;
        }

        uksort($replacements, fn (string $first, string $second): int => strlen($second) <=> strlen($first));
        $updated = 0;

        $updated += $this->replaceTextColumn('articles', 'body_html', $replacements);
        $updated += $this->replaceTextColumn('authors', 'short_bio', $replacements);
        $updated += $this->replaceTextColumn('authors', 'full_bio', $replacements);
        $updated += $this->replaceTextColumn('team_members', 'short_bio', $replacements);
        $updated += $this->replaceTextColumn('team_members', 'full_bio', $replacements);
        $updated += $this->replaceTextColumn('ad_creatives', 'html', $replacements);
        $updated += $this->replaceTextColumn('ad_booking_line_items', 'html', $replacements);
        $updated += $this->replaceJsonColumn('article_blocks', 'data', $replacements);
        $updated += $this->replaceJsonColumn('articles', 'onefivefour_metadata', $replacements);
        $updated += $this->replaceJsonColumn('articles', 'guide_metadata', $replacements);
        $updated += $this->replaceJsonColumn('homepage_sections', 'configuration', $replacements);
        $updated += $this->replaceJsonColumn('site_settings', 'value', $replacements);
        $updated += $this->replaceJsonColumn('team_members', 'social_links', $replacements);
        $updated += $this->replaceJsonColumn('team_members', 'metadata', $replacements);
        $updated += $this->replaceJsonColumn('seo_metadata', 'breadcrumbs', $replacements);
        $updated += $this->replaceJsonColumn('seo_metadata', 'schema_json', $replacements);

        return $updated;
    }

    /**
     * Reserve every target path before touching storage. The cache-backed lock
     * is shared by web and worker processes in production, unlike a row lock
     * on either source asset, and therefore serializes two different assets
     * that request an overlapping original or derivative path.
     *
     * @param  array<int, array{variant: string|null, old_path: string, old_url: string, new_path: string, new_url: string}>  $manifest
     * @return array<int, Lock>
     */
    private function acquireTargetLocks(MediaAsset $asset, array $manifest): array
    {
        $locks = [];

        foreach ($this->targetIdentities($asset, $manifest) as $identity) {
            $lock = Cache::lock('media-asset-rename-target:'.hash('sha256', $identity), 300);

            if (! $lock->get()) {
                $this->releaseTargetLocks($locks);

                throw ValidationException::withMessages([
                    'filename' => 'Another media operation is already using this filename. Please retry.',
                ]);
            }

            $locks[] = $lock;
        }

        return $locks;
    }

    /**
     * @param  array<int, Lock>  $locks
     */
    private function releaseTargetLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                Log::warning('A media filename reservation could not be released cleanly.', [
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{variant: string|null, old_path: string, old_url: string, new_path: string, new_url: string}>  $manifest
     * @return array<int, string>
     */
    private function targetIdentities(MediaAsset $asset, array $manifest): array
    {
        $disk = $asset->disk ?: 'public';

        return collect($manifest)
            ->pluck('new_path')
            ->filter(fn ($path): bool => is_string($path) && $path !== '')
            ->map(fn (string $path): string => $disk."\0".$path)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Normalize compatibility aliases and retain the stable responsive
     * variant name when it is known. Older rows did not store that name, so
     * infer it conservatively from the path or URL suffix when possible.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string|int>  $variantNames
     * @return array<int, array{variant: string|null, path: string, url: string}>
     */
    private function legacyReferences(array $metadata, array $variantNames): array
    {
        return collect($metadata['legacy_paths'] ?? [])
            ->map(function ($reference) use ($variantNames): array {
                $path = is_array($reference)
                    ? (string) ($reference['path'] ?? '')
                    : (string) $reference;
                $url = is_array($reference)
                    ? (string) ($reference['url'] ?? '')
                    : '';
                $variant = is_array($reference) && filled($reference['variant'] ?? null)
                    ? (string) $reference['variant']
                    : $this->inferVariantName($path, $url, $variantNames);

                return [
                    'variant' => $variant,
                    'path' => $path,
                    'url' => $url,
                ];
            })
            ->filter(fn (array $reference): bool => $reference['path'] !== '' || $reference['url'] !== '')
            ->unique(fn (array $reference): string => $this->variantKey($reference['variant']).'|'.$reference['path'].'|'.$reference['url'])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string|int>  $variantNames
     */
    private function inferVariantName(string $path, string $url, array $variantNames): ?string
    {
        $candidatePath = $path !== '' ? $path : (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $stem = pathinfo($candidatePath, PATHINFO_FILENAME);

        foreach ($variantNames as $variantName) {
            $variantName = (string) $variantName;

            if ($variantName !== '' && str_ends_with($stem, '-'.Str::slug($variantName))) {
                return $variantName;
            }
        }

        return null;
    }

    private function variantKey(?string $variant): string
    {
        return $variant === null || $variant === '' ? 'original' : 'variant:'.$variant;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function replaceTextColumn(string $table, string $column, array $replacements): int
    {
        $updated = 0;

        DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $column, $replacements, &$updated): void {
                foreach ($rows as $row) {
                    $before = (string) $row->{$column};
                    $after = str_replace(array_keys($replacements), array_values($replacements), $before);

                    if ($after === $before) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update([$column => $after]);
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function replaceJsonColumn(string $table, string $column, array $replacements): int
    {
        $updated = 0;

        DB::table($table)
            ->select(['id', $column])
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $column, $replacements, &$updated): void {
                foreach ($rows as $row) {
                    $before = json_decode((string) $row->{$column}, true);

                    if (! is_array($before)) {
                        continue;
                    }

                    $after = $this->replaceReferencesInValue($before, $replacements);

                    if ($after === $before) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update([
                        $column => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function replaceReferencesInValue(mixed $value, array $replacements): mixed
    {
        if (is_string($value)) {
            return str_replace(array_keys($replacements), array_values($replacements), $value);
        }

        if (! is_array($value)) {
            return $value;
        }

        return collect($value)
            ->map(fn ($item) => $this->replaceReferencesInValue($item, $replacements))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(MediaAsset $asset): array
    {
        return [
            'path' => $asset->path,
            'url' => $asset->url,
            'responsive_variants' => collect($asset->metadata['responsive_variants'] ?? [])
                ->map(fn ($variant): array => [
                    'path' => is_array($variant) ? ($variant['path'] ?? null) : null,
                    'url' => is_array($variant) ? ($variant['url'] ?? null) : null,
                ])
                ->all(),
        ];
    }
}
