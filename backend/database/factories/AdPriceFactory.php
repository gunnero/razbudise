<?php

namespace Database\Factories;

use App\Models\AdPrice;
use App\Models\AdZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdPrice>
 */
class AdPriceFactory extends Factory
{
    protected $model = AdPrice::class;

    public function definition(): array
    {
        return [
            'ad_zone_id' => AdZone::factory(),
            'duration_weeks' => fake()->randomElement([1, 2, 4]),
            'creative_type' => 'image',
            'price_denars' => fake()->randomElement([5000, 7500, 9000, 12000, 15000]),
            'political_multiplier' => 2,
            'is_active' => true,
        ];
    }
}
