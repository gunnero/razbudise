<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaAssetUploader
{
    public function __construct(
        private readonly MediaVariantGenerator $variants,
        private readonly MediaSeoFilenameSuggester $filenameSuggester,
    ) {}

    public function upload(UploadedFile $file, array $attributes = [], string $disk = 'public'): MediaAsset
    {
        $path = $file->store('media', $disk);

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => 'The media file could not be stored.']);
        }

        try {
            $metadata = $this->metadataForStoredPath($disk, $path);

            if (is_int($metadata['width'])
                && is_int($metadata['height'])
                && ! $this->variants->dimensionsWithinLimits($metadata['width'], $metadata['height'])) {
                throw ValidationException::withMessages([
                    'file' => sprintf(
                        'Image dimensions exceed limits. Maximum side is %d pixels and maximum image area is %d pixels.',
                        MediaVariantGenerator::MAX_IMAGE_SIDE,
                        MediaVariantGenerator::MAX_IMAGE_PIXELS,
                    ),
                ]);
            }

            return $this->registerStoredPath($path, [
                ...$attributes,
                'original_name' => $file->getClientOriginalName(),
            ], $disk);
        } catch (Throwable $exception) {
            $this->deletePaths($disk, [$path]);

            throw $exception;
        }
    }

    public function registerStoredPath(string $path, array $attributes = [], string $disk = 'public'): MediaAsset
    {
        $filesystem = Storage::disk($disk);
        $plannedVariantPaths = array_values($this->variants->plannedPaths($path));
        $cleanupPaths = collect($plannedVariantPaths)
            ->reject(fn (string $variantPath): bool => $filesystem->exists($variantPath))
            ->values()
            ->all();
        $conflictingVariantPath = collect($plannedVariantPaths)
            ->first(fn (string $variantPath): bool => $filesystem->exists($variantPath));

        if (! MediaAsset::query()->where('disk', $disk)->where('path', $path)->exists()) {
            $cleanupPaths[] = $path;
        }

        try {
            if (is_string($conflictingVariantPath)) {
                throw ValidationException::withMessages([
                    'file' => 'A derivative path for this upload already exists. Please retry the upload.',
                ]);
            }

            return DB::transaction(function () use ($path, $attributes, $disk): MediaAsset {
                $metadata = $this->metadataForStoredPath($disk, $path);

                $automaticSuggestion = $this->filenameSuggester->suggestFrom(
                    $attributes,
                    $path,
                    $metadata['mime_type'],
                );
                $submittedSuggestion = filled($attributes['seo_filename_suggestion'] ?? null)
                    ? trim((string) $attributes['seo_filename_suggestion'])
                    : null;
                $hasManualSuggestion = $submittedSuggestion !== null
                    && $submittedSuggestion !== $automaticSuggestion;
                $suggestedFilename = $hasManualSuggestion
                    ? $submittedSuggestion
                    : $automaticSuggestion;

                $asset = MediaAsset::query()->create([
                    'disk' => $disk,
                    'path' => $path,
                    'url' => $metadata['url'],
                    'mime_type' => $metadata['mime_type'],
                    'display_name' => $attributes['display_name'] ?? null,
                    'title' => $attributes['title'] ?? null,
                    'alt_text' => $attributes['alt_text'] ?? null,
                    'caption' => $attributes['caption'] ?? null,
                    'description' => $attributes['description'] ?? null,
                    'photographer' => $attributes['photographer'] ?? null,
                    'source_name' => $attributes['source_name'] ?? null,
                    'license' => $attributes['license'] ?? null,
                    'copyright_notice' => $attributes['copyright_notice'] ?? null,
                    'seo_filename_suggestion' => $suggestedFilename,
                    'usage_notes' => $attributes['usage_notes'] ?? null,
                    'width' => $metadata['width'],
                    'height' => $metadata['height'],
                    'size_bytes' => $metadata['size_bytes'],
                    'uploaded_by' => $attributes['uploaded_by'] ?? null,
                    'metadata' => array_filter([
                        'original_name' => $attributes['original_name'] ?? basename($path),
                        'credit' => $attributes['photo_credit'] ?? $attributes['credit'] ?? null,
                        'source_url' => $attributes['source_url'] ?? null,
                        'focal_point' => $this->focalPoint($attributes),
                        'purpose' => $this->purpose($attributes),
                        'ad_creative_upload' => ($attributes['purpose'] ?? null) === 'ad_creative' ? true : null,
                        'content_sha256' => $attributes['content_sha256'] ?? null,
                        'seo_filename_suggestion_generated' => ! $hasManualSuggestion && $suggestedFilename !== null ? true : null,
                        'public_booking_upload_token' => $attributes['public_booking_upload_token'] ?? null,
                        'public_booking_upload_status' => $attributes['public_booking_upload_status'] ?? null,
                    ], fn ($value) => $value !== null && $value !== ''),
                ]);

                $responsiveVariants = $this->variants->generate($asset);

                if ($responsiveVariants !== []) {
                    $asset->forceFill([
                        'metadata' => array_filter(
                            ($asset->metadata ?? []) + ['responsive_variants' => $responsiveVariants],
                            fn ($value) => $value !== null && $value !== '',
                        ),
                    ])->save();
                }

                return $asset->refresh();
            }, 3);
        } catch (Throwable $exception) {
            $this->deletePaths($disk, array_values(array_unique($cleanupPaths)));

            throw $exception;
        }
    }

    /**
     * @return array{url: string, mime_type: string|null, width: int|null, height: int|null, size_bytes: int|null}
     */
    public function metadataForStoredPath(string $disk, string $path): array
    {
        $filesystem = Storage::disk($disk);
        $localPath = method_exists($filesystem, 'path') ? $filesystem->path($path) : null;
        $dimensions = $localPath ? @getimagesize($localPath) : null;

        return [
            'url' => $filesystem->url($path),
            'mime_type' => $filesystem->mimeType($path) ?: null,
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'size_bytes' => $filesystem->size($path) ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{x: int, y: int}|null
     */
    private function focalPoint(array $attributes): ?array
    {
        if (! isset($attributes['focal_point_x'], $attributes['focal_point_y'])) {
            return null;
        }

        return [
            'x' => max(0, min(100, (int) $attributes['focal_point_x'])),
            'y' => max(0, min(100, (int) $attributes['focal_point_y'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function purpose(array $attributes): ?string
    {
        $purpose = $attributes['purpose'] ?? null;

        return in_array($purpose, ['ad_creative', 'author_profile', 'team_profile'], true) ? $purpose : null;
    }

    /** @param array<int, string> $paths */
    private function deletePaths(string $disk, array $paths): void
    {
        try {
            Storage::disk($disk)->delete($paths);
        } catch (Throwable $exception) {
            Log::warning('A failed media registration left files requiring cleanup.', [
                'disk' => $disk,
                'paths' => $paths,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
