<?php

namespace Database\Factories;

use App\Models\TeamRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamRole>
 */
class TeamRoleFactory extends Factory
{
    protected $model = TeamRole::class;

    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(),
            'group_key' => 'editorial',
            'sort_order' => 0,
            'status' => 'active',
        ];
    }
}
