<?php

namespace Database\Factories;

use App\Models\Advertiser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Advertiser>
 */
class AdvertiserFactory extends Factory
{
    protected $model = Advertiser::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->companyEmail(),
            'website_url' => fake()->url(),
            'is_active' => true,
        ];
    }
}
