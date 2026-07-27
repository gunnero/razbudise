<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\Category;
use App\Services\Articles\EditorialQualityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorialQualityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_explainable_non_blocking_editorial_warnings(): void
    {
        $category = Category::factory()->create(['slug' => 'uncategorized']);
        $article = Article::factory()->create([
            'title' => 'ОВА Е ПРЕДОЛГ НАСЛОВ ШТО ТРЕБА ДА БИДЕ ПРОВЕРЕН ОД УРЕДНИК ПРЕД ДА СЕ ПОЈАВИ НА ПОЧЕТНАТА СТРАНИЦА НА ПОРТАЛОТ И ПОВТОРНО ДА СЕ ПРОВЕРИ',
            'excerpt' => null,
            'body_html' => '<p>Lorem ipsum</p>',
            'category_id' => $category->id,
            'author_id' => null,
            'featured_image_id' => null,
        ]);

        $codes = collect(app(EditorialQualityService::class)->inspect($article))->pluck('code');

        $this->assertContains('title_too_long', $codes);
        $this->assertContains('title_mostly_uppercase', $codes);
        $this->assertContains('missing_excerpt', $codes);
        $this->assertContains('missing_featured_image', $codes);
        $this->assertContains('default_category', $codes);
        $this->assertContains('missing_author', $codes);
        $this->assertContains('placeholder_content', $codes);
    }

    public function test_it_warns_when_excerpt_duplicates_the_canonical_title(): void
    {
        $article = Article::factory()->create([
            'title' => 'Наслов со нормална должина',
            'excerpt' => 'Наслов со нормална должина',
        ]);

        $codes = collect(app(EditorialQualityService::class)->inspect($article))->pluck('code');

        $this->assertContains('excerpt_duplicates_title', $codes);
    }

    public function test_it_reviews_the_optional_homepage_excerpt_without_changing_canonical_copy(): void
    {
        $article = Article::factory()->create([
            'title' => 'Наслов со нормална должина',
            'excerpt' => 'Краток и уреден канонски опис.',
            'homepage_excerpt' => str_repeat('Предолг опис за почетна. ', 14),
        ]);

        $codes = collect(app(EditorialQualityService::class)->inspect($article))->pluck('code');

        $this->assertContains('excerpt_long', $codes);
        $this->assertSame('Краток и уреден канонски опис.', $article->excerpt);
    }
}
