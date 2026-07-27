<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $title = fake()->sentence(5);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1000, 9999),
            'excerpt' => fake()->sentence(),
            'body_html' => '<p>'.fake()->paragraph().'</p>',
            'status' => 'draft',
            'type' => 'editorial',
            'author_id' => Author::factory(),
            'category_id' => Category::factory(),
            'published_at' => null,
            'scheduled_at' => null,
            'is_sponsored' => false,
            'is_ai_generated' => false,
            'is_ai_assisted' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
    }
}
