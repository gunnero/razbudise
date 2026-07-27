<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MediaVariantGenerator
{
    public const int MAX_IMAGE_PIXELS = 40_000_000;

    public const int MAX_IMAGE_SIDE = 12_000;

    private const VARIANTS = [
        'thumbnail' => 320,
        'medium' => 768,
        'large' => 1280,
    ];

    /**
     * @return array<string, array{path: string, url: string, width: int|null, height: int|null}>
     */
    public function generate(MediaAsset $asset): array
    {
        if (! str_starts_with((string) $asset->mime_type, 'image/') || ! $asset->path) {
            return [];
        }

        if ($asset->width && $asset->height && ! $this->dimensionsWithinLimits((int) $asset->width, (int) $asset->height)) {
            return [];
        }

        $disk = Storage::disk($asset->disk ?: 'public');

        if (! $disk->exists($asset->path)) {
            return [];
        }

        $sourcePath = method_exists($disk, 'path') ? $disk->path($asset->path) : null;
        $dimensions = $sourcePath ? @getimagesize($sourcePath) : null;

        if (is_array($dimensions)
            && ! $this->dimensionsWithinLimits((int) ($dimensions[0] ?? 0), (int) ($dimensions[1] ?? 0))) {
            return [];
        }

        $plannedPaths = $this->plannedPaths($asset->path);
        $preexistingPaths = collect($plannedPaths)
            ->filter(fn (string $path): bool => $disk->exists($path))
            ->values()
            ->all();

        if ($preexistingPaths !== []) {
            throw new RuntimeException(
                'Media derivative generation was aborted because one or more target paths already exist.',
            );
        }

        $variants = [];

        try {
            foreach ($plannedPaths as $name => $variantPath) {
                $targetWidth = self::VARIANTS[$name];
                $variant = $this->writeVariant($asset, $variantPath, $targetWidth);

                if ($variant === null) {
                    continue;
                }

                if (! $disk->exists($variantPath)) {
                    throw new RuntimeException("Media derivative was reported as stored but is missing: {$variantPath}");
                }

                [$width, $height] = $variant;

                $variants[$name] = [
                    'path' => $variantPath,
                    'url' => $disk->url($variantPath),
                    'width' => $width,
                    'height' => $height,
                ];
            }

            return $variants;
        } catch (\Throwable $exception) {
            $newlyCreatedPaths = collect($plannedPaths)
                ->reject(fn (string $path): bool => in_array($path, $preexistingPaths, true))
                ->filter(fn (string $path): bool => $disk->exists($path))
                ->values()
                ->all();

            if ($newlyCreatedPaths !== []) {
                $disk->delete($newlyCreatedPaths);
            }

            throw $exception;
        }
    }

    /**
     * Return the complete deterministic derivative manifest before any file is
     * written so callers can compensate even when generation fails midway.
     *
     * @return array<string, string>
     */
    public function plannedPaths(string $sourcePath): array
    {
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'jpg';
        $baseName = Str::slug(pathinfo($sourcePath, PATHINFO_FILENAME)) ?: hash('sha256', $sourcePath);
        $directory = trim(pathinfo($sourcePath, PATHINFO_DIRNAME), '.');

        return collect(self::VARIANTS)
            ->mapWithKeys(fn (int $targetWidth, string $name): array => [
                $name => trim($directory.'/variants/'.$baseName.'-'.$name.'.'.$extension, '/'),
            ])
            ->all();
    }

    /**
     * @return array{int|null, int|null}|null
     */
    protected function writeVariant(MediaAsset $asset, string $variantPath, int $targetWidth): ?array
    {
        $disk = Storage::disk($asset->disk ?: 'public');
        $sourcePath = method_exists($disk, 'path') ? $disk->path($asset->path) : null;
        $variantLocalPath = method_exists($disk, 'path') ? $disk->path($variantPath) : null;

        if ($sourcePath && $variantLocalPath && extension_loaded('gd')) {
            $dimensions = @getimagesize($sourcePath);

            if ($dimensions && $this->dimensionsWithinLimits((int) ($dimensions[0] ?? 0), (int) ($dimensions[1] ?? 0))) {
                [$sourceWidth, $sourceHeight] = $dimensions;
                $width = min($targetWidth, (int) $sourceWidth);
                $height = max(1, (int) round(((int) $sourceHeight) * ($width / (int) $sourceWidth)));

                if ($this->resizeImage($sourcePath, $variantLocalPath, (string) $asset->mime_type, $width, $height)) {
                    return [$width, $height];
                }
            }
        }

        $contents = $disk->get($asset->path);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        if (! $disk->put($variantPath, $contents) || ! $disk->exists($variantPath)) {
            throw new RuntimeException("Media derivative could not be stored: {$variantPath}");
        }

        return [
            $asset->width ? min($targetWidth, (int) $asset->width) : null,
            $asset->width && $asset->height
                ? max(1, (int) round(((int) $asset->height) * (min($targetWidth, (int) $asset->width) / (int) $asset->width)))
                : null,
        ];
    }

    public function dimensionsWithinLimits(int $width, int $height): bool
    {
        return $width > 0
            && $height > 0
            && $width <= self::MAX_IMAGE_SIDE
            && $height <= self::MAX_IMAGE_SIDE
            && $width * $height <= self::MAX_IMAGE_PIXELS;
    }

    private function resizeImage(string $sourcePath, string $variantPath, string $mimeType, int $width, int $height): bool
    {
        $source = match ($mimeType) {
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($sourcePath) : false,
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            'image/gif' => @imagecreatefromgif($sourcePath),
            default => false,
        };

        if (! $source) {
            return false;
        }

        $target = imagescale($source, $width, $height);

        if (! $target) {
            imagedestroy($source);

            return false;
        }

        $directory = dirname($variantPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $saved = match ($mimeType) {
            'image/avif' => function_exists('imageavif') ? imageavif($target, $variantPath, 65) : false,
            'image/png' => imagepng($target, $variantPath),
            'image/webp' => function_exists('imagewebp') ? imagewebp($target, $variantPath) : false,
            'image/gif' => imagegif($target, $variantPath),
            default => imagejpeg($target, $variantPath, 82),
        };

        imagedestroy($target);
        imagedestroy($source);

        return (bool) $saved;
    }
}
