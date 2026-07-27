<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdPrice;
use App\Models\AdZone;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdPriceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_ad_price_seed_creates_missing_defaults_without_overwriting_existing_prices(): void
    {
        $this->seed(DatabaseSeeder::class);

        $zone = AdZone::query()->where('key', 'home_in_feed_1')->firstOrFail();
        $price = AdPrice::query()
            ->where('ad_zone_id', $zone->id)
            ->where('duration_weeks', 2)
            ->where('creative_type', 'image')
            ->firstOrFail();

        $price->update([
            'price_denars' => 12345,
            'political_multiplier' => 3.5,
            'is_active' => false,
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(102, AdPrice::query()->count());

        $price->refresh();
        $this->assertSame(12345, $price->price_denars);
        $this->assertSame('3.50', $price->political_multiplier);
        $this->assertFalse($price->is_active);
    }
}
