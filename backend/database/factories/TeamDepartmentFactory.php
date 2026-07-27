<?php

namespace Database\Factories;

use App\Models\TeamDepartment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamDepartment>
 */
class TeamDepartmentFactory extends Factory
{
    protected $model = TeamDepartment::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::headline($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->sentence(),
            'group_key' => null,
            'status' => 'active',
            'sort_order' => 0,
            'metadata' => [],
        ];
    }
}
