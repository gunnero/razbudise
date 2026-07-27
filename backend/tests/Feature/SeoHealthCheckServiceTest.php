<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Redirect;
use App\Models\SiteSetting;
use App\Models\Tag;
use App\Services\Seo\SeoHealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoHealthCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_health_summary_reports_published_article_gaps(): void
    {
        Article::factory()->published()->create([
            'title' => 'Објава без SEO опис',
            'slug' => 'objava-bez-seo-opis',
            'excerpt' => null,
            'featured_image_id' => null,
            'old_url' => 'https://razbudise.mk/old-post/',
        ]);

        $summary = app(SeoHealthCheckService::class)->summary();
        $issueCodes = collect($summary['issues'])->pluck('code')->all();

        $this->assertSame(3, $summary['totals']['issues']);
        $this->assertContains('missing_meta_description', $issueCodes);
        $this->assertContains('missing_og_image', $issueCodes);
        $this->assertContains('missing_redirect', $issueCodes);
    }

    public function test_seo_health_summary_accepts_default_og_image_for_articles_without_images(): void
    {
        SiteSetting::query()->create([
            'key' => 'default_og_image',
            'value' => ['/brand/razbudise-logo.png'],
            'group' => 'seo',
            'is_public' => true,
        ]);

        Article::factory()->published()->create([
            'title' => 'Објава со site OG fallback',
            'slug' => 'objava-so-site-og-fallback',
            'excerpt' => 'Јасен опис за објава со default Open Graph слика.',
            'featured_image_id' => null,
        ]);

        $summary = app(SeoHealthCheckService::class)->summary();

        $this->assertSame(0, $summary['totals']['issues']);
        $this->assertSame([], $summary['issues']);
    }

    public function test_seo_health_summary_adds_severity_to_critical_and_nice_to_have_issues(): void
    {
        Article::factory()->published()->create([
            'title' => 'Објава со стар линк',
            'slug' => 'objava-so-star-link',
            'excerpt' => 'Опис за објава со стар линк.',
            'old_url' => 'https://razbudise.mk/star-link/',
        ]);

        Tag::query()->create([
            'name' => 'Летен одмор',
            'slug' => 'leten-odmor',
        ]);

        $summary = app(SeoHealthCheckService::class)->summary();
        $issuesByCode = collect($summary['issues'])->keyBy('code');

        $this->assertSame('critical', $issuesByCode->get('missing_redirect')['severity']);
        $this->assertSame('warning', $issuesByCode->get('missing_og_image')['severity']);
        $this->assertSame('nice_to_have', $issuesByCode->get('tag_missing_meta_description')['severity']);
        $this->assertSame([
            'critical' => 1,
            'warning' => 1,
            'nice_to_have' => 1,
        ], $summary['totals']['issues_by_severity']);
    }

    public function test_public_qa_command_can_show_severity_summary_without_full_issue_list(): void
    {
        Tag::query()->create([
            'name' => 'Летен одмор',
            'slug' => 'leten-odmor',
        ]);

        $this->artisan('seo:public-qa --summary')
            ->expectsOutput('Public SEO issues: 1')
            ->expectsOutput('Critical issues: 0')
            ->expectsOutput('Warnings: 0')
            ->expectsOutput('Nice-to-have issues: 1')
            ->doesntExpectOutput('[tag_missing_meta_description] http://localhost:3001/tag/leten-odmor Public tag page is missing a meta description.')
            ->assertSuccessful();
    }

    public function test_public_qa_command_fails_only_when_critical_seo_issues_exist(): void
    {
        Article::factory()->published()->create([
            'title' => 'Објава без стар redirect',
            'slug' => 'objava-bez-star-redirect',
            'excerpt' => 'Опис за објава без стар redirect.',
            'old_url' => 'https://razbudise.mk/stara-objava-bez-redirect/',
        ]);

        $this->artisan('seo:public-qa --summary')
            ->expectsOutput('Critical issues: 1')
            ->assertFailed();
    }

    public function test_seo_health_summary_ignores_healthy_published_articles(): void
    {
        $image = MediaAsset::factory()->create();
        $article = Article::factory()->published()->create([
            'title' => 'Здрава SEO објава',
            'slug' => 'zdrava-seo-objava',
            'excerpt' => 'Јасен SEO опис.',
            'featured_image_id' => $image->id,
            'old_url' => 'https://razbudise.mk/zdrava-old-url/',
        ]);

        $article->seoMetadata()->create([
            'meta_description' => 'Јасен SEO опис за јавна страница.',
            'canonical_url' => $article->publicUrl(),
            'og_image_id' => $image->id,
        ]);

        Redirect::query()->create([
            'source_url' => 'https://razbudise.mk/zdrava-old-url/',
            'target_url' => '/zdrava-seo-objava',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $summary = app(SeoHealthCheckService::class)->summary();

        $this->assertSame(0, $summary['totals']['issues']);
        $this->assertSame([], $summary['issues']);
    }

    public function test_seo_health_summary_does_not_require_redirect_when_wordpress_url_is_preserved(): void
    {
        $image = MediaAsset::factory()->create();
        $article = Article::factory()->published()->create([
            'title' => 'Зачувана WordPress адреса',
            'slug' => 'zacuvana-wordpress-adresa',
            'excerpt' => 'Јасен SEO опис.',
            'featured_image_id' => $image->id,
            'old_url' => 'https://razbudise.mk/zacuvana-wordpress-adresa/',
        ]);

        $article->seoMetadata()->create([
            'meta_description' => 'Јасен SEO опис за зачувана јавна адреса.',
            'canonical_url' => $article->publicUrl(),
            'og_image_id' => $image->id,
        ]);

        $summary = app(SeoHealthCheckService::class)->summary();

        $this->assertSame(0, $summary['totals']['issues']);
        $this->assertSame([], $summary['issues']);
    }

    public function test_seo_health_summary_reports_stale_article_canonical_urls(): void
    {
        $image = MediaAsset::factory()->create();
        $article = Article::factory()->published()->create([
            'title' => 'Објава со стар canonical',
            'slug' => 'objava-so-star-canonical',
            'excerpt' => 'Јасен опис за canonical проверка.',
            'featured_image_id' => $image->id,
        ]);

        $article->seoMetadata()->create([
            'meta_description' => 'Јасен опис за canonical проверка.',
            'canonical_url' => 'https://razbudise.mk/objava-so-star-canonical/',
            'og_image_id' => $image->id,
        ]);

        $summary = app(SeoHealthCheckService::class)->summary();

        $this->assertSame(1, $summary['totals']['issues']);
        $this->assertSame('stale_canonical_url', $summary['issues'][0]['code']);
    }

    public function test_seo_health_summary_reports_public_category_tag_and_author_gaps(): void
    {
        Category::factory()->create([
            'name' => 'Живот',
            'slug' => 'zivot',
            'description' => null,
            'is_active' => true,
        ]);

        Tag::query()->create([
            'name' => 'Настани',
            'slug' => 'nastani',
        ]);

        Author::factory()->create([
            'name' => 'Автор без SEO',
            'slug' => 'avtor-bez-seo',
            'short_bio' => null,
            'is_active' => true,
        ]);

        Author::factory()->create([
            'name' => 'Скриен автор без SEO',
            'slug' => 'skrien-avtor-bez-seo',
            'short_bio' => null,
            'is_active' => false,
        ]);

        $summary = app(SeoHealthCheckService::class)->summary();
        $codes = collect($summary['issues'])->pluck('code')->all();

        $this->assertSame(0, $summary['totals']['articles_checked']);
        $this->assertSame(1, $summary['totals']['categories_checked']);
        $this->assertSame(1, $summary['totals']['tags_checked']);
        $this->assertSame(1, $summary['totals']['authors_checked']);
        $this->assertSame(3, $summary['totals']['issues']);
        $this->assertContains('category_missing_meta_description', $codes);
        $this->assertContains('tag_missing_meta_description', $codes);
        $this->assertContains('author_missing_meta_description', $codes);
        $this->assertNotContains('inactive_author_missing_meta_description', $codes);
    }

    public function test_seo_health_summary_reports_stale_public_page_canonical_urls(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001/']);

        $category = Category::factory()->create([
            'name' => 'Култура',
            'slug' => 'kultura',
            'description' => 'Културни приказни и препораки.',
            'is_active' => true,
        ]);
        $category->seoMetadata()->create([
            'meta_description' => 'Културни приказни и препораки.',
            'canonical_url' => 'https://razbudise.mk/category/kultura/',
        ]);

        $tag = Tag::query()->create([
            'name' => 'Интервју',
            'slug' => 'intervju',
        ]);
        $tag->seoMetadata()->create([
            'meta_description' => 'Разговори и интервјуа.',
            'canonical_url' => 'https://razbudise.mk/tag/intervju/',
        ]);

        $author = Author::factory()->create([
            'name' => 'Разбуди Се',
            'slug' => 'razbudi-se',
            'short_bio' => 'Редакциски профил.',
            'is_active' => true,
        ]);
        $author->seoMetadata()->create([
            'meta_description' => 'Редакциски профил.',
            'canonical_url' => 'https://razbudise.mk/profil/razbudi-se/',
        ]);

        $summary = app(SeoHealthCheckService::class)->summary();
        $codes = collect($summary['issues'])->pluck('code')->all();

        $this->assertSame(3, $summary['totals']['issues']);
        $this->assertContains('category_stale_canonical_url', $codes);
        $this->assertContains('tag_stale_canonical_url', $codes);
        $this->assertContains('author_stale_canonical_url', $codes);
    }
}
