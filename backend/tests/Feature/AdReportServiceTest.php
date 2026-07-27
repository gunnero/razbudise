<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdClick;
use App\Models\AdCreative;
use App\Models\AdImpression;
use App\Models\AdZone;
use App\Services\Ads\AdReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_summarizes_totals_campaigns_and_zones_for_date_range(): void
    {
        $zone = AdZone::factory()->create([
            'key' => 'home_top_billboard',
            'name' => 'Home top billboard',
        ]);

        $campaign = AdCampaign::factory()->create([
            'name' => 'Summer launch',
            'status' => 'active',
            'start_at' => '2026-06-01 00:00:00',
            'end_at' => '2026-06-30 23:59:59',
        ]);

        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Leaderboard',
            'type' => 'image',
            'width' => 728,
            'height' => 90,
            'is_fallback' => false,
            'is_active' => true,
        ]);

        foreach (['2026-06-05 10:00:00', '2026-06-05 11:00:00', '2026-06-06 12:00:00'] as $occurredAt) {
            AdImpression::query()->create([
                'campaign_id' => $campaign->id,
                'creative_id' => $creative->id,
                'zone_id' => $zone->id,
                'occurred_at' => $occurredAt,
            ]);
        }

        AdImpression::query()->create([
            'campaign_id' => $campaign->id,
            'creative_id' => $creative->id,
            'zone_id' => $zone->id,
            'occurred_at' => '2026-05-31 23:59:59',
        ]);

        AdClick::query()->create([
            'campaign_id' => $campaign->id,
            'creative_id' => $creative->id,
            'zone_id' => $zone->id,
            'target_url' => 'https://advertiser.example.test',
            'occurred_at' => '2026-06-05 10:10:00',
        ]);

        $report = app(AdReportService::class)->summary('2026-06-01', '2026-06-30');

        $this->assertSame(3, $report['totals']['impressions']);
        $this->assertSame(1, $report['totals']['clicks']);
        $this->assertSame(33.33, $report['totals']['ctr_percent']);
        $this->assertSame('Summer launch', $report['campaigns'][0]['name']);
        $this->assertSame(3, $report['campaigns'][0]['impressions']);
        $this->assertSame(1, $report['campaigns'][0]['clicks']);
        $this->assertSame('home_top_billboard', $report['zones'][0]['key']);
        $this->assertSame('Home top billboard', $report['zones'][0]['name']);
        $this->assertSame(3, $report['zones'][0]['impressions']);
        $this->assertSame(1, $report['zones'][0]['clicks']);
    }

    public function test_ad_report_exports_campaign_rows_for_csv_downloads(): void
    {
        $campaign = AdCampaign::factory()->create([
            'name' => 'CSV campaign',
            'status' => 'active',
            'start_at' => '2026-06-01 00:00:00',
            'end_at' => '2026-06-30 23:59:59',
        ]);

        AdImpression::query()->create([
            'campaign_id' => $campaign->id,
            'occurred_at' => '2026-06-05 10:00:00',
        ]);

        $rows = app(AdReportService::class)->campaignExportRows('2026-06-01', '2026-06-30');

        $this->assertSame([
            'campaign_id' => $campaign->id,
            'campaign' => 'CSV campaign',
            'advertiser' => $campaign->advertiser->name,
            'status' => 'active',
            'impressions' => 1,
            'clicks' => 0,
            'ctr_percent' => 0.0,
        ], $rows[0]);
    }

    public function test_ad_report_exports_zone_inventory_rows(): void
    {
        $zone = AdZone::factory()->create([
            'key' => 'article_after_title',
            'name' => 'Article after title',
            'page_context' => 'article',
            'allowed_sizes' => [[728, 90], [320, 100]],
            'is_active' => true,
        ]);

        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Ready creative',
            'type' => 'image',
            'width' => 728,
            'height' => 90,
            'is_fallback' => false,
            'is_active' => true,
        ]);

        AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Fallback creative',
            'type' => 'html',
            'html' => '<strong>Fallback</strong>',
            'is_fallback' => true,
            'is_active' => true,
        ]);

        $rows = app(AdReportService::class)->zoneInventoryRows();

        $this->assertSame('article_after_title', $rows[0]['zone_key']);
        $this->assertSame('728x90, 320x100', $rows[0]['allowed_sizes']);
        $this->assertSame(2, $rows[0]['active_creatives']);
        $this->assertSame(1, $rows[0]['eligible_creatives']);
        $this->assertSame(1, $rows[0]['fallback_creatives']);
        $this->assertSame(0, $rows[0]['global_fallback_creatives']);
        $this->assertSame('yes', $rows[0]['has_launch_delivery']);
        $this->assertSame('campaign', $rows[0]['delivery_status']);
    }

    public function test_ad_report_exports_zone_inventory_delivery_statuses(): void
    {
        $campaignZone = AdZone::factory()->create([
            'key' => 'home_top_billboard',
            'page_context' => 'home',
            'is_active' => true,
        ]);
        $zoneFallbackZone = AdZone::factory()->create([
            'key' => 'category_top',
            'page_context' => 'category',
            'is_active' => true,
        ]);
        $globalFallbackZone = AdZone::factory()->create([
            'key' => 'author_page_top',
            'page_context' => 'author',
            'is_active' => true,
        ]);
        $inactiveZone = AdZone::factory()->create([
            'key' => 'mobile_sticky_bottom',
            'page_context' => 'global',
            'is_active' => false,
        ]);

        $activeCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
        $pausedCampaign = AdCampaign::factory()->create([
            'status' => 'paused',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        AdCreative::query()->create([
            'campaign_id' => $activeCampaign->id,
            'zone_id' => $campaignZone->id,
            'name' => 'Paid billboard',
            'type' => 'image',
            'width' => 970,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);

        AdCreative::query()->create([
            'campaign_id' => $pausedCampaign->id,
            'zone_id' => $zoneFallbackZone->id,
            'name' => 'Category house fallback',
            'type' => 'html',
            'html' => '<strong>Fallback</strong>',
            'is_fallback' => true,
            'is_active' => true,
        ]);

        AdCreative::query()->create([
            'campaign_id' => $pausedCampaign->id,
            'zone_id' => null,
            'name' => 'Global house fallback',
            'type' => 'html',
            'html' => '<strong>Global fallback</strong>',
            'is_fallback' => true,
            'is_active' => true,
        ]);

        AdCreative::query()->create([
            'campaign_id' => $activeCampaign->id,
            'zone_id' => $inactiveZone->id,
            'name' => 'Inactive zone paid creative',
            'type' => 'image',
            'is_fallback' => false,
            'is_active' => true,
        ]);

        $rows = collect(app(AdReportService::class)->zoneInventoryRows())->keyBy('zone_key');

        $this->assertSame('campaign', $rows['home_top_billboard']['delivery_status']);
        $this->assertSame('yes', $rows['home_top_billboard']['has_launch_delivery']);
        $this->assertSame(1, $rows['home_top_billboard']['global_fallback_creatives']);

        $this->assertSame('zone_fallback', $rows['category_top']['delivery_status']);
        $this->assertSame('yes', $rows['category_top']['has_launch_delivery']);

        $this->assertSame('global_fallback', $rows['author_page_top']['delivery_status']);
        $this->assertSame('yes', $rows['author_page_top']['has_launch_delivery']);

        $this->assertSame('inactive', $rows['mobile_sticky_bottom']['delivery_status']);
        $this->assertSame('no', $rows['mobile_sticky_bottom']['has_launch_delivery']);
    }
}
