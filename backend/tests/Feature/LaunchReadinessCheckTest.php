<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdZone;
use App\Models\Article;
use App\Models\HomepageSection;
use App\Models\SiteSetting;
use App\Models\WordpressImportRecord;
use App\Services\Operations\LaunchReadinessCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LaunchReadinessCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_readiness_service_reports_blockers_and_ready_checks(): void
    {
        Storage::fake('public');
        config([
            'queue.default' => 'sync',
            'scout.driver' => 'database',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 1,
            'wordpress_type' => 'post',
            'status' => 'failed',
            'warnings' => [],
            'error_message' => 'Missing title',
        ]);

        $summary = app(LaunchReadinessCheckService::class)->summary();

        $this->assertFalse($summary['ready']);
        $this->assertContains('queue_not_async', collect($summary['checks'])->pluck('code')->all());
        $this->assertContains('missing_site_settings', collect($summary['checks'])->pluck('code')->all());
        $this->assertContains('failed_wordpress_imports', collect($summary['checks'])->pluck('code')->all());
    }

    public function test_launch_readiness_command_returns_failure_when_blockers_exist(): void
    {
        $this->artisan('launch:qa')
            ->expectsOutputToContain('Launch readiness')
            ->assertFailed();
    }

    public function test_launch_readiness_command_can_pass_for_minimum_ready_local_state(): void
    {
        config([
            'queue.default' => 'database',
            'scout.driver' => 'database',
            'ads.required_zone_keys' => ['home_top_billboard'],
        ]);

        foreach (['site_name', 'site_url', 'default_meta_title', 'default_meta_description'] as $key) {
            SiteSetting::query()->create([
                'key' => $key,
                'value' => ['ready'],
                'group' => 'qa',
                'is_public' => true,
            ]);
        }

        Article::factory()->published()->create();
        HomepageSection::query()->create([
            'key' => 'latest',
            'name' => 'Latest',
            'type' => 'latest',
            'configuration' => ['limit' => 10],
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $zone = AdZone::factory()->create(['key' => 'home_top_billboard']);
        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
        AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Launch coverage',
            'type' => 'html',
            'html' => '<div>Launch creative</div>',
            'is_fallback' => false,
            'is_active' => true,
        ]);

        $this->artisan('launch:qa')
            ->expectsOutputToContain('Launch readiness: ready')
            ->assertSuccessful();
    }

    public function test_launch_readiness_hard_fails_active_homepage_issues_and_required_ad_delivery_gaps(): void
    {
        config([
            'queue.default' => 'database',
            'ads.required_zone_keys' => ['home_top_billboard'],
        ]);
        HomepageSection::query()->create([
            'key' => 'broken_manual',
            'name' => 'Broken manual',
            'type' => 'manual',
            'configuration' => ['article_slugs' => ['missing-article']],
            'is_active' => true,
            'sort_order' => 1,
        ]);
        AdZone::factory()->create(['key' => 'home_top_billboard', 'is_active' => true]);

        $summary = app(LaunchReadinessCheckService::class)->summary();
        $checks = collect($summary['checks'])->keyBy('code');

        $this->assertFalse($summary['ready']);
        $this->assertSame('fail', $checks['homepage_qa']['status']);
        $this->assertSame('manual_article_missing', $checks['homepage_qa']['context']['issues'][0]['code']);
        $this->assertSame('fail', $checks['required_ad_zone_delivery']['status']);
        $this->assertSame('empty', $checks['required_ad_zone_delivery']['context']['undeliverable_zones'][0]['delivery_status']);
    }

    public function test_launch_readiness_hard_fails_manual_review_markers_and_critical_seo(): void
    {
        config([
            'queue.default' => 'database',
            'ads.required_zone_keys' => [],
        ]);
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 41,
            'old_url' => 'https://old.example.test/unredirected-manual-review/',
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 41,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => $article->old_url,
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['manual_review'],
        ]);
        HomepageSection::query()->create([
            'key' => 'latest',
            'name' => 'Latest',
            'type' => 'latest',
            'configuration' => ['limit' => 10],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $summary = app(LaunchReadinessCheckService::class)->summary();
        $migrationCheck = collect($summary['checks'])->keyBy('code')['wordpress_migration_readiness'];
        $blockingMigrationChecks = collect($migrationCheck['context']['checks'])->keyBy('code');

        $this->assertFalse($summary['ready']);
        $this->assertSame('fail', $migrationCheck['status']);
        $this->assertSame('fail', $blockingMigrationChecks['manual_review_items']['status']);
        $this->assertSame('fail', $blockingMigrationChecks['public_seo_issues']['status']);
    }

    public function test_launch_readiness_command_supports_json_output(): void
    {
        $exitCode = Artisan::call('launch:qa', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"ready": false', $output);
        $this->assertStringContainsString('"checks"', $output);
    }
}
