<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchApiAdvancedFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_api_exposes_type_sponsored_and_date_filters(): void
    {
        $author = Author::factory()->create(['slug' => 'redakcija']);
        $category = Category::factory()->create(['slug' => 'intervju']);

        Article::factory()
            ->published()
            ->for($author)
            ->for($category)
            ->create([
                'title' => 'Битола интервју со локален креатор',
                'slug' => 'bitola-intervju-so-lokalen-kreator',
                'excerpt' => 'Битола и креативна сцена.',
                'type' => 'interview',
                'is_sponsored' => true,
                'published_at' => '2026-06-20 10:00:00',
            ]);

        Article::factory()
            ->published()
            ->create([
                'title' => 'Битола кратка вест',
                'type' => 'news',
                'is_sponsored' => true,
                'published_at' => '2026-06-20 10:00:00',
            ]);

        Article::factory()
            ->published()
            ->create([
                'title' => 'Битола старо интервју',
                'type' => 'interview',
                'is_sponsored' => true,
                'published_at' => '2026-05-10 10:00:00',
            ]);

        $this->getJson('/api/search?query='.rawurlencode('Битола').'&type=interview&sponsored=1&published_from=2026-06-01&published_to=2026-06-30')
            ->assertOk()
            ->assertJsonPath('data.filters.selected.type', 'interview')
            ->assertJsonPath('data.filters.selected.sponsored', true)
            ->assertJsonPath('data.filters.selected.published_from', '2026-06-01')
            ->assertJsonPath('data.filters.selected.published_to', '2026-06-30')
            ->assertJsonCount(1, 'data.articles.data')
            ->assertJsonPath('data.articles.data.0.slug', 'bitola-intervju-so-lokalen-kreator');
    }
}
