<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdZone;
use App\Services\Ads\AdAvailabilityService;
use App\Services\Ads\AdPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerAdRenewalController extends Controller
{
    private const RENEWABLE_LINE_STATUSES = ['paid', 'approved', 'scheduled', 'active', 'published'];

    private const RENEWABLE_BOOKING_STATUSES = ['paid', 'approved', 'scheduled', 'active', 'published'];

    public function store(
        Request $request,
        AdBookingLineItem $lineItem,
        AdAvailabilityService $availability,
        AdPricingService $pricing,
    ): JsonResponse {
        $this->authorizeLineItem($request, $lineItem);
        $this->assertRenewableSource($lineItem);

        $data = $request->validate([
            'duration_weeks' => ['required', 'integer', 'in:1,2,4'],
        ]);
        $durationWeeks = (int) $data['duration_weeks'];

        $renewal = DB::transaction(function () use ($lineItem, $availability, $pricing, $durationWeeks): AdBookingRequest {
            $lineItem->loadMissing(['bookingRequest.customer', 'zone']);
            $booking = $lineItem->bookingRequest;
            $start = $lineItem->end_at->copy();
            $end = $start->copy()->addWeeks($durationWeeks);
            $zone = AdZone::query()
                ->whereKey($lineItem->ad_zone_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $availability->isAvailable($zone, $start, $end)) {
                throw ValidationException::withMessages([
                    'start_date' => 'Selected ad zone is not available for the renewal dates.',
                ]);
            }

            $pricedLine = $pricing->priceLine(
                $zone,
                $durationWeeks,
                (string) $lineItem->creative_type,
                (bool) $lineItem->is_political_or_sensitive,
            );
            $priceDenars = (int) $pricedLine['price_denars'];

            $renewal = AdBookingRequest::query()->create([
                'ad_customer_id' => $booking->ad_customer_id,
                'discount_code_id' => null,
                'reference' => $this->nextReference(),
                'billing_company_name' => $booking->billing_company_name,
                'billing_tax_number' => $booking->billing_tax_number,
                'billing_registration_number' => $booking->billing_registration_number,
                'billing_address' => $booking->billing_address,
                'billing_city' => $booking->billing_city,
                'billing_country' => $booking->billing_country,
                'contact_name' => $booking->contact_name,
                'contact_email' => $booking->contact_email,
                'contact_phone' => $booking->contact_phone,
                'status' => 'needs_payment',
                'subtotal_denars' => $priceDenars,
                'discount_denars' => 0,
                'tax_rate' => 0,
                'tax_denars' => 0,
                'total_denars' => $priceDenars,
                'payment_status' => 'unpaid',
                'hold_expires_at' => now()->addDays(2),
                'customer_notes' => 'Renewal requested from booking '.$booking->reference.' line item #'.$lineItem->id,
            ]);

            $renewal->lineItems()->create([
                'ad_zone_id' => $lineItem->ad_zone_id,
                'image_id' => $lineItem->image_id,
                'duration_weeks' => $durationWeeks,
                'start_at' => $start,
                'end_at' => $end,
                'creative_type' => $lineItem->creative_type,
                'html' => $lineItem->html,
                'click_url' => $lineItem->click_url,
                'width' => (int) $lineItem->width,
                'height' => (int) $lineItem->height,
                'status' => 'pending_review',
                'is_political_or_sensitive' => (bool) $lineItem->is_political_or_sensitive,
                'political_multiplier' => $pricedLine['political_multiplier'],
                'technical_review_required' => (bool) $lineItem->technical_review_required,
                'review_notes' => 'Renewal request copied from line item #'.$lineItem->id,
                'price_denars' => $priceDenars,
            ]);

            return $renewal;
        });

        return response()->json([
            'data' => [
                'line_item_id' => $lineItem->id,
                'renewal_booking_request_id' => $renewal->id,
                'reference' => $renewal->reference,
                'status' => 'renewal_request_received',
                'payment_status' => $renewal->payment_status,
                'total_denars' => (int) $renewal->total_denars,
            ],
        ], 202);
    }

    private function authorizeLineItem(Request $request, AdBookingLineItem $lineItem): void
    {
        $lineItem->loadMissing('bookingRequest.customer');
        $customer = $lineItem->bookingRequest?->customer;

        abort_unless(
            $customer
            && (int) $customer->user_id === (int) $request->user()->id
            && $customer->status === 'active'
            && ! $customer->dashboard_deactivated_at
            && ! $customer->former_customer_at,
            403,
        );
    }

    private function assertRenewableSource(AdBookingLineItem $lineItem): void
    {
        $lineItem->loadMissing('bookingRequest');

        abort_unless(
            in_array($lineItem->status, self::RENEWABLE_LINE_STATUSES, true)
            && in_array($lineItem->bookingRequest?->status, self::RENEWABLE_BOOKING_STATUSES, true)
            && $lineItem->bookingRequest?->payment_status === 'paid',
            403,
        );
    }

    private function nextReference(): string
    {
        do {
            $reference = 'RAZ-REN-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (AdBookingRequest::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
