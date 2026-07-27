<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;

class MediaAssetStorageService
{
    /**
     * Return every file owned by the asset, including compatibility aliases
     * retained by safe filename changes.
     *
     * @return array<int, string>
     */
    public function paths(MediaAsset $asset): array
    {
        $variantPaths = collect($asset->metadata['responsive_variants'] ?? [])
            ->pluck('path');
        $legacyPaths = collect($asset->metadata['legacy_paths'] ?? [])
            ->map(fn ($path): ?string => is_array($path) ? ($path['path'] ?? null) : $path);

        return $variantPaths
            ->merge($legacyPaths)
            ->push($asset->path)
            ->filter(fn ($path): bool => is_string($path) && $path !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function deleteFiles(MediaAsset $asset): bool
    {
        if (($asset->disk ?: 'public') === 'external') {
            return true;
        }

        $paths = $this->unsharedPaths($asset);

        return $paths === [] || Storage::disk($asset->disk ?: 'public')->delete($paths);
    }

    /**
     * Only return files that are not owned by another media row. This makes
     * cleanup safe for historic duplicate rows that point at the same file.
     *
     * @return array<int, string>
     */
    public function unsharedPaths(MediaAsset $asset): array
    {
        $paths = $this->paths($asset);

        if ($paths === []) {
            return [];
        }

        $sharedPaths = MediaAsset::query()
            ->whereKeyNot($asset->getKey())
            ->where('disk', $asset->disk ?: 'public')
            ->get()
            ->flatMap(fn (MediaAsset $candidate): array => $this->paths($candidate))
            ->unique()
            ->all();

        return array_values(array_diff($paths, $sharedPaths));
    }
}
