<?php

namespace App\Services\Ads;

use App\Models\AdBookingLineItem;
use App\Models\AdZone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AdAvailabilityService
{
    private const BLOCKING_LINE_ITEM_STATUSES = ['paid', 'approved', 'scheduled', 'active', 'published'];

    public function isAvailable(
        AdZone $zone,
        CarbonInterface $start,
        CarbonInterface $end,
        ?AdBookingLineItem $ignore = null,
    ): bool {
        return $this->blockingLineItems($zone, $start, $end, $ignore)->doesntExist();
    }

    public function nextAvailableStart(AdZone $zone, CarbonInterface $desiredStart, int $durationWeeks): CarbonInterface
    {
        $start = $desiredStart->copy();
        $end = $start->copy()->addWeeks($durationWeeks);

        while (! $this->isAvailable($zone, $start, $end)) {
            $blockingItem = $this->blockingLineItems($zone, $start, $end)
                ->orderByDesc('end_at')
                ->first();

            if (! $blockingItem) {
                break;
            }

            $start = $blockingItem->end_at->copy();
            $end = $start->copy()->addWeeks($durationWeeks);
        }

        return $start;
    }

    public function assertAvailable(AdZone $zone, CarbonInterface $start, CarbonInterface $end): void
    {
        if ($this->isAvailable($zone, $start, $end)) {
            return;
        }

        throw ValidationException::withMessages([
            'ad_zone_id' => 'Selected ad zone is not available for the requested dates.',
        ]);
    }

    private function blockingLineItems(
        AdZone $zone,
        CarbonInterface $start,
        CarbonInterface $end,
        ?AdBookingLineItem $ignore = null,
    ): Builder {
        return AdBookingLineItem::query()
            ->where('ad_zone_id', $zone->id)
            ->when($ignore, fn (Builder $query): Builder => $query->whereKeyNot($ignore->id))
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('status', self::BLOCKING_LINE_ITEM_STATUSES)
                    ->orWhereHas('bookingRequest', function (Builder $query): void {
                        $query
                            ->whereIn('status', ['pending_review', 'needs_payment', 'paid'])
                            ->where('hold_expires_at', '>', now());
                    });
            });
    }
}
