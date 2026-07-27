<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdDiscountCode;
use App\Models\AdPrice;
use App\Models\AdZone;
use App\Services\Ads\AdPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_uses_duration_discount_and_zero_tax(): void
    {
        $zone = AdZone::factory()->create(['key' => 'home_top_billboard']);
        AdPrice::factory()->create([
            'ad_zone_id' => $zone->id,
            'duration_weeks' => 4,
            'creative_type' => 'image',
            'price_denars' => 30000,
            'political_multiplier' => 2,
            'is_active' => true,
        ]);
        AdDiscountCode::factory()->create([
            'code' => 'RAZ10',
            'type' => 'percent',
            'value' => 10,
        ]);

        $result = app(AdPricingService::class)->priceRequest([
            [
                'zone' => $zone,
                'duration_weeks' => 4,
                'creative_type' => 'image',
                'is_political' => false,
            ],
        ], 'RAZ10');

        $this->assertSame([
            'subtotal_denars' => 30000,
            'discount_denars' => 3000,
            'tax_rate' => 0.0,
            'tax_denars' => 0,
            'total_denars' => 27000,
        ], $result);
    }

    public function test_political_multiplier_changes_line_price(): void
    {
        $zone = AdZone::factory()->create();
        AdPrice::factory()->create([
            'ad_zone_id' => $zone->id,
            'duration_weeks' => 2,
            'creative_type' => 'image',
            'price_denars' => 15000,
            'political_multiplier' => 2,
            'is_active' => true,
        ]);

        $result = app(AdPricingService::class)->priceLine($zone, 2, 'image', true);

        $this->assertSame(30000, $result['price_denars']);
        $this->assertSame(2.0, $result['political_multiplier']);
    }

    public function test_fixed_discount_code_is_capped_at_subtotal(): void
    {
        $zone = AdZone::factory()->create(['key' => 'article_after_title']);
        AdPrice::factory()->create([
            'ad_zone_id' => $zone->id,
            'duration_weeks' => 1,
            'creative_type' => 'image',
            'price_denars' => 5000,
            'is_active' => true,
        ]);
        AdDiscountCode::factory()->create([
            'code' => 'FREEISH',
            'type' => 'fixed',
            'value' => 10000,
        ]);

        $result = app(AdPricingService::class)->priceRequest([
            [
                'zone' => $zone,
                'duration_weeks' => 1,
                'creative_type' => 'image',
                'is_political' => false,
            ],
        ], 'FREEISH');

        $this->assertSame(5000, $result['discount_denars']);
        $this->assertSame(0, $result['total_denars']);
    }

    public function test_zone_restricted_discount_applies_only_to_matching_line_totals(): void
    {
        $eligibleZone = AdZone::factory()->create(['key' => 'home_top_billboard']);
        $ineligibleZone = AdZone::factory()->create(['key' => 'article_sidebar_top']);
        AdPrice::factory()->create([
            'ad_zone_id' => $eligibleZone->id,
            'duration_weeks' => 2,
            'creative_type' => 'image',
            'price_denars' => 20000,
            'is_active' => true,
        ]);
        AdPrice::factory()->create([
            'ad_zone_id' => $ineligibleZone->id,
            'duration_weeks' => 2,
            'creative_type' => 'image',
            'price_denars' => 10000,
            'is_active' => true,
        ]);
        AdDiscountCode::factory()->create([
            'code' => 'HOME10',
            'type' => 'percent',
            'value' => 10,
            'ad_zone_keys' => ['home_top_billboard'],
        ]);

        $result = app(AdPricingService::class)->priceRequest([
            [
                'zone' => $eligibleZone,
                'duration_weeks' => 2,
                'creative_type' => 'image',
                'is_political' => false,
            ],
            [
                'zone' => $ineligibleZone,
                'duration_weeks' => 2,
                'creative_type' => 'image',
                'is_political' => false,
            ],
        ], 'HOME10');

        $this->assertSame(30000, $result['subtotal_denars']);
        $this->assertSame(2000, $result['discount_denars']);
        $this->assertSame(28000, $result['total_denars']);
    }

    public function test_inactive_or_expired_discount_code_is_ignored(): void
    {
        $zone = AdZone::factory()->create(['key' => 'category_top']);
        AdPrice::factory()->create([
            'ad_zone_id' => $zone->id,
            'duration_weeks' => 2,
            'creative_type' => 'image',
            'price_denars' => 12000,
            'is_active' => true,
        ]);
        AdDiscountCode::factory()->create([
            'code' => 'OLD10',
            'type' => 'percent',
            'value' => 10,
            'ends_at' => now()->subDay(),
            'is_active' => true,
        ]);
        AdDiscountCode::factory()->create([
            'code' => 'OFF10',
            'type' => 'percent',
            'value' => 10,
            'is_active' => false,
        ]);

        $service = app(AdPricingService::class);

        $this->assertSame(0, $service->priceRequest([
            [
                'zone' => $zone,
                'duration_weeks' => 2,
                'creative_type' => 'image',
                'is_political' => false,
            ],
        ], 'OLD10')['discount_denars']);

        $this->assertSame(0, $service->priceRequest([
            [
                'zone' => $zone,
                'duration_weeks' => 2,
                'creative_type' => 'image',
                'is_political' => false,
            ],
        ], 'OFF10')['discount_denars']);
    }
}
