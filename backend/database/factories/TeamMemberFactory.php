<?php

namespace Database\Factories;

use App\Models\TeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamMember>
 */
class TeamMemberFactory extends Factory
{
    protected $model = TeamMember::class;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'display_title' => fake()->jobTitle(),
            'short_bio' => fake()->sentence(),
            'full_bio' => fake()->paragraphs(2, true),
            'email' => fake()->safeEmail(),
            'member_type' => 'human',
            'status' => 'active',
            'sort_order' => 0,
            'joined_at' => null,
            'social_links' => [],
            'metadata' => [],
        ];
    }
}
