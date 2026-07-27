<?php

namespace App\Services\Ads;

use App\Models\AdDiscountCode;
use App\Models\AdPrice;
use App\Models\AdZone;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdPricingService
{
    public function priceLine(AdZone $zone, int $durationWeeks, string $creativeType, bool $isPolitical): array
    {
        $price = AdPrice::query()
            ->where('ad_zone_id', $zone->id)
            ->where('duration_weeks', $durationWeeks)
            ->where('creative_type', $creativeType)
            ->where('is_active', true)
            ->first();

        if (! $price) {
            throw ValidationException::withMessages([
                'duration_weeks' => 'No active ad price exists for the selected zone, duration, and creative type.',
            ]);
        }

        $multiplier = $isPolitical ? (float) $price->political_multiplier : 1.0;

        return [
            'zone_key' => $zone->key,
            'duration_weeks' => $durationWeeks,
            'creative_type' => $creativeType,
            'is_political' => $isPolitical,
            'base_price_denars' => (int) $price->price_denars,
            'political_multiplier' => $multiplier,
            'price_denars' => (int) round(((int) $price->price_denars) * $multiplier),
        ];
    }

    public function priceRequest(array $lineInputs, ?string $discountCode, float $taxRate = 0.0): array
    {
        $lines = collect($lineInputs)->map(function (array $input): array {
            return $this->priceLine(
                $input['zone'],
                (int) $input['duration_weeks'],
                (string) $input['creative_type'],
                (bool) ($input['is_political'] ?? false),
            );
        });

        $subtotal = (int) $lines->sum('price_denars');
        $discount = $this->resolveDiscount($discountCode, $lines->all(), $subtotal);
        $discountDenars = (int) $discount['discount_denars'];
        $taxableAmount = max(0, $subtotal - $discountDenars);
        $taxDenars = (int) round($taxableAmount * $taxRate);

        return [
            'subtotal_denars' => $subtotal,
            'discount_denars' => $discountDenars,
            'tax_rate' => $taxRate,
            'tax_denars' => $taxDenars,
            'total_denars' => $taxableAmount + $taxDenars,
        ];
    }

    public function resolveDiscount(?string $code, array $zoneKeys, int $subtotal): array
    {
        if (! $code || $subtotal <= 0) {
            return $this->emptyDiscount();
        }

        $discountCode = AdDiscountCode::query()
            ->where('code', Str::upper(trim($code)))
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->first();

        if (! $discountCode || $this->isUsageLimitReached($discountCode) || ! $this->matchesZones($discountCode, $zoneKeys)) {
            return $this->emptyDiscount();
        }

        $discountableSubtotal = $this->discountableSubtotal($discountCode, $zoneKeys, $subtotal);

        $amount = match ($discountCode->type) {
            'fixed' => (int) $discountCode->value,
            default => (int) floor($discountableSubtotal * ((int) $discountCode->value / 100)),
        };

        return [
            'discount_code_id' => $discountCode->id,
            'discount_denars' => min($discountableSubtotal, $amount),
        ];
    }

    private function emptyDiscount(): array
    {
        return [
            'discount_code_id' => null,
            'discount_denars' => 0,
        ];
    }

    private function isUsageLimitReached(AdDiscountCode $discountCode): bool
    {
        return $discountCode->usage_limit !== null
            && (int) $discountCode->used_count >= (int) $discountCode->usage_limit;
    }

    private function matchesZones(AdDiscountCode $discountCode, array $zoneKeys): bool
    {
        $allowedZoneKeys = $discountCode->ad_zone_keys;

        if (! $allowedZoneKeys) {
            return true;
        }

        return count(array_intersect($allowedZoneKeys, $this->extractZoneKeys($zoneKeys))) > 0;
    }

    private function discountableSubtotal(AdDiscountCode $discountCode, array $zoneKeys, int $subtotal): int
    {
        $allowedZoneKeys = $discountCode->ad_zone_keys;

        if (! $allowedZoneKeys || ! $this->hasLineTotals($zoneKeys)) {
            return $subtotal;
        }

        return (int) collect($zoneKeys)
            ->filter(fn (array $line): bool => in_array($line['zone_key'], $allowedZoneKeys, true))
            ->sum('price_denars');
    }

    private function extractZoneKeys(array $zoneKeys): array
    {
        if (! $this->hasLineTotals($zoneKeys)) {
            return $zoneKeys;
        }

        return collect($zoneKeys)->pluck('zone_key')->all();
    }

    private function hasLineTotals(array $zoneKeys): bool
    {
        return isset($zoneKeys[0]) && is_array($zoneKeys[0]) && array_key_exists('price_denars', $zoneKeys[0]);
    }
}
