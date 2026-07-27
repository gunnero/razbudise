<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleBlock;
use App\Models\Category;
use App\Models\HomepageSection;
use App\Models\MediaAsset;
use App\Models\SponsoredContentDetail;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_returns_active_sections_with_published_articles_only(): void
    {
        $category = Category::factory()->create([
            'name' => 'Живот',
            'slug' => 'zivot',
        ]);

        $published = Article::factory()
            ->published()
            ->for($category)
            ->create([
                'title' => 'Објавена почетна приказна',
                'slug' => 'objavena-pocetna-prikazna',
            ]);

        Article::factory()
            ->for($category)
            ->create([
                'title' => 'Скриена почетна приказна',
                'status' => 'draft',
                'published_at' => null,
            ]);

        HomepageSection::query()->create([
            'key' => 'featured',
            'name' => 'Главни приказни',
            'type' => 'manual',
            'configuration' => [
                'title' => 'Издвоено',
                'article_slugs' => [$published->slug, 'missing-slug'],
                'limit' => 4,
            ],
            'is_active' => true,
            'sort_order' => 10,
        ]);

        HomepageSection::query()->create([
            'key' => 'hidden',
            'name' => 'Скриено',
            'type' => 'latest',
            'configuration' => ['limit' => 3],
            'is_active' => false,
            'sort_order' => 20,
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.key', 'featured')
            ->assertJsonPath('data.sections.0.articles.0.slug', 'objavena-pocetna-prikazna')
            ->assertJsonCount(1, 'data.sections')
            ->assertJsonCount(1, 'data.sections.0.articles');
    }

    public function test_homepage_category_section_uses_configured_active_category(): void
    {
        $category = Category::factory()->create([
            'name' => 'Интервју',
            'slug' => 'intervju',
            'is_active' => true,
        ]);

        Article::factory()
            ->published()
            ->for($category)
            ->create(['title' => 'Интервју на почетна']);

        HomepageSection::query()->create([
            'key' => 'interviews',
            'name' => 'Интервјуа',
            'type' => 'category',
            'configuration' => [
                'title' => 'Интервју',
                'category_slug' => 'intervju',
                'limit' => 6,
            ],
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.category.slug', 'intervju')
            ->assertJsonPath('data.sections.0.articles.0.category.slug', 'intervju');
    }

    public function test_homepage_section_exposes_frontend_layout_and_ad_configuration(): void
    {
        $article = Article::factory()
            ->published()
            ->create([
                'title' => 'Главна CMS приказна',
                'slug' => 'glavna-cms-prikazna',
            ]);

        HomepageSection::query()->create([
            'key' => 'editorial_pick',
            'name' => 'Избор на редакција',
            'type' => 'manual',
            'configuration' => [
                'title' => 'Главна тема',
                'description' => 'Контролирано од CMS.',
                'layout' => 'hero',
                'ad_zone_after' => 'home_after_featured',
                'article_slugs' => [$article->slug],
                'limit' => 1,
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.key', 'editorial_pick')
            ->assertJsonPath('data.sections.0.layout', 'hero')
            ->assertJsonPath('data.sections.0.ad_zone_after', 'home_after_featured')
            ->assertJsonPath('data.sections.0.rules.type', 'manual')
            ->assertJsonPath('data.sections.0.rules.limit', 1)
            ->assertJsonPath('data.sections.0.rules.article_slugs.0', 'glavna-cms-prikazna')
            ->assertJsonPath('data.sections.0.articles.0.slug', 'glavna-cms-prikazna');
    }

    public function test_homepage_section_type_options_match_public_api_section_modes(): void
    {
        $this->assertSame([
            'manual' => 'Manual article picks',
            'latest' => 'Latest published articles',
            'category' => 'Category feed',
            'promo_cards' => 'Promoted image cards',
        ], HomepageSection::typeOptions());
    }

    public function test_homepage_promo_card_section_exposes_editable_cards_without_articles(): void
    {
        HomepageSection::query()->create([
            'key' => 'special_rubrics',
            'name' => 'Специјални рубрики',
            'type' => 'promo_cards',
            'configuration' => [
                'title' => 'Специјални рубрики',
                'description' => 'Авторски и видео рубрики.',
                'cards' => [
                    [
                        'title' => 'Мими Yugo',
                        'href' => '/category/mimi-yugo',
                        'image_url' => '/homepage/promos/mimi-yugo.webp',
                        'alt' => 'Мими Yugo',
                        'sort_order' => 20,
                    ],
                    [
                        'title' => 'Нена колумни',
                        'href' => '/category/nena-kolumni',
                        'image_url' => '/homepage/promos/nena-kolumni.webp',
                        'alt' => 'Нена колумни',
                        'sort_order' => 10,
                    ],
                    [
                        'title' => 'Брчиме со Дуле',
                        'href' => '/category/brchime_so_dule',
                        'image_url' => '/homepage/promos/brchime-so-dule.webp',
                        'alt' => 'Брчиме со Дуле',
                        'sort_order' => 30,
                    ],
                ],
            ],
            'is_active' => true,
            'sort_order' => 5,
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.key', 'special_rubrics')
            ->assertJsonPath('data.sections.0.type', 'promo_cards')
            ->assertJsonPath('data.sections.0.title', 'Специјални рубрики')
            ->assertJsonCount(0, 'data.sections.0.articles')
            ->assertJsonCount(3, 'data.sections.0.promo_cards')
            ->assertJsonPath('data.sections.0.promo_cards.0.title', 'Нена колумни')
            ->assertJsonPath('data.sections.0.promo_cards.0.href', '/category/nena-kolumni')
            ->assertJsonPath('data.sections.0.promo_cards.1.href', '/category/mimi-yugo')
            ->assertJsonPath('data.sections.0.promo_cards.2.href', '/category/brchime_so_dule');
    }

    public function test_homepage_falls_back_to_latest_articles_when_configured_sections_are_empty(): void
    {
        Article::factory()->published()->create([
            'title' => 'Најнова резервна приказна',
            'slug' => 'najnovata-rezervna-prikazna',
        ]);

        HomepageSection::query()->create([
            'key' => 'empty_manual',
            'name' => 'Празно',
            'type' => 'manual',
            'configuration' => [
                'article_slugs' => ['missing-slug'],
                'limit' => 4,
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.key', 'latest_fallback')
            ->assertJsonPath('data.sections.0.layout', 'grid')
            ->assertJsonPath('data.sections.0.articles.0.slug', 'najnovata-rezervna-prikazna');
    }

    public function test_homepage_sections_do_not_repeat_articles_across_frontpage_slots(): void
    {
        $first = Article::factory()->published()->create([
            'title' => 'Прва реална објава',
            'slug' => 'prva-realna-objava',
            'published_at' => now(),
        ]);
        $second = Article::factory()->published()->create([
            'title' => 'Втора реална објава',
            'slug' => 'vtora-realna-objava',
            'published_at' => now()->subMinute(),
        ]);
        $third = Article::factory()->published()->create([
            'title' => 'Трета реална објава',
            'slug' => 'treta-realna-objava',
            'published_at' => now()->subMinutes(2),
        ]);

        HomepageSection::query()->create([
            'key' => 'featured',
            'name' => 'Главни приказни',
            'type' => 'latest',
            'configuration' => [
                'layout' => 'hero',
                'limit' => 2,
            ],
            'is_active' => true,
            'sort_order' => 10,
        ]);

        HomepageSection::query()->create([
            'key' => 'latest',
            'name' => 'Најново',
            'type' => 'latest',
            'configuration' => [
                'layout' => 'grid',
                'limit' => 2,
            ],
            'is_active' => true,
            'sort_order' => 20,
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.articles.0.slug', $first->slug)
            ->assertJsonPath('data.sections.0.articles.1.slug', $second->slug)
            ->assertJsonPath('data.sections.1.articles.0.slug', $third->slug)
            ->assertJsonMissingPath('data.sections.1.articles.1');
    }

    public function test_database_seeder_creates_repeatable_homepage_sections_and_demo_articles(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('homepage_sections', 7);
        $this->assertSame(8, Article::query()->published()->count());
        $this->assertSame(8, Tag::query()->count());
        $this->assertSame(8, MediaAsset::query()->where('path', 'like', 'demo/%')->where('path', 'not like', 'demo/ads/%')->count());
        $this->assertSame(8, Article::query()->published()->whereNotNull('featured_image_id')->count());
        $this->assertSame(1, Article::query()->where('is_sponsored', true)->count());
        $this->assertSame(1, SponsoredContentDetail::query()->count());
        $this->assertGreaterThanOrEqual(5, ArticleBlock::query()->count());

        $sponsored = Article::query()
            ->where('slug', 'platen-promotivna-prikazna-za-lokalen-brend')
            ->with(['tags', 'sponsoredContentDetail'])
            ->firstOrFail();

        $this->assertSame('Спонзорирана објава', $sponsored->sponsoredContentDetail?->disclosure_label);
        $this->assertSame(['biznis', 'sponzorirano'], $sponsored->tags->pluck('slug')->sort()->values()->all());

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.key', 'featured')
            ->assertJsonPath('data.sections.0.type', 'latest')
            ->assertJsonPath('data.sections.0.layout', 'hero')
            ->assertJsonPath('data.sections.0.rules.type', 'latest')
            ->assertJsonPath('data.sections.0.rules.limit', 9)
            ->assertJsonPath('data.sections.0.articles.0.featured_image.path', 'demo/bitola-se-budi-so-novi-kulturni-prikazni.png')
            ->assertJsonPath('data.sections.1.key', 'special_rubrics')
            ->assertJsonPath('data.sections.1.type', 'promo_cards')
            ->assertJsonPath('data.sections.1.promo_cards.0.href', '/category/mimi-yugo')
            ->assertJsonPath('data.sections.1.promo_cards.1.href', '/category/nena-kolumni')
            ->assertJsonPath('data.sections.1.promo_cards.2.href', '/category/brchime_so_dule');

        $this->assertDatabaseHas('homepage_sections', [
            'key' => 'news',
            'name' => 'Вести',
            'type' => 'category',
        ]);

        $this->assertTrue(
            Article::query()->where('slug', 'bitola-se-budi-so-novi-kulturni-prikazni')->firstOrFail()->published_at->lt(now()->subYear()),
        );
    }

    public function test_homepage_uses_optional_display_title_without_changing_canonical_title(): void
    {
        $article = Article::factory()->create([
            'title' => 'Долг канонски наслов што останува на самата објава',
            'homepage_title' => 'Краток наслов за почетна',
            'excerpt' => 'Оригинален опис што останува на самата објава.',
            'homepage_excerpt' => '<p>Пократок опис само за картичката.</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);
        HomepageSection::query()->create([
            'key' => 'featured',
            'name' => 'Главни приказни',
            'type' => 'manual',
            'configuration' => ['article_slugs' => [$article->slug], 'limit' => 1],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.articles.0.title', 'Краток наслов за почетна')
            ->assertJsonPath('data.sections.0.articles.0.excerpt', 'Пократок опис само за картичката.')
            ->assertJsonPath('data.sections.0.articles.0.canonical_title', 'Долг канонски наслов што останува на самата објава');
    }

    public function test_latest_section_can_hide_articles_outside_its_freshness_window(): void
    {
        Article::factory()->create(['slug' => 'old-news', 'status' => 'published', 'published_at' => now()->subDays(45)]);
        $fresh = Article::factory()->create(['slug' => 'fresh-news', 'status' => 'published', 'published_at' => now()->subDay()]);
        HomepageSection::query()->create([
            'key' => 'latest',
            'name' => 'Најново',
            'type' => 'latest',
            'configuration' => ['limit' => 6, 'freshness_days' => 14, 'fallback_strategy' => 'hide'],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.articles.0.slug', $fresh->slug)
            ->assertJsonMissing(['slug' => 'old-news']);
    }

    public function test_latest_section_can_fill_with_evergreen_content(): void
    {
        $old = Article::factory()->create(['slug' => 'evergreen-story', 'status' => 'published', 'published_at' => now()->subDays(45)]);
        HomepageSection::query()->create([
            'key' => 'latest',
            'name' => 'Најново',
            'type' => 'latest',
            'configuration' => ['limit' => 2, 'freshness_days' => 14, 'fallback_strategy' => 'evergreen'],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.articles.0.slug', $old->slug);
    }

    public function test_manual_pin_is_not_rejected_by_automatic_freshness_rules(): void
    {
        $pinned = Article::factory()->create(['slug' => 'editorial-pin', 'status' => 'published', 'published_at' => now()->subYears(2)]);
        HomepageSection::query()->create([
            'key' => 'featured',
            'name' => 'Издвоено',
            'type' => 'manual',
            'configuration' => ['limit' => 1, 'freshness_days' => 7, 'article_slugs' => [$pinned->slug]],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.articles.0.slug', $pinned->slug);
    }
}
