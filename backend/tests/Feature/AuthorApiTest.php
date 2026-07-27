<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_authors_are_visible_publicly(): void
    {
        $active = Author::factory()->create([
            'name' => 'Активен Автор',
            'slug' => 'aktiven-avtor',
            'is_active' => true,
        ]);

        $inactive = Author::factory()->create([
            'name' => 'Неактивен Автор',
            'slug' => 'neaktiven-avtor',
            'is_active' => false,
        ]);

        $this->getJson('/api/authors')
            ->assertOk()
            ->assertJsonPath('data.0.slug', $active->slug)
            ->assertJsonMissing(['slug' => $inactive->slug]);

        $this->getJson("/api/authors/{$active->slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $active->slug);

        $this->getJson("/api/authors/{$inactive->slug}")
            ->assertNotFound();
    }

    public function test_author_articles_are_paginated_and_only_published_articles_are_visible(): void
    {
        $author = Author::factory()->create([
            'slug' => 'avtor-so-statii',
            'is_active' => true,
        ]);

        $featuredImage = MediaAsset::factory()->create([
            'url' => 'https://example.test/featured.jpg',
            'alt_text' => 'Featured image',
        ]);

        Article::factory()
            ->count(3)
            ->sequence(
                ['title' => 'Прва статија', 'slug' => 'prva-statija', 'published_at' => now()->subDays(1)],
                ['title' => 'Втора статија', 'slug' => 'vtora-statija', 'published_at' => now()->subDays(2)],
                ['title' => 'Трета статија', 'slug' => 'treta-statija', 'published_at' => now()->subDays(3)],
            )
            ->create([
                'author_id' => $author->id,
                'status' => 'published',
                'featured_image_id' => $featuredImage->id,
            ]);

        Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'draft',
            'published_at' => now()->subDay(),
        ]);

        $this->getJson("/api/authors/{$author->slug}/articles?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Прва статија')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson("/api/authors/{$author->slug}/articles?per_page=2&page=2")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Трета статија');
    }

    public function test_author_directory_supports_search_sorting_and_published_content_metadata(): void
    {
        $culture = Category::factory()->create(['name' => 'Култура', 'slug' => 'kultura']);
        $events = Category::factory()->create(['name' => 'Настани', 'slug' => 'nastani']);
        $ana = Author::factory()->create([
            'name' => 'Ана Петровска',
            'slug' => 'ana-petrovska',
            'short_bio' => null,
            'full_bio' => 'Долга биографија од која се добива реален краток преглед.',
        ]);
        $boris = Author::factory()->create(['name' => 'Борис Стојанов', 'slug' => 'boris-stojanov']);

        Article::factory()->create([
            'author_id' => $ana->id,
            'category_id' => $culture->id,
            'title' => 'Најнова културна приказна',
            'slug' => 'najnova-kulturna-prikazna',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        Article::factory()->create([
            'author_id' => $ana->id,
            'category_id' => $events->id,
            'status' => 'published',
            'published_at' => now()->subDays(3),
        ]);
        Article::factory()->create([
            'author_id' => $ana->id,
            'category_id' => $culture->id,
            'status' => 'published',
            'published_at' => now()->subDays(5),
        ]);
        Article::factory()->create([
            'author_id' => $boris->id,
            'category_id' => $events->id,
            'status' => 'published',
            'published_at' => now()->subDays(30),
        ]);

        $this->getJson('/api/authors?search='.rawurlencode('Петровска').'&sort=recent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'ana-petrovska')
            ->assertJsonPath('data.0.bio_preview', 'Долга биографија од која се добива реален краток преглед.')
            ->assertJsonPath('data.0.article_count', 3)
            ->assertJsonPath('data.0.latest_article.title', 'Најнова културна приказна')
            ->assertJsonPath('data.0.editorial_focus.0.name', 'Култура')
            ->assertJsonPath('directory_stats.author_count', 2)
            ->assertJsonPath('directory_stats.published_article_count', 4)
            ->assertJsonPath('directory_stats.active_contributor_count', 2);

        $this->getJson('/api/authors?sort=articles')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'ana-petrovska');

        $this->getJson('/api/authors?sort=alphabetical')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'ana-petrovska')
            ->assertJsonPath('data.1.slug', 'boris-stojanov');
    }
}
