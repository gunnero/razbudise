<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\ArticleChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArticleChangeRequest> */
class ArticleChangeRequestFactory extends Factory
{
    protected $model = ArticleChangeRequest::class;

    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'requested_by' => User::factory(),
            'summary' => fake()->sentence(),
            'deadline_at' => null,
            'resubmitted_at' => null,
            'resubmitted_by' => null,
        ];
    }
}
