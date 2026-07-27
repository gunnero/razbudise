<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Operations\AdminAuditLogger;
use Illuminate\Support\Facades\DB;

class MediaAssetLifecycleService
{
    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
        private readonly MediaAssetDeletionGuard $deletionGuard,
    ) {}

    public function archive(MediaAsset $asset, ?User $actor = null): MediaAsset
    {
        return DB::transaction(function () use ($asset, $actor): MediaAsset {
            /** @var MediaAsset $locked */
            $locked = MediaAsset::query()->lockForUpdate()->findOrFail($asset->getKey());

            if ($locked->archived_at !== null) {
                return $locked;
            }

            $before = ['archived_at' => null, 'archived_by' => null];
            $locked->forceFill([
                'archived_at' => now(),
                'archived_by' => $actor?->getKey() ?? auth()->id(),
            ])->save();

            $this->auditLogger->record(
                action: 'media_asset.archived',
                auditable: $locked,
                actor: $actor,
                before: $before,
                after: [
                    'archived_at' => $locked->archived_at?->toIso8601String(),
                    'archived_by' => $locked->archived_by,
                ],
            );

            return $locked->refresh();
        }, 3);
    }

    public function restore(MediaAsset $asset, ?User $actor = null): MediaAsset
    {
        return DB::transaction(function () use ($asset, $actor): MediaAsset {
            /** @var MediaAsset $locked */
            $locked = MediaAsset::query()->lockForUpdate()->findOrFail($asset->getKey());

            if ($locked->archived_at === null) {
                return $locked;
            }

            $before = [
                'archived_at' => $locked->archived_at->toIso8601String(),
                'archived_by' => $locked->archived_by,
            ];
            $locked->forceFill([
                'archived_at' => null,
                'archived_by' => null,
            ])->save();

            $this->auditLogger->record(
                action: 'media_asset.restored',
                auditable: $locked,
                actor: $actor,
                before: $before,
                after: ['archived_at' => null, 'archived_by' => null],
            );

            return $locked->refresh();
        }, 3);
    }

    public function delete(MediaAsset $asset, ?User $actor = null): void
    {
        DB::transaction(function () use ($asset, $actor): void {
            /** @var MediaAsset $locked */
            $locked = MediaAsset::query()->lockForUpdate()->findOrFail($asset->getKey());
            $summary = $this->deletionGuard->assertCanDelete($locked);

            $this->auditLogger->record(
                action: 'media_asset.deleted',
                auditable: $locked,
                actor: $actor,
                before: [
                    'path' => $locked->path,
                    'url' => $locked->url,
                    'display_name' => $locked->getRawOriginal('display_name'),
                    'title' => $locked->title,
                ],
                metadata: ['usage' => $summary],
            );

            $locked->delete();
        }, 3);
    }
}
