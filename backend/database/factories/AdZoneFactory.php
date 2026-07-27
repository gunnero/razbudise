<?php

namespace Database\Factories;

use App\Models\AdZone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdZone>
 */
class AdZoneFactory extends Factory
{
    protected $model = AdZone::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'key' => Str::slug($name, '_').'_'.fake()->unique()->numberBetween(1000, 9999),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'allowed_sizes' => [[728, 90]],
            'page_context' => 'global',
            'is_active' => true,
        ];
    }
}
