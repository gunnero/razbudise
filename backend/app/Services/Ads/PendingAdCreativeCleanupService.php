<?php

namespace App\Services\Ads;

use App\Models\MediaAsset;
use App\Services\Media\MediaAssetLifecycleService;
use Illuminate\Validation\ValidationException;

class PendingAdCreativeCleanupService
{
    public function __construct(
        private readonly MediaAssetLifecycleService $mediaLifecycle,
        private readonly PublicAdCreativeClaimService $creativeClaims,
    ) {}

    public function prune(int $olderThanHours = 24): int
    {
        $deleted = $this->creativeClaims->pruneExpired();

        MediaAsset::query()
            ->whereNull('uploaded_by')
            ->where('metadata->purpose', 'ad_creative')
            ->where('metadata->public_booking_upload_status', 'pending')
            ->where('created_at', '<', now()->subHours($olderThanHours))
            ->whereDoesntHave('adBookingLineItems')
            ->whereDoesntHave('adCreativeUploadClaims')
            ->chunkById(100, function ($assets) use (&$deleted): void {
                foreach ($assets as $asset) {
                    try {
                        $this->mediaLifecycle->delete($asset);
                        $deleted++;
                    } catch (ValidationException) {
                        // A newly discovered reference wins over cleanup.
                    }
                }
            });

        return $deleted;
    }
}
