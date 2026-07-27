<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleListingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_listing_supports_public_filters(): void
    {
        $author = Author::factory()->create(['slug' => 'redakcija']);
        $category = Category::factory()->create(['slug' => 'biznis']);
        $tag = Tag::query()->create(['name' => 'Финансии', 'slug' => 'finansii']);

        $matching = Article::factory()
            ->published()
            ->for($author)
            ->for($category)
            ->create([
                'title' => 'Филтрирана објава',
                'slug' => 'filtrirana-objava',
                'type' => 'guide',
                'is_sponsored' => true,
                'published_at' => '2026-06-20 10:00:00',
            ]);
        $matching->tags()->attach($tag);

        Article::factory()
            ->published()
            ->create([
                'title' => 'Погрешен тип',
                'type' => 'news',
                'is_sponsored' => true,
                'published_at' => '2026-06-20 10:00:00',
            ]);

        Article::factory()
            ->for($author)
            ->for($category)
            ->create([
                'title' => 'Нацрт не се гледа',
                'status' => 'draft',
                'published_at' => null,
                'type' => 'guide',
                'is_sponsored' => true,
            ])
            ->tags()
            ->attach($tag);

        $this->getJson('/api/articles?type=guide&category=biznis&tag=finansii&author=redakcija&sponsored=1&published_from=2026-06-01&published_to=2026-06-30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'filtrirana-objava')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_article_detail_exposes_event_guide_metadata_only_when_selected(): void
    {
        $article = Article::factory()
            ->published()
            ->create([
                'slug' => 'prakticen-vodic',
                'presentation_type' => 'event_guide',
                'guide_metadata' => [
                    'meta' => [
                        'period_label' => '20 до 26 јули',
                        'city_label' => 'Битола',
                        'last_verified_at' => '2026-07-20',
                    ],
                    'events' => [
                        [
                            'title' => 'Втор настан',
                            'start_date' => '2026-07-22',
                            'source_url' => 'javascript:alert(1)',
                            'source_label' => 'Лош линк',
                            'display_order' => 20,
                        ],
                        [
                            'title' => 'Прв настан',
                            'start_date' => '2026-07-20',
                            'location' => 'Битола',
                            'source_url' => 'https://example.com/source',
                            'source_label' => 'Проверен извор',
                            'display_order' => 10,
                        ],
                    ],
                    'practical_notes' => ['Проверете ја програмата.'],
                    'sources' => [
                        ['label' => 'Проверен извор', 'url' => 'https://example.com/source'],
                        ['label' => 'Небезбеден извор', 'url' => 'javascript:alert(1)'],
                    ],
                ],
            ]);

        $this->getJson("/api/articles/{$article->slug}")
            ->assertOk()
            ->assertJsonPath('data.presentation_type', 'event_guide')
            ->assertJsonPath('data.guide.meta.event_count', 2)
            ->assertJsonPath('data.guide.events.0.title', 'Прв настан')
            ->assertJsonPath('data.guide.events.1.source_url', null)
            ->assertJsonCount(1, 'data.guide.sources')
            ->assertJsonPath('data.guide.practical_notes.0', 'Проверете ја програмата.');

        $standard = Article::factory()->published()->create([
            'slug' => 'standarden-tekst',
            'presentation_type' => 'standard',
            'guide_metadata' => ['events' => [['title' => 'Скриено']]],
        ]);

        $this->getJson("/api/articles/{$standard->slug}")
            ->assertOk()
            ->assertJsonPath('data.presentation_type', 'standard')
            ->assertJsonPath('data.guide', null);
    }

    public function test_event_guide_related_articles_prefer_event_guides_and_local_content(): void
    {
        $category = Category::factory()->create(['slug' => 'nastani']);
        $otherCategory = Category::factory()->create(['slug' => 'zivot']);

        $current = Article::factory()
            ->published()
            ->for($category)
            ->create([
                'slug' => 'tekoven-vodic',
                'presentation_type' => 'event_guide',
                'published_at' => now()->subDay(),
            ]);

        $guide = Article::factory()
            ->published()
            ->for($otherCategory)
            ->create([
                'slug' => 'drug-vodic',
                'presentation_type' => 'event_guide',
                'published_at' => now()->subHours(2),
            ]);

        $local = Article::factory()
            ->published()
            ->for($category)
            ->create([
                'slug' => 'lokalen-nastan',
                'presentation_type' => 'standard',
                'type' => 'event',
                'published_at' => now()->subHour(),
            ]);

        Article::factory()
            ->published()
            ->for($otherCategory)
            ->create([
                'slug' => 'distant-topic',
                'presentation_type' => 'standard',
                'published_at' => now(),
            ]);

        $this->getJson("/api/articles/{$current->slug}/related?limit=2")
            ->assertOk()
            ->assertJsonPath('data.0.slug', $guide->slug)
            ->assertJsonPath('data.1.slug', $local->slug);
    }
}
