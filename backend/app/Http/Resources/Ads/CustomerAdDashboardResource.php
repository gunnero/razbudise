<?php

namespace App\Http\Resources\Ads;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Services\Ads\AdAvailabilityService;
use App\Services\Ads\AdPricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

class CustomerAdDashboardResource extends JsonResource
{
    private const RENEWABLE_LINE_STATUSES = ['paid', 'approved', 'scheduled', 'active', 'published'];

    private const RENEWABLE_BOOKING_STATUSES = ['paid', 'approved', 'scheduled', 'active', 'published'];

    public function toArray(Request $request): array
    {
        return [
            'customer' => [
                'id' => $this->id,
                'company_name' => $this->company_name,
                'tax_number' => $this->tax_number,
                'contact_name' => $this->contact_name,
                'contact_email' => $this->contact_email,
                'status' => $this->status,
            ],
            'bookings' => $this->bookingRequests->map(fn ($booking): array => [
                'id' => $booking->id,
                'reference' => $booking->reference,
                'status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'rejection_reason' => $booking->rejection_reason,
                'total_denars' => (int) $booking->total_denars,
                'hold_expires_at' => $booking->hold_expires_at?->toISOString(),
                'paid_at' => $booking->paid_at?->toISOString(),
                'items' => $booking->lineItems->map(function ($lineItem) use ($booking): array {
                    $impressions = $this->impressionsFor($lineItem);
                    $clicks = $this->clicksFor($lineItem);
                    $renewalOptions = $this->renewalOptions($lineItem, $booking);

                    return [
                        'id' => $lineItem->id,
                        'zone_name' => $lineItem->zone?->name,
                        'zone_key' => $lineItem->zone?->key,
                        'duration_weeks' => (int) $lineItem->duration_weeks,
                        'start_at' => $lineItem->start_at?->toISOString(),
                        'end_at' => $lineItem->end_at?->toISOString(),
                        'start_date' => $lineItem->start_at?->toDateString(),
                        'end_date' => $lineItem->end_at?->toDateString(),
                        'creative_type' => $lineItem->creative_type,
                        'width' => (int) $lineItem->width,
                        'height' => (int) $lineItem->height,
                        'status' => $lineItem->status,
                        'rejection_reason' => $lineItem->rejection_reason,
                        'technical_review_required' => (bool) $lineItem->technical_review_required,
                        'price_denars' => (int) $lineItem->price_denars,
                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'ctr' => $this->ctrFor($impressions, $clicks),
                        'can_renew' => $this->canRenew($renewalOptions),
                        'renewal_options' => $renewalOptions,
                        'revisions' => $lineItem->revisions->map(fn ($revision): array => [
                            'id' => $revision->id,
                            'status' => $revision->status,
                            'creative_type' => $revision->creative_type,
                            'rejection_reason' => $revision->rejection_reason,
                            'technical_review_required' => (bool) $revision->technical_review_required,
                            'created_at' => $revision->created_at?->toISOString(),
                        ])->values(),
                    ];
                })->values(),
            ])->values(),
            'invoices' => $this->bookingRequests
                ->filter(fn ($booking): bool => (bool) $booking->invoice)
                ->map(fn ($booking): array => [
                    'id' => $booking->invoice->id,
                    'booking_reference' => $booking->reference,
                    'number' => $booking->invoice->number,
                    'type' => $booking->invoice->type,
                    'status' => $booking->invoice->status,
                    'total_denars' => (int) $booking->invoice->total_denars,
                    'issued_at' => $booking->invoice->issued_at?->toISOString(),
                    'due_at' => $booking->invoice->due_at?->toISOString(),
                ])
                ->values(),
        ];
    }

    private function impressionsFor(AdBookingLineItem $lineItem): int
    {
        return (int) ($lineItem->creative?->getAttribute('impressions_count') ?? 0);
    }

    private function clicksFor(AdBookingLineItem $lineItem): int
    {
        return (int) ($lineItem->creative?->getAttribute('clicks_count') ?? 0);
    }

    private function ctrFor(int $impressions, int $clicks): float
    {
        if ($impressions === 0) {
            return 0.0;
        }

        return round(($clicks / $impressions) * 100, 2);
    }

    private function canRenew(array $renewalOptions): bool
    {
        return collect($renewalOptions)->contains(fn (array $option): bool => (bool) $option['available']);
    }

    private function renewalOptions(AdBookingLineItem $lineItem, AdBookingRequest $booking): array
    {
        return collect([1, 2, 4])
            ->map(fn (int $durationWeeks): array => $this->renewalOption($lineItem, $booking, $durationWeeks))
            ->all();
    }

    private function renewalOption(AdBookingLineItem $lineItem, AdBookingRequest $booking, int $durationWeeks): array
    {
        $base = [
            'duration_weeks' => $durationWeeks,
            'available' => false,
            'price_denars' => null,
        ];

        $price = $this->renewalPrice($lineItem, $durationWeeks);

        if ($price !== null) {
            $base['price_denars'] = $price;
        }

        if (! $this->hasRenewableSource($lineItem, $booking)) {
            return $base;
        }

        $available = app(AdAvailabilityService::class)->isAvailable(
            $lineItem->zone,
            $lineItem->end_at,
            $lineItem->end_at->copy()->addWeeks($durationWeeks),
        );

        return [
            ...$base,
            'available' => $available && $price !== null,
        ];
    }

    private function hasRenewableSource(AdBookingLineItem $lineItem, AdBookingRequest $booking): bool
    {
        if (
            ! in_array($lineItem->status, self::RENEWABLE_LINE_STATUSES, true)
            || ! in_array($booking->status, self::RENEWABLE_BOOKING_STATUSES, true)
            || $booking->payment_status !== 'paid'
            || ! $lineItem->zone
            || ! $lineItem->end_at
        ) {
            return false;
        }

        return true;
    }

    private function renewalPrice(AdBookingLineItem $lineItem, int $durationWeeks): ?int
    {
        if (! $lineItem->zone) {
            return null;
        }

        try {
            $pricedLine = app(AdPricingService::class)->priceLine(
                $lineItem->zone,
                $durationWeeks,
                (string) $lineItem->creative_type,
                (bool) $lineItem->is_political_or_sensitive,
            );
        } catch (ValidationException) {
            return null;
        }

        return (int) $pricedLine['price_denars'];
    }
}
