<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleBlock;
use App\Models\Author;
use App\Models\Category;
use App\Models\SeoMetadata;
use App\Models\SponsoredContentDetail;
use App\Models\Tag;
use App\Models\WordpressImportMapping;
use App\Models\WordpressImportRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_detail_returns_only_published_articles_with_seo_and_taxonomy_data(): void
    {
        $category = Category::factory()->create([
            'name' => 'Вести',
            'slug' => 'vesti',
        ]);

        $tag = Tag::query()->create([
            'name' => 'Култура',
            'slug' => 'kultura',
        ]);

        $article = Article::factory()
            ->published()
            ->for($category)
            ->create([
                'title' => 'Јавна објава',
                'slug' => 'javna-objava',
                'body_html' => '<p>Објавен текст за јавната страна.</p>',
            ]);

        $article->tags()->attach($tag);

        SeoMetadata::query()->create([
            'seoable_type' => Article::class,
            'seoable_id' => $article->id,
            'seo_title' => 'SEO наслов',
            'meta_description' => 'SEO опис за јавната објава.',
        ]);

        Article::factory()->create([
            'slug' => 'draft-objava',
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->getJson('/api/articles/javna-objava')
            ->assertOk()
            ->assertJsonPath('data.slug', 'javna-objava')
            ->assertJsonPath('data.category.slug', 'vesti')
            ->assertJsonPath('data.tags.0.slug', 'kultura')
            ->assertJsonPath('data.seo.title', 'SEO наслов')
            ->assertJsonPath('data.body_html', '<p>Објавен текст за јавната страна.</p>');

        $this->getJson('/api/articles/draft-objava')->assertNotFound();
    }

    public function test_legacy_permanent_preview_tokens_are_not_public_routes(): void
    {
        Article::factory()->create([
            'title' => 'Работна верзија за преглед',
            'slug' => 'rabotna-verzija-za-pregled',
            'status' => 'review',
            'published_at' => null,
            'preview_token' => 'legacy-permanent-preview-token',
            'body_html' => '<p>Ова е текст за уреднички преглед.</p>',
        ]);

        $this->getJson('/api/articles/rabotna-verzija-za-pregled')->assertNotFound();
        $this->getJson('/api/articles/rabotna-verzija-za-pregled/preview?preview_token=legacy-permanent-preview-token')
            ->assertNotFound();
    }

    public function test_article_detail_exposes_only_safe_wordpress_migration_metadata(): void
    {
        $article = Article::factory()
            ->published()
            ->create([
                'slug' => 'uvezena-wordpress-objava',
                'old_wordpress_id' => 12345,
                'old_url' => 'https://old.example.test/uvezena-wordpress-objava/',
            ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 12345,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/uvezena-wordpress-objava/',
            'new_url' => '/uvezena-wordpress-objava',
            'status' => 'failed',
            'source_payload' => ['private_token' => 'do-not-expose'],
            'warnings' => ['internal cleanup warning'],
            'error_message' => 'Importer stack trace should stay private.',
        ]);

        Article::factory()
            ->published()
            ->create([
                'slug' => 'native-objava',
            ]);

        Article::factory()
            ->create([
                'slug' => 'skriena-uvezena-objava',
                'old_wordpress_id' => 99999,
                'old_url' => 'https://old.example.test/skriena-uvezena-objava/',
                'status' => 'draft',
                'published_at' => null,
            ]);

        $this->getJson('/api/articles/uvezena-wordpress-objava')
            ->assertOk()
            ->assertJsonPath('data.old_wordpress_id', 12345)
            ->assertJsonPath('data.old_url', 'https://old.example.test/uvezena-wordpress-objava/')
            ->assertJsonMissingPath('data.source_payload')
            ->assertJsonMissingPath('data.warnings')
            ->assertJsonMissingPath('data.error_message')
            ->assertDontSee('do-not-expose')
            ->assertDontSee('Importer stack trace should stay private.');

        $this->getJson('/api/articles/native-objava')
            ->assertOk()
            ->assertJsonPath('data.old_wordpress_id', null)
            ->assertJsonPath('data.old_url', null);

        $this->getJson('/api/articles/skriena-uvezena-objava')->assertNotFound();
    }

    public function test_article_detail_returns_ordered_article_blocks_when_present(): void
    {
        $article = Article::factory()
            ->published()
            ->create([
                'slug' => 'blokirana-objava',
                'body_html' => '<p>Fallback HTML remains available.</p>',
            ]);

        ArticleBlock::query()->create([
            'article_id' => $article->id,
            'type' => 'quote',
            'data' => ['text' => 'Втор блок', 'attribution' => 'Редакција'],
            'sort_order' => 20,
        ]);

        ArticleBlock::query()->create([
            'article_id' => $article->id,
            'type' => 'paragraph',
            'data' => ['html' => '<p>Прв блок</p>'],
            'sort_order' => 10,
        ]);

        $this->getJson('/api/articles/blokirana-objava')
            ->assertOk()
            ->assertJsonCount(2, 'data.blocks')
            ->assertJsonPath('data.blocks.0.type', 'paragraph')
            ->assertJsonPath('data.blocks.0.data.html', '<p>Прв блок</p>')
            ->assertJsonPath('data.blocks.1.type', 'quote')
            ->assertJsonPath('data.blocks.1.data.attribution', 'Редакција')
            ->assertJsonPath('data.body_html', '<p>Fallback HTML remains available.</p>');
    }

    public function test_article_detail_builds_breadcrumbs_and_returns_sponsored_campaign_metadata(): void
    {
        $category = Category::factory()->create([
            'name' => 'Бизнис',
            'slug' => 'biznis',
        ]);

        $article = Article::factory()
            ->published()
            ->for($category)
            ->create([
                'title' => 'Партнерска приказна',
                'slug' => 'partnerska-prikazna',
                'is_sponsored' => true,
                'type' => 'sponsored',
            ]);

        SponsoredContentDetail::query()->create([
            'article_id' => $article->id,
            'sponsor_name' => 'Локален партнер',
            'sponsor_url' => 'https://partner.example',
            'disclosure_label' => 'Платена промоција',
            'campaign_start_at' => '2026-06-01 08:00:00',
            'campaign_end_at' => '2026-06-30 20:00:00',
            'reportable_views' => 123,
            'reportable_clicks' => 7,
        ]);

        $this->getJson('/api/articles/partnerska-prikazna')
            ->assertOk()
            ->assertJsonPath('data.seo.breadcrumbs.0.name', 'Почетна')
            ->assertJsonPath('data.seo.breadcrumbs.1.name', 'Бизнис')
            ->assertJsonPath('data.seo.breadcrumbs.1.url', '/category/biznis')
            ->assertJsonPath('data.seo.breadcrumbs.2.name', 'Партнерска приказна')
            ->assertJsonPath('data.sponsored_content.sponsor_name', 'Локален партнер')
            ->assertJsonPath('data.sponsored_content.disclosure_label', 'Платена промоција')
            ->assertJsonPath('data.sponsored_content.campaign_start_at', '2026-06-01T08:00:00.000000Z')
            ->assertJsonPath('data.sponsored_content.campaign_end_at', '2026-06-30T20:00:00.000000Z')
            ->assertJsonPath('data.sponsored_content.reportable_views', 123)
            ->assertJsonPath('data.sponsored_content.reportable_clicks', 7);
    }

    public function test_category_archive_returns_active_category_with_only_published_articles(): void
    {
        $category = Category::factory()->create([
            'name' => 'Живот',
            'slug' => 'zivot',
            'is_active' => true,
        ]);

        $published = Article::factory()
            ->published()
            ->for($category)
            ->create(['title' => 'Објавена категорија']);

        Article::factory()
            ->for($category)
            ->create(['title' => 'Нацрт категорија']);

        $this->getJson('/api/categories/zivot/articles')
            ->assertOk()
            ->assertJsonPath('data.category.slug', 'zivot')
            ->assertJsonPath('data.articles.data.0.slug', $published->slug)
            ->assertJsonCount(1, 'data.articles.data');

        $inactive = Category::factory()->create([
            'slug' => 'skriena',
            'is_active' => false,
        ]);

        $this->getJson("/api/categories/{$inactive->slug}/articles")->assertNotFound();
    }

    public function test_category_archive_resolves_wordpress_category_alias_to_canonical_category(): void
    {
        $category = Category::factory()->create([
            'name' => 'Урбано',
            'slug' => 'urbano',
            'is_active' => true,
        ]);

        WordpressImportMapping::query()->create([
            'source_type' => 'category',
            'wordpress_id' => 140,
            'source_slug' => '%d1%83%d1%80%d0%b1%d0%b0%d0%bd%d0%be',
            'target_type' => Category::class,
            'target_id' => $category->id,
        ]);

        $published = Article::factory()
            ->published()
            ->for($category)
            ->create(['title' => 'Урбана приказна']);

        $this->getJson('/api/categories/%D1%83%D1%80%D0%B1%D0%B0%D0%BD%D0%BE/articles')
            ->assertOk()
            ->assertJsonPath('data.category.slug', 'urbano')
            ->assertJsonPath('data.articles.data.0.slug', $published->slug)
            ->assertJsonCount(1, 'data.articles.data');
    }

    public function test_category_archive_includes_secondary_wordpress_category_memberships(): void
    {
        $primary = Category::factory()->create([
            'name' => 'Дом и Дизајн',
            'slug' => 'dom-i-dizajn',
            'is_active' => true,
        ]);

        $secondary = Category::factory()->create([
            'name' => 'Урбано',
            'slug' => 'urbano',
            'is_active' => true,
        ]);

        $published = Article::factory()
            ->published()
            ->for($primary)
            ->create(['title' => 'Урбана приказна со примарна рубрика']);

        $draft = Article::factory()
            ->for($primary)
            ->create([
                'title' => 'Скриена урбана приказна',
                'status' => 'draft',
                'published_at' => null,
            ]);

        $published->categories()->attach($secondary);
        $draft->categories()->attach($secondary);

        $this->getJson('/api/categories/urbano/articles')
            ->assertOk()
            ->assertJsonPath('data.category.slug', 'urbano')
            ->assertJsonPath('data.articles.data.0.slug', $published->slug)
            ->assertJsonPath('data.articles.data.0.category.slug', 'dom-i-dizajn')
            ->assertJsonCount(1, 'data.articles.data');
    }

    public function test_tag_archive_returns_only_published_articles(): void
    {
        $tag = Tag::query()->create([
            'name' => 'Настани',
            'slug' => 'nastani',
        ]);

        $published = Article::factory()->published()->create(['title' => 'Објавен таг']);
        $draft = Article::factory()->create(['title' => 'Нацрт таг']);

        $published->tags()->attach($tag);
        $draft->tags()->attach($tag);

        $this->getJson('/api/tags/nastani/articles')
            ->assertOk()
            ->assertJsonPath('data.tag.slug', 'nastani')
            ->assertJsonPath('data.articles.data.0.slug', $published->slug)
            ->assertJsonCount(1, 'data.articles.data');
    }

    public function test_tag_archive_returns_seo_metadata_and_breadcrumb_context(): void
    {
        $tag = Tag::query()->create([
            'name' => 'Култура',
            'slug' => 'kultura',
        ]);

        SeoMetadata::query()->create([
            'seoable_type' => Tag::class,
            'seoable_id' => $tag->id,
            'seo_title' => 'SEO таг култура',
            'meta_description' => 'Архива за културни теми.',
            'og_title' => 'OG таг култура',
            'og_description' => 'OG опис за култура.',
        ]);

        $this->getJson('/api/tags/kultura/articles')
            ->assertOk()
            ->assertJsonPath('data.tag.seo.title', 'SEO таг култура')
            ->assertJsonPath('data.tag.seo.meta_description', 'Архива за културни теми.')
            ->assertJsonPath('data.tag.seo.og_title', 'OG таг култура')
            ->assertJsonPath('data.tag.seo.breadcrumbs.0.name', 'Почетна')
            ->assertJsonPath('data.tag.seo.breadcrumbs.1.name', '#Култура')
            ->assertJsonPath('data.tag.seo.breadcrumbs.1.url', '/tag/kultura');
    }

    public function test_search_returns_matching_published_articles_only(): void
    {
        Article::factory()
            ->published()
            ->create([
                'title' => 'Битолска културна приказна',
                'excerpt' => 'Настан во центарот.',
            ]);

        Article::factory()->create([
            'title' => 'Битолска скриена приказна',
            'status' => 'draft',
            'published_at' => null,
        ]);

        Article::factory()
            ->published()
            ->create(['title' => 'Сосема друга тема']);

        $this->getJson('/api/search?query='.rawurlencode('Битолска'))
            ->assertOk()
            ->assertJsonPath('data.query', 'Битолска')
            ->assertJsonCount(1, 'data.articles.data')
            ->assertJsonPath('data.articles.data.0.title', 'Битолска културна приказна');
    }

    public function test_search_supports_category_tag_author_filters_and_returns_filter_metadata(): void
    {
        $author = Author::factory()->create([
            'name' => 'Разбуди Се Редакција',
            'slug' => 'razbudi-se-redakcija',
            'is_active' => true,
        ]);

        $category = Category::factory()->create([
            'name' => 'Живот',
            'slug' => 'zivot',
            'is_active' => true,
        ]);

        $otherCategory = Category::factory()->create([
            'name' => 'Бизнис',
            'slug' => 'biznis',
            'is_active' => true,
        ]);

        $tag = Tag::query()->create([
            'name' => 'Култура',
            'slug' => 'kultura',
        ]);

        $matching = Article::factory()
            ->published()
            ->for($author)
            ->for($category)
            ->create([
                'title' => 'Култура во градот',
                'slug' => 'kultura-vo-gradot',
            ]);
        $matching->tags()->attach($tag);

        Article::factory()
            ->published()
            ->for($author)
            ->for($otherCategory)
            ->create([
                'title' => 'Култура во компаниите',
                'slug' => 'kultura-vo-kompaniite',
            ]);

        $this->getJson('/api/search?query='.rawurlencode('Култура').'&category=zivot&tag=kultura&author=razbudi-se-redakcija')
            ->assertOk()
            ->assertJsonPath('data.query', 'Култура')
            ->assertJsonPath('data.filters.selected.category', 'zivot')
            ->assertJsonPath('data.filters.selected.tag', 'kultura')
            ->assertJsonPath('data.filters.selected.author', 'razbudi-se-redakcija')
            ->assertJsonPath('data.filters.categories.0.slug', 'zivot')
            ->assertJsonPath('data.filters.tags.0.slug', 'kultura')
            ->assertJsonPath('data.filters.authors.0.slug', 'razbudi-se-redakcija')
            ->assertJsonCount(1, 'data.articles.data')
            ->assertJsonPath('data.articles.data.0.slug', 'kultura-vo-gradot');
    }

    public function test_events_endpoint_returns_only_published_event_articles(): void
    {
        $category = Category::factory()->create([
            'name' => 'Настани',
            'slug' => 'nastani',
            'is_active' => true,
        ]);

        $event = Article::factory()
            ->published()
            ->for($category)
            ->create([
                'title' => 'Културен викенд',
                'slug' => 'kulturen-vikend',
                'type' => 'event',
                'published_at' => now()->subHour(),
            ]);

        Article::factory()
            ->published()
            ->for($category)
            ->create([
                'title' => 'Обична вест',
                'slug' => 'obicna-vest',
                'type' => 'news',
            ]);

        Article::factory()
            ->for($category)
            ->create([
                'title' => 'Скриен настан',
                'slug' => 'skrien-nastan',
                'status' => 'draft',
                'type' => 'event',
                'published_at' => null,
            ]);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('data.0.slug', $event->slug)
            ->assertJsonPath('data.0.type', 'event')
            ->assertJsonPath('data.0.category.slug', 'nastani')
            ->assertJsonCount(1, 'data');
    }

    public function test_article_related_endpoint_prefers_same_category_and_excludes_current_article(): void
    {
        $category = Category::factory()->create(['slug' => 'kultura']);
        $otherCategory = Category::factory()->create(['slug' => 'biznis']);

        $article = Article::factory()
            ->published()
            ->for($category)
            ->create(['slug' => 'glavna-prikazna']);

        $related = Article::factory()
            ->published()
            ->for($category)
            ->create(['slug' => 'povrzana-prikazna']);

        Article::factory()
            ->published()
            ->for($otherCategory)
            ->create(['slug' => 'druga-rubrika']);

        Article::factory()
            ->for($category)
            ->create([
                'slug' => 'nacrt-rubrika',
                'status' => 'draft',
                'published_at' => null,
            ]);

        $response = $this->getJson("/api/articles/{$article->slug}/related")
            ->assertOk()
            ->assertJsonPath('data.0.slug', $related->slug);

        $this->assertNotContains($article->slug, collect($response->json('data'))->pluck('slug'));
    }

    public function test_article_related_endpoint_falls_back_to_latest_articles_when_category_has_no_siblings(): void
    {
        $category = Category::factory()->create(['slug' => 'bitola']);
        $otherCategory = Category::factory()->create(['slug' => 'vesti']);

        $article = Article::factory()
            ->published()
            ->for($category)
            ->create(['slug' => 'edinstvena-prikazna']);

        $fallback = Article::factory()
            ->published()
            ->for($otherCategory)
            ->create(['slug' => 'najnov-fallback']);

        Article::factory()
            ->for($otherCategory)
            ->create([
                'slug' => 'nacrt-fallback',
                'status' => 'draft',
                'published_at' => null,
            ]);

        $this->getJson("/api/articles/{$article->slug}/related")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $fallback->slug);
    }
}
