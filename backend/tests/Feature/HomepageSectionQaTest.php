<?php

namespace Tests\Feature;

use App\Models\AdZone;
use App\Models\Article;
use App\Models\Category;
use App\Models\HomepageSection;
use App\Services\Homepage\HomepageSectionQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageSectionQaTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_section_qa_flags_empty_missing_and_bad_ad_zone_configuration(): void
    {
        $published = Article::factory()->published()->create(['slug' => 'published-story']);
        Article::factory()->create(['slug' => 'draft-story', 'status' => 'draft', 'published_at' => null]);
        Category::factory()->create(['slug' => 'inactive-category', 'is_active' => false]);
        AdZone::factory()->create(['key' => 'home_after_featured']);

        HomepageSection::query()->create([
            'key' => 'manual_pick',
            'name' => 'Manual pick',
            'type' => 'manual',
            'configuration' => [
                'article_slugs' => [$published->slug, 'missing-story', 'draft-story'],
                'ad_zone_after' => 'missing_zone',
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        HomepageSection::query()->create([
            'key' => 'bad_category',
            'name' => 'Bad category',
            'type' => 'category',
            'configuration' => [
                'category_slug' => 'inactive-category',
            ],
            'is_active' => true,
            'sort_order' => 2,
        ]);

        HomepageSection::query()->create([
            'key' => 'empty_latest',
            'name' => 'Empty latest',
            'type' => 'latest',
            'configuration' => ['limit' => 2],
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $summary = app(HomepageSectionQaService::class)->summary();
        $codes = collect($summary['issues'])->pluck('code')->all();

        $this->assertSame(3, $summary['totals']['sections_checked']);
        $this->assertContains('manual_article_missing', $codes);
        $this->assertContains('manual_article_unpublished', $codes);
        $this->assertContains('unknown_ad_zone', $codes);
        $this->assertContains('category_inactive', $codes);
    }

    public function test_homepage_qa_command_outputs_issue_totals(): void
    {
        HomepageSection::query()->create([
            'key' => 'empty_manual',
            'name' => 'Empty manual',
            'type' => 'manual',
            'configuration' => ['article_slugs' => []],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->artisan('homepage:qa')
            ->expectsOutput('Homepage sections checked: 1')
            ->expectsOutput('Homepage QA issues: 1')
            ->assertFailed();
    }

    public function test_homepage_qa_fails_when_there_are_no_active_sections(): void
    {
        $summary = app(HomepageSectionQaService::class)->summary();

        $this->assertSame(0, $summary['totals']['sections_checked']);
        $this->assertSame(1, $summary['totals']['no_active_sections']);
        $this->assertSame('no_active_sections', $summary['issues'][0]['code']);

        $this->artisan('homepage:qa')
            ->expectsOutput('No active homepage sections: 1')
            ->assertFailed();
    }
}
