<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MediaCleanupService
{
    public function __construct(private readonly MediaVariantGenerator $variantGenerator) {}

    /**
     * @return array{
     *     assets_checked: int,
     *     fixable_assets: int,
     *     fixed_assets: int,
     *     missing_alt_text: int,
     *     missing_dimensions: int,
     *     missing_variants: int,
     *     missing_credit: int,
     *     items: array<int, array<string, mixed>>
     * }
     */
    public function repair(bool $apply = false): array
    {
        $summary = [
            'assets_checked' => 0,
            'fixable_assets' => 0,
            'fixed_assets' => 0,
            'missing_alt_text' => 0,
            'missing_dimensions' => 0,
            'missing_variants' => 0,
            'missing_credit' => 0,
            'items' => [],
        ];

        return $this->repairAssets(
            MediaAsset::query()
                ->where('mime_type', 'like', 'image/%')
                ->orderBy('id')
                ->get(),
            $apply,
            $summary,
        );
    }

    /**
     * @param  iterable<int, MediaAsset>  $assets
     * @param  array<string, mixed>|null  $summary
     * @return array<string, mixed>
     */
    public function repairAssets(iterable $assets, bool $apply = false, ?array $summary = null): array
    {
        $summary ??= [
            'assets_checked' => 0,
            'fixable_assets' => 0,
            'fixed_assets' => 0,
            'missing_alt_text' => 0,
            'missing_dimensions' => 0,
            'missing_variants' => 0,
            'missing_credit' => 0,
            'items' => [],
        ];

        foreach ($assets as $asset) {
            $this->repairAsset($asset, $apply, $summary);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function repairAsset(MediaAsset $asset, bool $apply, array &$summary): void
    {
        $summary['assets_checked']++;
        $changes = [];
        $codes = [];

        if (! str_starts_with((string) $asset->mime_type, 'image/')) {
            return;
        }

        if (! $asset->alt_text && ($altText = $this->fallbackAltText($asset))) {
            $changes['alt_text'] = $altText;
            $codes[] = 'missing_alt_text';
            $summary['missing_alt_text']++;
        }

        $metadata = $asset->metadata ?? [];

        $storedFileExists = $this->storedFileExists($asset);

        if (($asset->width === null || $asset->height === null) && $storedFileExists) {
            $storedMetadata = app(MediaAssetUploader::class)->metadataForStoredPath($asset->disk ?: 'public', $asset->path);

            if (($storedMetadata['width'] ?? null) && ($storedMetadata['height'] ?? null)) {
                $changes['width'] = $storedMetadata['width'];
                $changes['height'] = $storedMetadata['height'];
                $changes['size_bytes'] = $asset->size_bytes ?: $storedMetadata['size_bytes'];
                $codes[] = 'missing_dimensions';
                $summary['missing_dimensions']++;
            }
        }

        if (empty($metadata['responsive_variants']) && $storedFileExists) {
            $variants = $apply ? $this->variantGenerator->generate($asset) : ['pending' => true];

            if ($variants !== []) {
                $metadata['responsive_variants'] = $variants;
                $changes['metadata'] = $metadata;
                $codes[] = 'missing_variants';
                $summary['missing_variants']++;
            }
        }

        if (empty($metadata['credit']) && $this->isImportedWordpressAsset($asset)) {
            $metadata['credit'] = 'RazbudiSe archive';
            $changes['metadata'] = $metadata;
            $codes[] = 'missing_credit';
            $summary['missing_credit']++;
        }

        if ($codes === []) {
            return;
        }

        $summary['fixable_assets']++;
        $summary['items'][] = [
            'media_asset_id' => $asset->id,
            'path' => $asset->path,
            'url' => $asset->url,
            'codes' => array_values(array_unique($codes)),
        ];

        if (! $apply || $changes === []) {
            return;
        }

        $asset->forceFill($changes)->save();
        $summary['fixed_assets']++;
    }

    private function fallbackAltText(MediaAsset $asset): ?string
    {
        $caption = trim((string) $asset->caption);

        if ($caption !== '') {
            return $caption;
        }

        $path = trim((string) $asset->path);

        if ($path === '') {
            return null;
        }

        $name = pathinfo($path, PATHINFO_FILENAME);
        $name = trim(str_replace(['-', '_'], ' ', $name));

        return $name !== '' ? $name : null;
    }

    private function isImportedWordpressAsset(MediaAsset $asset): bool
    {
        $metadata = $asset->metadata ?? [];

        return str_starts_with((string) ($metadata['source'] ?? ''), 'wordpress')
            || str_contains((string) ($metadata['source_url'] ?? ''), '/wp-content/uploads/');
    }

    private function storedFileExists(MediaAsset $asset): bool
    {
        if ($asset->disk === 'external' || ! $asset->path) {
            return false;
        }

        try {
            return Storage::disk($asset->disk ?: 'public')->exists($asset->path);
        } catch (Throwable) {
            return false;
        }
    }
}
