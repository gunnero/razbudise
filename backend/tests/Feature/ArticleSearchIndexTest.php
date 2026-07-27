<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\Search\ArticleSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleSearchIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_searchable_array_contains_public_index_fields(): void
    {
        $author = Author::factory()->create(['name' => 'Редакција', 'slug' => 'redakcija']);
        $category = Category::factory()->create(['name' => 'Култура', 'slug' => 'kultura']);

        $article = Article::factory()
            ->published()
            ->for($author)
            ->for($category)
            ->create([
                'title' => 'Меили приказна',
                'slug' => 'meili-prikazna',
                'excerpt' => 'Краток опис',
                'body_html' => '<p>Тело за пребарување.</p>',
                'type' => 'interview',
                'is_sponsored' => false,
            ]);

        $this->assertTrue($article->shouldBeSearchable());
        $this->assertSame([
            'id' => $article->id,
            'title' => 'Меили приказна',
            'slug' => 'meili-prikazna',
            'excerpt' => 'Краток опис',
            'body' => 'Тело за пребарување.',
            'type' => 'interview',
            'is_sponsored' => false,
            'category_slug' => 'kultura',
            'category_name' => 'Култура',
            'author_slug' => 'redakcija',
            'author_name' => 'Редакција',
            'published_at' => $article->published_at?->timestamp,
        ], $article->toSearchableArray());
    }

    public function test_database_search_service_filters_like_public_search(): void
    {
        $category = Category::factory()->create(['slug' => 'zivot']);

        Article::factory()
            ->published()
            ->for($category)
            ->create([
                'title' => 'Мирно утро во Битола',
                'excerpt' => 'Навики и кафе.',
            ]);

        Article::factory()
            ->published()
            ->create([
                'title' => 'Друга тема',
                'excerpt' => 'Нема врска со барањето.',
            ]);

        $result = app(ArticleSearchService::class)->databaseSearch('Битола', ['category' => 'zivot'], 12);

        $this->assertSame(1, $result->total());
        $this->assertSame('Мирно утро во Битола', $result->items()[0]->title);
    }
}
