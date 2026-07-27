<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Operations\AdminAuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaAssetReplacementService
{
    public function __construct(
        private readonly MediaAssetUploader $uploader,
        private readonly MediaVariantGenerator $variants,
        private readonly MediaAssetStorageService $storage,
        private readonly MediaAssetRenameService $referenceRewriter,
        private readonly MediaSeoFilenameSuggester $filenameSuggester,
        private readonly AdminAuditLogger $auditLogger,
    ) {}

    public function replace(MediaAsset $asset, UploadedFile $file, ?User $actor = null): MediaAsset
    {
        Validator::make(['file' => $file], [
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif,avif', 'max:10240'],
        ])->validate();

        $targetDisk = ($asset->disk ?: 'public') === 'external' ? 'public' : ($asset->disk ?: 'public');
        $newPath = $file->store('media', $targetDisk);

        if (! is_string($newPath) || $newPath === '') {
            throw ValidationException::withMessages(['replacement_file' => 'The replacement file could not be stored.']);
        }

        $plannedVariantPaths = array_values($this->variants->plannedPaths($newPath));
        $preexistingVariantPaths = collect($plannedVariantPaths)
            ->filter(fn (string $path): bool => Storage::disk($targetDisk)->exists($path))
            ->values()
            ->all();
        $newPaths = [
            $newPath,
            ...array_values(array_diff($plannedVariantPaths, $preexistingVariantPaths)),
        ];

        try {
            $conflictingVariantPath = $preexistingVariantPaths[0] ?? null;

            if (is_string($conflictingVariantPath)) {
                throw ValidationException::withMessages([
                    'replacement_file' => 'A derivative path for this replacement already exists. Please retry the upload.',
                ]);
            }

            $stored = $this->uploader->metadataForStoredPath($targetDisk, $newPath);

            if (is_int($stored['width'])
                && is_int($stored['height'])
                && ! $this->variants->dimensionsWithinLimits($stored['width'], $stored['height'])) {
                throw ValidationException::withMessages([
                    'replacement_file' => sprintf(
                        'Image dimensions exceed limits. Maximum side is %d pixels and maximum image area is %d pixels.',
                        MediaVariantGenerator::MAX_IMAGE_SIDE,
                        MediaVariantGenerator::MAX_IMAGE_PIXELS,
                    ),
                ]);
            }

            return DB::transaction(function () use (
                $asset,
                $file,
                $actor,
                $targetDisk,
                $newPath,
                $stored,
            ): MediaAsset {
                /** @var MediaAsset $locked */
                $locked = MediaAsset::query()->lockForUpdate()->findOrFail($asset->getKey());
                $before = $this->auditSnapshot($locked);
                $oldDisk = $locked->disk ?: 'public';
                $oldPaths = $this->storage->paths($locked);
                $oldReferences = $this->references($locked, includeLegacy: true);
                $metadata = $locked->metadata ?? [];
                $suggestionWasGenerated = ($metadata['seo_filename_suggestion_generated'] ?? false) === true;

                unset($metadata['responsive_variants']);
                $metadata['original_name'] = $file->getClientOriginalName();
                $metadata['content_sha256'] = hash_file('sha256', $file->getRealPath()) ?: null;
                $metadata['last_replaced_at'] = now()->toIso8601String();

                $legacyReferences = collect($metadata['legacy_paths'] ?? [])
                    ->map(fn ($reference): array => is_array($reference)
                        ? [
                            'variant' => filled($reference['variant'] ?? null) ? (string) $reference['variant'] : null,
                            'path' => $reference['path'] ?? null,
                            'url' => $reference['url'] ?? null,
                        ]
                        : ['variant' => null, 'path' => $reference, 'url' => null])
                    ->merge(collect($oldReferences)->map(fn (array $reference): array => [
                        'variant' => $reference['variant'],
                        'path' => $reference['path'] ?: null,
                        'url' => $reference['url'] ?: null,
                    ]))
                    ->when(
                        $oldDisk !== $targetDisk,
                        fn ($references) => $references->map(fn (array $reference): array => [
                            'variant' => $reference['variant'],
                            // An external path must never become owned by the
                            // local replacement disk. The absolute URL remains
                            // a searchable compatibility alias.
                            'path' => null,
                            'url' => filter_var($reference['url'], FILTER_VALIDATE_URL)
                                ? $reference['url']
                                : null,
                        ]),
                    )
                    ->filter(fn (array $reference): bool => filled($reference['path']) || filled($reference['url']))
                    ->unique(fn (array $reference): string => ($reference['path'] ?? '').'|'.($reference['url'] ?? ''))
                    ->values()
                    ->all();
                $metadata['legacy_paths'] = $legacyReferences;

                $locked->forceFill([
                    'disk' => $targetDisk,
                    'path' => $newPath,
                    'url' => $stored['url'],
                    'mime_type' => $stored['mime_type'],
                    'width' => $stored['width'],
                    'height' => $stored['height'],
                    'size_bytes' => $stored['size_bytes'],
                    'metadata' => $metadata,
                ]);

                if ($suggestionWasGenerated) {
                    $locked->seo_filename_suggestion = $this->filenameSuggester->suggest($locked);

                    if (filled($locked->seo_filename_suggestion)) {
                        $metadata['seo_filename_suggestion_generated'] = true;
                    } else {
                        unset($metadata['seo_filename_suggestion_generated']);
                    }

                    $locked->metadata = $metadata;
                }

                $locked->save();

                $responsiveVariants = $this->variants->generate($locked);

                if ($responsiveVariants !== []) {
                    $metadata = $locked->metadata ?? [];
                    $metadata['responsive_variants'] = $responsiveVariants;
                    $locked->forceFill(['metadata' => $metadata])->save();
                }

                $newReferences = $this->references($locked->refresh(), includeLegacy: false);
                $referencesUpdated = $this->referenceRewriter->rewriteCurrentContentReferences(
                    $this->replacementMap($oldReferences, $newReferences),
                );

                $this->auditLogger->record(
                    action: 'media_asset.file_replaced',
                    auditable: $locked,
                    actor: $actor,
                    before: $before,
                    after: $this->auditSnapshot($locked->refresh()),
                    metadata: [
                        'content_references_updated' => $referencesUpdated,
                        'compatibility_aliases_retained' => count($legacyReferences),
                        'compatibility_file_aliases_retained' => $oldDisk === $targetDisk ? count($oldPaths) : 0,
                    ],
                );

                return $locked->refresh();
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk($targetDisk)->delete(array_values(array_unique($newPaths)));

            throw $exception;
        }
    }

    /**
     * Return current references plus, when requested, compatibility aliases.
     * Each reference retains its stable responsive variant name so an old
     * `large` URL can never be paired with a new `thumbnail` by array order.
     *
     * @return array<int, array{variant: string|null, path: string, url: string}>
     */
    private function references(MediaAsset $asset, bool $includeLegacy): array
    {
        $filesystem = ($asset->disk ?: 'public') === 'external'
            ? null
            : Storage::disk($asset->disk ?: 'public');
        $variantNames = array_keys($asset->metadata['responsive_variants'] ?? []);
        $references = collect($asset->metadata['responsive_variants'] ?? [])
            ->map(fn ($variant, string $name): array => [
                'variant' => $name,
                'path' => is_array($variant) ? (string) ($variant['path'] ?? '') : '',
                'url' => is_array($variant) ? (string) ($variant['url'] ?? '') : '',
            ])
            ->prepend([
                'variant' => null,
                'path' => (string) $asset->path,
                'url' => (string) $asset->url,
            ]);

        if ($includeLegacy) {
            $references = $references->merge(
                collect($asset->metadata['legacy_paths'] ?? [])->map(function ($reference) use ($variantNames): array {
                    $path = is_array($reference) ? (string) ($reference['path'] ?? '') : (string) $reference;
                    $url = is_array($reference) ? (string) ($reference['url'] ?? '') : '';

                    return [
                        'variant' => is_array($reference) && filled($reference['variant'] ?? null)
                            ? (string) $reference['variant']
                            : $this->inferVariantName($path, $url, $variantNames),
                        'path' => $path,
                        'url' => $url,
                    ];
                }),
            );
        }

        return $references
            ->filter(fn (array $reference): bool => $reference['path'] !== '' || $reference['url'] !== '')
            ->flatMap(function (array $reference) use ($filesystem): array {
                if ($reference['path'] === '' || $filesystem === null) {
                    return [$reference];
                }

                $generatedUrl = (string) $filesystem->url($reference['path']);

                if ($generatedUrl === '' || $generatedUrl === $reference['url']) {
                    return [$reference];
                }

                return [
                    $reference,
                    [...$reference, 'url' => $generatedUrl],
                ];
            })
            ->unique(fn (array $reference): string => $this->variantKey($reference['variant']).'|'.$reference['path'].'|'.$reference['url'])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{variant: string|null, path: string, url: string}>  $old
     * @param  array<int, array{variant: string|null, path: string, url: string}>  $new
     * @return array<string, string>
     */
    private function replacementMap(array $old, array $new): array
    {
        $map = [];
        $newByVariant = collect($new)
            ->keyBy(fn (array $reference): string => $this->variantKey($reference['variant']));

        foreach ($old as $oldReference) {
            $newReference = $newByVariant->get($this->variantKey($oldReference['variant']))
                ?? $newByVariant->get($this->variantKey(null));

            if (! is_array($newReference)) {
                continue;
            }

            if ($oldReference['url'] !== '' && $oldReference['url'] !== $newReference['url']) {
                $map[$oldReference['url']] = $newReference['url'];
            }

            if ($oldReference['path'] !== '' && $oldReference['path'] !== $newReference['path']) {
                $map['/storage/'.ltrim($oldReference['path'], '/')] = '/storage/'.ltrim($newReference['path'], '/');
                $map[$oldReference['path']] = $newReference['path'];
            }
        }

        return $map;
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
     * @return array<string, mixed>
     */
    private function auditSnapshot(MediaAsset $asset): array
    {
        return [
            'disk' => $asset->disk,
            'path' => $asset->path,
            'url' => $asset->url,
            'mime_type' => $asset->mime_type,
            'width' => $asset->width,
            'height' => $asset->height,
            'size_bytes' => $asset->size_bytes,
            'original_name' => $asset->metadata['original_name'] ?? null,
            'responsive_variants' => collect($asset->metadata['responsive_variants'] ?? [])
                ->map(fn ($variant): array => [
                    'path' => is_array($variant) ? ($variant['path'] ?? null) : null,
                    'url' => is_array($variant) ? ($variant['url'] ?? null) : null,
                ])
                ->all(),
        ];
    }
}
