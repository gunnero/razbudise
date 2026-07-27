<?php

namespace App\Services\Media;

use App\Models\MediaAsset;

class MediaSeoFilenameSuggester
{
    private const MAX_WORDS = 8;

    private const MAX_STEM_LENGTH = 96;

    /** @var array<string, string> */
    private const MACEDONIAN_ASCII = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'ѓ' => 'gj', 'е' => 'e', 'ж' => 'zh', 'з' => 'z', 'ѕ' => 'dz',
        'и' => 'i', 'ј' => 'j', 'к' => 'k', 'л' => 'l', 'љ' => 'lj',
        'м' => 'm', 'н' => 'n', 'њ' => 'nj', 'о' => 'o', 'п' => 'p',
        'р' => 'r', 'с' => 's', 'т' => 't', 'ќ' => 'kj', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'џ' => 'dz',
        'ш' => 'sh',
    ];

    /** @var array<int, string> */
    private const GENERIC_STEMS = [
        'dsc',
        'image',
        'img',
        'photo',
        'picture',
        'screenshot',
        'slika',
        'untitled',
    ];

    public function suggest(MediaAsset $asset): ?string
    {
        return $this->suggestFrom([
            'title' => $asset->title,
            'alt_text' => $asset->alt_text,
            'display_name' => $asset->getRawOriginal('display_name'),
            'caption' => $asset->caption,
            'description' => $asset->description,
            'original_name' => $asset->metadata['original_name'] ?? null,
        ], $asset->path, $asset->mime_type);
    }

    /**
     * Use one best available description instead of concatenating metadata.
     * This keeps suggestions readable and avoids keyword-stuffed filenames.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function suggestFrom(array $attributes, ?string $path = null, ?string $mimeType = null): ?string
    {
        $candidates = [
            $attributes['title'] ?? null,
            $attributes['alt_text'] ?? null,
            $attributes['display_name'] ?? null,
            $attributes['caption'] ?? null,
            $attributes['description'] ?? null,
            $attributes['original_name'] ?? null,
            $path ? basename($path) : null,
        ];

        foreach ($candidates as $candidate) {
            $stem = $this->descriptiveStem($candidate);

            if ($stem === null) {
                continue;
            }

            $extension = $this->extension($path, $mimeType, $attributes['original_name'] ?? null);

            return $extension === null ? $stem : $stem.'.'.$extension;
        }

        return null;
    }

    private function descriptiveStem(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\.(?:avif|gif|jpe?g|png|svg|webp)$/iu', '', $value) ?? $value;
        $value = mb_strtolower($value, 'UTF-8');
        $value = strtr($value, self::MACEDONIAN_ASCII);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        $tokens = collect(explode('-', trim($value, '-')))
            ->filter()
            ->unique()
            ->take(self::MAX_WORDS)
            ->values();
        $stem = $tokens->implode('-');

        if ($stem === '' || $this->isGenericStem($stem)) {
            return null;
        }

        if (strlen($stem) > self::MAX_STEM_LENGTH) {
            $stem = rtrim(substr($stem, 0, self::MAX_STEM_LENGTH), '-');
        }

        return $stem === '' ? null : $stem;
    }

    private function isGenericStem(string $stem): bool
    {
        $withoutNumericSuffix = preg_replace('/[-_]?\d+$/', '', $stem) ?? $stem;

        return in_array($withoutNumericSuffix, self::GENERIC_STEMS, true)
            || ctype_digit(str_replace('-', '', $stem))
            || preg_match('/^[a-f0-9]{12,}$/', str_replace('-', '', $stem)) === 1;
    }

    private function extension(?string $path, ?string $mimeType, mixed $originalName): ?string
    {
        foreach ([$path, is_string($originalName) ? $originalName : null] as $filename) {
            $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));

            if (preg_match('/^[a-z0-9]{2,5}$/', $extension) === 1) {
                return $extension;
            }
        }

        return match (strtolower((string) $mimeType)) {
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/svg+xml' => 'svg',
            'image/webp' => 'webp',
            default => null,
        };
    }
}
