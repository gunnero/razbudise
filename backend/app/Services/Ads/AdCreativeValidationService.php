<?php

namespace App\Services\Ads;

use App\Models\AdZone;
use App\Models\MediaAsset;
use Illuminate\Validation\ValidationException;

class AdCreativeValidationService
{
    public function validateImageDimensions(AdZone $zone, MediaAsset $image): void
    {
        $actual = [(int) $image->width, (int) $image->height];

        foreach ($this->allowedSizes($zone) as $allowedSize) {
            if ($allowedSize === $actual) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'image_id' => sprintf(
                'Image dimensions must match one of the required zone sizes: %s. Actual size: %dx%d.',
                $this->formatSizes($this->allowedSizes($zone)),
                $actual[0],
                $actual[1],
            ),
        ]);
    }

    public function requiresTechnicalReview(string $creativeType): bool
    {
        return in_array(strtolower($creativeType), ['html', 'script'], true);
    }

    public function allowedSizes(AdZone $zone): array
    {
        return collect($zone->allowed_sizes ?? [])
            ->map(function (mixed $size): ?array {
                if (! is_array($size)) {
                    return null;
                }

                if (
                    array_key_exists('width', $size)
                    && array_key_exists('height', $size)
                    && is_numeric($size['width'])
                    && is_numeric($size['height'])
                ) {
                    return [(int) $size['width'], (int) $size['height']];
                }

                if (! array_key_exists(0, $size) || ! array_key_exists(1, $size)) {
                    return null;
                }

                if (! is_numeric($size[0]) || ! is_numeric($size[1])) {
                    return null;
                }

                return [(int) $size[0], (int) $size[1]];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function formatSizes(array $sizes): string
    {
        return collect($sizes)
            ->map(fn (array $size): string => "{$size[0]}x{$size[1]}")
            ->implode(', ');
    }
}
