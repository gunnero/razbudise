<?php

namespace App\Services\Ads;

use App\Models\AdBookingRequest;
use App\Models\AdCustomer;
use App\Models\AdDiscountCode;
use App\Models\AdZone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdBookingSubmissionService
{
    public function __construct(
        private readonly AdAvailabilityService $availability,
        private readonly AdPricingService $pricing,
        private readonly AdCreativeValidationService $creativeValidation,
        private readonly AdBillingProfileService $billingProfile,
        private readonly PublicAdCreativeClaimService $creativeClaims,
    ) {}

    public function submit(array $data): AdBookingRequest
    {
        return DB::transaction(function () use ($data): AdBookingRequest {
            $zones = AdZone::query()
                ->whereIn('key', collect($data['items'])->pluck('zone_key')->unique()->values())
                ->orderBy('key')
                ->lockForUpdate()
                ->get()
                ->keyBy('key');
            $claimsByItem = $this->creativeClaims->lockForBooking($data['items']);

            $lineInputs = collect($data['items'])->map(function (array $item, int $index) use ($zones, $claimsByItem): array {
                $zone = $zones->get($item['zone_key']);

                if (! $zone || ! $zone->is_active) {
                    throw ValidationException::withMessages([
                        "items.{$index}.zone_key" => 'Selected ad zone is no longer active.',
                    ]);
                }

                $start = CarbonImmutable::parse($item['start_date'])->startOfDay();
                $end = $start->addWeeks((int) $item['duration_weeks']);
                $claim = $claimsByItem[$index] ?? null;
                $image = $claim?->mediaAsset;

                if ($image) {
                    // The FormRequest provides fast feedback, but the locked
                    // media row is authoritative in case it changed meanwhile.
                    $this->creativeValidation->validateImageDimensions($zone, $image);
                }

                $this->availability->assertAvailable($zone, $start, $end);

                $price = $this->pricing->priceLine(
                    $zone,
                    (int) $item['duration_weeks'],
                    $item['creative_type'],
                    (bool) ($item['is_political_or_sensitive'] ?? false),
                );

                return [
                    'zone' => $zone,
                    'item' => $item,
                    'image' => $image,
                    'start' => $start,
                    'end' => $end,
                    'price' => $price,
                    'claim' => $claim,
                ];
            })->values();

            $linePrices = $lineInputs->pluck('price')->all();
            $subtotal = (int) collect($linePrices)->sum('price_denars');
            $discount = $this->pricing->resolveDiscount($data['discount_code'] ?? null, $linePrices, $subtotal);
            $discountDenars = (int) $discount['discount_denars'];
            $taxRate = $this->billingProfile->taxRate();
            $taxableAmount = max(0, $subtotal - $discountDenars);
            $taxDenars = (int) round($taxableAmount * $taxRate);

            $customer = $this->customerFor($data);

            $bookingRequest = AdBookingRequest::query()->create([
                'ad_customer_id' => $customer->id,
                'discount_code_id' => $discount['discount_code_id'],
                'reference' => $this->nextReference(),
                'billing_company_name' => $data['billing']['company_name'],
                'billing_tax_number' => $this->normalizeTaxNumber($data['billing']['tax_number']),
                'billing_registration_number' => $data['billing']['registration_number'] ?? null,
                'billing_address' => $data['billing']['address'],
                'billing_city' => $data['billing']['city'],
                'billing_country' => $this->normalizeCountry($data['billing']['country']),
                'contact_name' => $data['contact']['name'],
                'contact_email' => $data['contact']['email'],
                'contact_phone' => $data['contact']['phone'] ?? null,
                'status' => 'pending_review',
                'subtotal_denars' => $subtotal,
                'discount_denars' => $discountDenars,
                'tax_rate' => $taxRate,
                'tax_denars' => $taxDenars,
                'total_denars' => $taxableAmount + $taxDenars,
                'payment_status' => 'unpaid',
                'hold_expires_at' => now()->addDays(2),
            ]);

            $this->consumeDiscount($discount, $discountDenars);

            $lineInputs->each(function (array $input) use ($bookingRequest): void {
                $item = $input['item'];
                $price = $input['price'];

                $bookingRequest->lineItems()->create([
                    'ad_zone_id' => $input['zone']->id,
                    'image_id' => $item['creative_type'] === 'image' ? $input['image']?->getKey() : null,
                    'duration_weeks' => (int) $item['duration_weeks'],
                    'start_at' => $input['start'],
                    'end_at' => $input['end'],
                    'creative_type' => $item['creative_type'],
                    'html' => $item['html'] ?? null,
                    'click_url' => $item['click_url'] ?? null,
                    'width' => $input['image'] ? (int) $input['image']->width : (int) $item['width'],
                    'height' => $input['image'] ? (int) $input['image']->height : (int) $item['height'],
                    'status' => 'pending_review',
                    'is_political_or_sensitive' => (bool) ($item['is_political_or_sensitive'] ?? false),
                    'political_multiplier' => $price['political_multiplier'],
                    'technical_review_required' => $this->creativeValidation->requiresTechnicalReview($item['creative_type']),
                    'price_denars' => $price['price_denars'],
                ]);
            });

            $this->creativeClaims->attachToBooking($claimsByItem, $bookingRequest);

            return $bookingRequest->load(['customer.advertiser', 'lineItems.zone', 'discountCode']);
        });
    }

    private function customerFor(array $data): AdCustomer
    {
        return AdCustomer::query()->create([
            'advertiser_id' => null,
            'company_name' => $data['billing']['company_name'],
            'tax_number' => $this->normalizeTaxNumber($data['billing']['tax_number']),
            'registration_number' => $data['billing']['registration_number'] ?? null,
            'billing_address' => $data['billing']['address'],
            'billing_city' => $data['billing']['city'],
            'billing_country' => $this->normalizeCountry($data['billing']['country']),
            'contact_name' => $data['contact']['name'],
            'contact_email' => $data['contact']['email'],
            'contact_phone' => $data['contact']['phone'] ?? null,
            'status' => 'pending',
            'last_active_at' => null,
        ]);
    }

    private function nextReference(): string
    {
        do {
            $reference = 'RAZ-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (AdBookingRequest::query()->where('reference', $reference)->exists());

        return $reference;
    }

    private function consumeDiscount(array $discount, int $discountDenars): void
    {
        if (empty($discount['discount_code_id']) || $discountDenars <= 0) {
            return;
        }

        $updated = AdDiscountCode::query()
            ->whereKey($discount['discount_code_id'])
            ->where(function ($query): void {
                $query
                    ->whereNull('usage_limit')
                    ->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->increment('used_count');

        if ($updated === 1) {
            return;
        }

        throw ValidationException::withMessages([
            'discount_code' => 'Discount code usage limit has been reached.',
        ]);
    }

    private function normalizeTaxNumber(string $taxNumber): string
    {
        return Str::of($taxNumber)
            ->trim()
            ->upper()
            ->toString();
    }

    private function normalizeCountry(string $country): string
    {
        return Str::of($country)
            ->trim()
            ->upper()
            ->toString();
    }
}
