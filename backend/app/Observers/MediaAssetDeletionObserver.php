<?php

namespace App\Observers;

use App\Models\MediaAsset;
use App\Services\Media\MediaAssetDeletionGuard;
use App\Services\Media\MediaAssetReferenceAliasService;
use App\Services\Media\MediaAssetStorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MediaAssetDeletionObserver
{
    public function saved(MediaAsset $asset): void
    {
        if (Schema::hasTable('media_asset_reference_aliases')) {
            app(MediaAssetReferenceAliasService::class)->sync($asset);
        }
    }

    public function deleting(MediaAsset $asset): void
    {
        app(MediaAssetDeletionGuard::class)->assertCanDelete($asset);
    }

    public function deleted(MediaAsset $asset): void
    {
        DB::afterCommit(function () use ($asset): void {
            if (! app(MediaAssetStorageService::class)->deleteFiles($asset)) {
                Log::warning('Media asset database record was deleted but one or more files remain.', [
                    'media_asset_id' => $asset->getKey(),
                    'disk' => $asset->disk,
                    'paths' => app(MediaAssetStorageService::class)->paths($asset),
                ]);
            }
        });
    }
}
