<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MediaQualityReportService
{
    private const EXPECTED_VARIANTS = ['thumbnail', 'medium', 'large'];

    /**
     * @return array{
     *     totals: array<string, int>,
     *     issues: array<int, array<string, mixed>>
     * }
     */
    public function summary(): array
    {
        $issues = [];

        MediaAsset::query()
            ->orderBy('id')
            ->each(function (MediaAsset $asset) use (&$issues): void {
                $isImage = str_starts_with((string) $asset->mime_type, 'image/');

                if ($isImage && ! $asset->alt_text) {
                    $issues[] = $this->issue($asset, 'missing_alt_text', 'Image is missing alt text.');
                }

                if ($isImage && (! $asset->width || ! $asset->height)) {
                    $issues[] = $this->issue($asset, 'missing_dimensions', 'Image is missing width or height metadata.');
                }

                if ($this->isStoredImage($asset) && ! $this->fileExists($asset)) {
                    $issues[] = $this->issue($asset, 'missing_file', 'Stored image file does not exist on its configured disk.');
                }

                if ($this->isStoredImage($asset)) {
                    $variantIssues = $this->responsiveVariantIssues($asset);

                    if ($variantIssues !== []) {
                        $issues[] = $this->issue(
                            $asset,
                            'missing_variants',
                            'Stored image has missing, stale, or invalid responsive variants.',
                            ['variant_issues' => $variantIssues],
                        );
                    }
                }

                if (str_starts_with((string) $asset->mime_type, 'image/') && empty($asset->metadata['credit'])) {
                    $issues[] = $this->issue($asset, 'missing_credit', 'Image is missing credit metadata.');
                }
            });

        foreach ($this->duplicateSourceUrlIssues() as $issue) {
            $issues[] = $issue;
        }

        foreach ($this->duplicateStoragePathIssues() as $issue) {
            $issues[] = $issue;
        }

        $issueCounts = collect($issues)->countBy('code');

        return [
            'totals' => [
                'assets_checked' => MediaAsset::query()->count(),
                'issues' => count($issues),
                'missing_alt_text' => (int) $issueCounts->get('missing_alt_text', 0),
                'missing_dimensions' => (int) $issueCounts->get('missing_dimensions', 0),
                'missing_file' => (int) $issueCounts->get('missing_file', 0),
                'missing_variants' => (int) $issueCounts->get('missing_variants', 0),
                'missing_credit' => (int) $issueCounts->get('missing_credit', 0),
                'duplicate_source_urls' => (int) $issueCounts->get('duplicate_source_url', 0),
                'duplicate_storage_paths' => (int) $issueCounts->get('duplicate_storage_path', 0),
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(): array
    {
        return collect($this->summary()['issues'])
            ->map(fn (array $issue): array => [
                'issue' => $issue['code'],
                'media_asset_id' => $issue['media_asset_id'],
                'path' => $issue['path'],
                'url' => $issue['url'],
                'message' => $issue['message'],
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function duplicateSourceUrlIssues(): array
    {
        return MediaAsset::query()
            ->whereNotNull('metadata')
            ->get()
            ->filter(fn (MediaAsset $asset): bool => filled($asset->metadata['source_url'] ?? null))
            ->groupBy(fn (MediaAsset $asset): string => (string) $asset->metadata['source_url'])
            ->filter(fn ($group): bool => $group->count() > 1)
            ->map(fn ($group, string $sourceUrl): array => [
                'code' => 'duplicate_source_url',
                'message' => 'Multiple media assets share the same source URL.',
                'media_asset_id' => $group->first()->id,
                'path' => $group->first()->path,
                'url' => $group->first()->url,
                'source_url' => $sourceUrl,
                'media_asset_ids' => $group->pluck('id')->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function duplicateStoragePathIssues(): array
    {
        return MediaAsset::query()
            ->whereNotNull('path')
            ->get()
            ->groupBy(fn (MediaAsset $asset): string => ($asset->disk ?: 'public').':'.$asset->path)
            ->filter(fn ($group): bool => $group->count() > 1)
            ->map(fn ($group): array => [
                'code' => 'duplicate_storage_path',
                'message' => 'Multiple media assets point to the same storage path.',
                'media_asset_id' => $group->first()->id,
                'path' => $group->first()->path,
                'url' => $group->first()->url,
                'media_asset_ids' => $group->pluck('id')->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function isStoredImage(MediaAsset $asset): bool
    {
        return str_starts_with((string) $asset->mime_type, 'image/')
            && $asset->disk !== 'external'
            && filled($asset->path);
    }

    private function fileExists(MediaAsset $asset): bool
    {
        try {
            return Storage::disk($asset->disk ?: 'public')->exists($asset->path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function responsiveVariantIssues(MediaAsset $asset): array
    {
        $variants = $asset->metadata['responsive_variants'] ?? null;

        if (! is_array($variants) || $variants === []) {
            return ['responsive_variants:missing_metadata'];
        }

        $issues = [];

        foreach (self::EXPECTED_VARIANTS as $name) {
            if (! array_key_exists($name, $variants)) {
                $issues[] = "{$name}:missing_metadata";
            }
        }

        foreach ($variants as $name => $variant) {
            $name = is_string($name) && $name !== '' ? $name : 'unnamed';

            if (! is_array($variant)) {
                $issues[] = "{$name}:invalid_metadata";

                continue;
            }

            $path = $variant['path'] ?? null;

            if (! is_string($path) || ! $this->isSafeStoragePath($path)) {
                $issues[] = "{$name}:invalid_path";
            } elseif (! $this->variantFileExists($asset, $path)) {
                $issues[] = "{$name}:missing_file";
            }

            if (! $this->variantDimensionsAreValid($variant)) {
                $issues[] = "{$name}:invalid_dimensions";
            }
        }

        return array_values(array_unique($issues));
    }

    private function isSafeStoragePath(string $path): bool
    {
        $path = trim($path);

        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1) {
            return false;
        }

        return ! in_array('..', explode('/', $path), true);
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    private function variantDimensionsAreValid(array $variant): bool
    {
        $width = $variant['width'] ?? null;
        $height = $variant['height'] ?? null;

        foreach ([$width, $height] as $dimension) {
            if ($dimension !== null && (! is_int($dimension) || $dimension <= 0 || $dimension > MediaVariantGenerator::MAX_IMAGE_SIDE)) {
                return false;
            }
        }

        return ! (is_int($width)
            && is_int($height)
            && $width * $height > MediaVariantGenerator::MAX_IMAGE_PIXELS);
    }

    private function variantFileExists(MediaAsset $asset, string $path): bool
    {
        try {
            return Storage::disk($asset->disk ?: 'public')->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(MediaAsset $asset, string $code, string $message, array $details = []): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'media_asset_id' => $asset->id,
            'path' => $asset->path,
            'url' => $asset->url,
            ...$details,
        ];
    }
}
