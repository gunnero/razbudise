<?php

namespace Database\Factories;

use App\Models\AdDiscountCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdDiscountCode>
 */
class AdDiscountCodeFactory extends Factory
{
    protected $model = AdDiscountCode::class;

    public function definition(): array
    {
        return [
            'code' => Str::upper(fake()->unique()->bothify('RAZ####')),
            'description' => 'Промотивен попуст за рекламна кампања',
            'type' => 'percent',
            'value' => 10,
            'ad_zone_keys' => null,
            'usage_limit' => null,
            'used_count' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'is_active' => true,
        ];
    }
}
