<?php

namespace App\Services\Ads;

use App\Models\AdCustomer;
use App\Models\MediaAsset;
use App\Services\Media\MediaAssetLifecycleService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdCustomerLifecycleService
{
    private const BLOCKING_LINE_ITEM_STATUSES = ['paid', 'approved', 'scheduled', 'active', 'published'];

    public function __construct(private readonly MediaAssetLifecycleService $mediaLifecycle) {}

    public function deactivateInactiveCustomers(CarbonInterface $cutoff): int
    {
        $count = 0;

        $this->inactiveCustomerQuery($cutoff)
            ->select('ad_customers.*')
            ->orderBy('id')
            ->eachById(function (AdCustomer $customer) use ($cutoff, &$count): void {
                DB::transaction(function () use ($customer, $cutoff, &$count): void {
                    $lockedCustomer = AdCustomer::query()
                        ->whereKey($customer->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedCustomer) {
                        return;
                    }

                    $now = now();
                    $updated = $this->inactiveCustomerQuery($cutoff)
                        ->whereKey($lockedCustomer->id)
                        ->update([
                            'status' => 'former',
                            'dashboard_deactivated_at' => $lockedCustomer->dashboard_deactivated_at ?: $now,
                            'former_customer_at' => $lockedCustomer->former_customer_at ?: $now,
                            'updated_at' => $now,
                        ]);

                    $count += $updated;
                });
            });

        return $count;
    }

    public function purgeExpiredDraftAssets(CarbonInterface $cutoff): int
    {
        $count = 0;

        MediaAsset::query()
            ->where('created_at', '<=', $cutoff)
            ->where(function (Builder $query): void {
                $query
                    ->where('metadata->purpose', 'ad_creative')
                    ->orWhere('metadata->ad_creative_upload', true);
            })
            ->whereDoesntHave('featuredArticles')
            ->whereDoesntHave('avatarAuthors')
            ->whereNotIn('id', fn ($query) => $query
                ->select('image_id')
                ->from('ad_booking_line_items')
                ->whereNotNull('image_id'))
            ->whereNotIn('id', fn ($query) => $query
                ->select('image_id')
                ->from('ad_creative_revisions')
                ->whereNotNull('image_id'))
            ->whereNotIn('id', fn ($query) => $query
                ->select('image_id')
                ->from('ad_creatives')
                ->whereNotNull('image_id'))
            ->orderBy('id')
            ->eachById(function (MediaAsset $asset) use (&$count): void {
                try {
                    $this->mediaLifecycle->delete($asset);
                    $count++;
                } catch (ValidationException) {
                    // A newly discovered reference wins over cleanup.
                }
            });

        return $count;
    }

    public function markFormerCustomer(AdCustomer $customer): void
    {
        $now = now();

        $customer->forceFill([
            'status' => 'former',
            'dashboard_deactivated_at' => $customer->dashboard_deactivated_at ?: $now,
            'former_customer_at' => $customer->former_customer_at ?: $now,
        ])->save();
    }

    public function countInactiveCustomers(CarbonInterface $cutoff): int
    {
        return $this->inactiveCustomerQuery($cutoff)->count();
    }

    private function inactiveCustomerQuery(CarbonInterface $cutoff): Builder
    {
        return AdCustomer::query()
            ->where('status', 'active')
            ->whereNotNull('last_active_at')
            ->where('last_active_at', '<=', $cutoff)
            ->whereDoesntHave('bookingRequests.lineItems', function (Builder $query): void {
                $query
                    ->whereIn('ad_booking_line_items.status', self::BLOCKING_LINE_ITEM_STATUSES)
                    ->where('ad_booking_line_items.end_at', '>=', now());
            });
    }
}
