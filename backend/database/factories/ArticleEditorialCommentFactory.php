<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArticleEditorialComment>
 */
class ArticleEditorialCommentFactory extends Factory
{
    protected $model = ArticleEditorialComment::class;

    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'author_id' => User::factory(),
            'parent_id' => null,
            'change_request_id' => null,
            'body' => fake()->paragraph(),
            'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
            'is_blocking' => false,
            'is_resolved' => false,
            'addressed_at' => null,
            'addressed_by' => null,
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }
}
