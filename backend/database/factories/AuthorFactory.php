<?php

namespace Database\Factories;

use App\Models\Author;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Author>
 */
class AuthorFactory extends Factory
{
    protected $model = Author::class;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'short_bio' => fake()->sentence(),
            'full_bio' => fake()->paragraphs(2, true),
            'job_title' => fake()->jobTitle(),
            'email' => fake()->safeEmail(),
            'show_email' => false,
            'website_url' => fake()->url(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
