<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Validation\ValidationException;

class MediaAssetDeletionGuard
{
    public function __construct(private readonly MediaAssetUsageService $usage) {}

    /**
     * @return array{total: int, published_content: int, relations: array<string, int>}
     */
    public function assertCanDelete(MediaAsset $asset): array
    {
        if ($this->usage->sharedStorageOwnerCount($asset) > 0) {
            throw ValidationException::withMessages([
                'media_asset' => 'Друга медиумска ставка ја користи истата датотека. Прво спојте ги дупликатите безбедно.',
            ]);
        }

        return $this->assertHasNoReferences($asset);
    }

    /**
     * Assert that every database and embedded reference has been removed.
     * Duplicate merging uses this after transferring references to the
     * canonical row, while shared-file cleanup remains storage-aware.
     *
     * @return array{total: int, published_content: int, relations: array<string, int>}
     */
    public function assertHasNoReferences(MediaAsset $asset): array
    {
        $summary = $this->usage->summary($asset);

        if ($summary['total'] === 0) {
            return $summary;
        }

        $message = $summary['published_content'] > 0
            ? 'Сликата се користи во објавена содржина и не може да се избрише. Архивирајте ја или прво изберете безбедна замена.'
            : 'Сликата сè уште се користи и не може да се избрише. Архивирајте ја или прво отстранете ги сите употреби.';

        throw ValidationException::withMessages([
            'media_asset' => $message,
        ]);
    }

    /**
     * Verify a duplicate after its references have been transferred. Embedded
     * URLs that are also owned by the canonical asset are intentionally not
     * attributed to the duplicate row.
     *
     * @return array{total: int, published_content: int, relations: array<string, int>}
     */
    public function assertCanDeleteMergedDuplicate(MediaAsset $duplicate, MediaAsset $canonical): array
    {
        $uniqueReferences = array_values(array_diff(
            $this->usage->searchableReferences($duplicate),
            $this->usage->searchableReferences($canonical),
        ));

        $probe = clone $duplicate;
        $probe->setAttribute('path', null);
        $probe->setAttribute('url', null);
        $probe->setAttribute('metadata', [
            'legacy_paths' => $uniqueReferences,
        ]);

        return $this->assertHasNoReferences($probe);
    }
}
