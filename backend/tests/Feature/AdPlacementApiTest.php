<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdClick;
use App\Models\AdCreative;
use App\Models\AdImpression;
use App\Models\AdZone;
use App\Models\Article;
use App\Models\Category;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdPlacementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ad_zone_endpoint_returns_best_active_creative_when_available(): void
    {
        config(['app.url' => 'https://preview.example.test']);

        $zone = AdZone::factory()->create([
            'key' => 'author_page_top',
            'is_active' => true,
        ]);

        $image = MediaAsset::factory()->create([
            'url' => 'https://example.test/ad.jpg',
        ]);

        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 10,
        ]);

        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Author top ad',
            'type' => 'image',
            'image_id' => $image->id,
            'click_url' => 'https://example.test',
            'width' => 728,
            'height' => 90,
            'is_fallback' => false,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/ads/author_page_top')
            ->assertOk()
            ->assertJsonPath('data.zone_key', 'author_page_top')
            ->assertJsonPath('data.name', 'Author top ad')
            ->assertJsonPath('data.image.url', 'https://example.test/ad.jpg')
            ->assertJsonPath('data.impression_tracking_url', 'https://preview.example.test/api/ads/impression');

        $this->assertSignedClickTrackingUrl(
            $response->json('data.click_tracking_url'),
            "https://preview.example.test/api/ads/click/{$creative->id}",
            $response->json('data.delivery_token'),
            'author_page_top',
        );
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_ad_tracking_urls_use_configured_public_url_for_internal_requests(): void
    {
        config(['app.url' => 'https://preview.example.test']);

        $zone = AdZone::factory()->create([
            'key' => 'home_top_billboard',
            'is_active' => true,
        ]);

        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        $creative = $this->createCreative($campaign, $zone, 'Public tracking URL ad');

        $response = $this->withServerVariables(['HTTP_HOST' => '127.0.0.1:8101'])
            ->getJson('/api/ads/home_top_billboard')
            ->assertOk()
            ->assertJsonPath('data.impression_tracking_url', 'https://preview.example.test/api/ads/impression');

        $this->assertSignedClickTrackingUrl(
            $response->json('data.click_tracking_url'),
            "https://preview.example.test/api/ads/click/{$creative->id}",
            $response->json('data.delivery_token'),
            'home_top_billboard',
        );
    }

    public function test_ad_zone_endpoint_excludes_campaigns_that_reached_total_impression_cap(): void
    {
        $zone = AdZone::factory()->create([
            'key' => 'home_top_billboard',
        ]);

        $cappedCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 50,
            'max_impressions' => 1,
        ]);

        $eligibleCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 10,
        ]);

        $cappedCreative = $this->createCreative($cappedCampaign, $zone, 'Capped ad');
        $this->createCreative($eligibleCampaign, $zone, 'Eligible ad');

        AdImpression::query()->create([
            'campaign_id' => $cappedCampaign->id,
            'creative_id' => $cappedCreative->id,
            'zone_id' => $zone->id,
            'occurred_at' => now(),
        ]);

        $this->getJson('/api/ads/home_top_billboard')
            ->assertOk()
            ->assertJsonPath('data.name', 'Eligible ad');
    }

    public function test_ad_zone_endpoint_excludes_campaigns_that_reached_click_cap(): void
    {
        $zone = AdZone::factory()->create([
            'key' => 'home_in_feed_1',
        ]);

        $cappedCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 50,
            'max_clicks' => 1,
        ]);

        $eligibleCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 10,
        ]);

        $cappedCreative = $this->createCreative($cappedCampaign, $zone, 'Clicked out ad');
        $this->createCreative($eligibleCampaign, $zone, 'Still eligible ad');

        AdClick::query()->create([
            'campaign_id' => $cappedCampaign->id,
            'creative_id' => $cappedCreative->id,
            'zone_id' => $zone->id,
            'target_url' => 'https://example.test/capped',
            'occurred_at' => now(),
        ]);

        $this->getJson('/api/ads/home_in_feed_1')
            ->assertOk()
            ->assertJsonPath('data.name', 'Still eligible ad');
    }

    public function test_ad_zone_endpoint_excludes_campaigns_that_reached_daily_impression_cap(): void
    {
        $zone = AdZone::factory()->create([
            'key' => 'article_after_paragraph_3',
        ]);

        $cappedCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 50,
            'daily_impression_cap' => 1,
        ]);

        $eligibleCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 10,
        ]);

        $cappedCreative = $this->createCreative($cappedCampaign, $zone, 'Daily capped ad');
        $this->createCreative($eligibleCampaign, $zone, 'Daily eligible ad');

        AdImpression::query()->create([
            'campaign_id' => $cappedCampaign->id,
            'creative_id' => $cappedCreative->id,
            'zone_id' => $zone->id,
            'occurred_at' => now(),
        ]);

        $this->getJson('/api/ads/article_after_paragraph_3')
            ->assertOk()
            ->assertJsonPath('data.name', 'Daily eligible ad');
    }

    public function test_inactive_ad_zone_endpoint_returns_null_even_when_global_fallback_exists(): void
    {
        AdZone::factory()->create([
            'key' => 'mobile_sticky_bottom',
            'is_active' => false,
        ]);

        $campaign = AdCampaign::factory()->create([
            'status' => 'paused',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => null,
            'name' => 'Global fallback',
            'type' => 'html',
            'html' => '<strong>Fallback</strong>',
            'is_fallback' => true,
            'is_active' => true,
        ]);

        $this->getJson('/api/ads/mobile_sticky_bottom')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_ad_zone_endpoint_returns_null_when_no_campaign_or_fallback_matches(): void
    {
        AdZone::factory()->create([
            'key' => 'article_sidebar_sticky',
            'is_active' => true,
        ]);

        $this->getJson('/api/ads/article_sidebar_sticky')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_ad_zone_endpoint_returns_fallback_creative_even_when_fallback_campaign_is_not_eligible(): void
    {
        $zone = AdZone::factory()->create([
            'key' => 'category_top',
            'is_active' => true,
        ]);

        $expiredCampaign = AdCampaign::factory()->create([
            'status' => 'paused',
            'start_at' => now()->subDays(10),
            'end_at' => now()->subDay(),
            'priority' => 1,
        ]);

        AdCreative::query()->create([
            'campaign_id' => $expiredCampaign->id,
            'zone_id' => null,
            'name' => 'House fallback',
            'type' => 'html',
            'html' => '<strong>Разбуди се</strong>',
            'width' => 728,
            'height' => 90,
            'is_fallback' => true,
            'is_active' => true,
        ]);

        $this->getJson('/api/ads/category_top')
            ->assertOk()
            ->assertJsonPath('data.zone_key', 'category_top')
            ->assertJsonPath('data.name', 'House fallback')
            ->assertJsonPath('data.is_fallback', true);
    }

    public function test_ad_zone_endpoint_respects_device_category_and_article_targeting(): void
    {
        $article = Article::factory()->create();
        $zone = AdZone::factory()->create([
            'key' => 'article_after_title',
        ]);

        $targetedCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 50,
            'device_targeting' => ['mobile'],
            'category_targeting' => ['zivot'],
            'article_targeting' => [$article->id],
        ]);

        $untargetedCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 10,
            'device_targeting' => [],
            'category_targeting' => [],
            'article_targeting' => [],
        ]);

        $this->createCreative($targetedCampaign, $zone, 'Mobile targeted');
        $this->createCreative($untargetedCampaign, $zone, 'Untargeted');

        $this->getJson("/api/ads/article_after_title?device=mobile&category=zivot&article_id={$article->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Mobile targeted');

        $this->getJson("/api/ads/article_after_title?device=desktop&category=zivot&article_id={$article->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Untargeted');

        $this->getJson("/api/ads/article_after_title?device=mobile&article_id={$article->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Untargeted');

        $this->getJson('/api/ads/article_after_title?device=mobile&category=zivot')
            ->assertOk()
            ->assertJsonPath('data.name', 'Untargeted');

        $this->getJson("/api/ads/article_after_title?category=zivot&article_id={$article->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Untargeted');
    }

    public function test_ad_zone_endpoint_matches_filament_targeting_shapes(): void
    {
        $category = Category::factory()->create([
            'slug' => 'urbano',
        ]);

        $article = Article::factory()->for($category)->published()->create();

        $zone = AdZone::factory()->create([
            'key' => 'category_in_feed',
        ]);

        $targetedCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 50,
            'device_targeting' => [
                ['device' => 'mobile'],
            ],
            'category_targeting' => [$category->id],
            'article_targeting' => [$article->id],
        ]);

        $untargetedCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 10,
            'device_targeting' => [],
            'category_targeting' => [],
            'article_targeting' => [],
        ]);

        $this->createCreative($targetedCampaign, $zone, 'Filament targeted');
        $this->createCreative($untargetedCampaign, $zone, 'Untargeted fallback');

        $this->getJson("/api/ads/category_in_feed?device=mobile&category=urbano&article_id={$article->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Filament targeted');

        $this->getJson("/api/ads/category_in_feed?device=mobile&category=urbano&article_id={$article->id}&category_id={$category->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Filament targeted');
    }

    public function test_ad_zone_endpoint_rotates_equal_priority_campaigns(): void
    {
        $zone = AdZone::factory()->create([
            'key' => 'home_in_feed_2',
        ]);

        $firstCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 20,
        ]);

        $secondCampaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'priority' => 20,
        ]);

        $firstCreative = $this->createCreative($firstCampaign, $zone, 'First equal ad');
        $secondCreative = $this->createCreative($secondCampaign, $zone, 'Second equal ad');

        AdImpression::query()->create([
            'campaign_id' => $firstCampaign->id,
            'creative_id' => $firstCreative->id,
            'zone_id' => $zone->id,
            'occurred_at' => now(),
        ]);

        $this->getJson('/api/ads/home_in_feed_2')
            ->assertOk()
            ->assertJsonPath('data.name', 'Second equal ad');
    }

    public function test_impression_endpoint_records_display_event(): void
    {
        $zone = AdZone::factory()->create([
            'key' => 'article_after_title',
        ]);

        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        $creative = $this->createCreative($campaign, $zone, 'Article title ad');
        $token = $this->getJson('/api/ads/article_after_title?device=mobile')
            ->assertOk()
            ->json('data.delivery_token');

        $this->postJson('/api/ads/impression', [
            'delivery_token' => $token,
            'creative_id' => $creative->id,
            'zone_key' => 'article_after_title',
            'device' => 'mobile',
        ], [
            'User-Agent' => 'RazbudiSe test browser',
        ])
            ->assertCreated()
            ->assertJsonPath('data.recorded', true);

        $this->assertDatabaseHas(AdImpression::class, [
            'campaign_id' => $campaign->id,
            'creative_id' => $creative->id,
            'zone_id' => $zone->id,
            'device' => 'mobile',
            'user_agent' => 'RazbudiSe test browser',
        ]);
    }

    public function test_click_redirect_records_click_then_redirects_to_creative_url(): void
    {
        $zone = AdZone::factory()->create([
            'key' => 'article_sidebar_top',
        ]);

        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        $creative = $this->createCreative($campaign, $zone, 'Sidebar ad', [
            'click_url' => 'https://advertiser.example.test/landing',
        ]);
        $clickUrl = $this->getJson('/api/ads/article_sidebar_top?device=desktop')
            ->assertOk()
            ->json('data.click_tracking_url');

        $this->get($this->relativeUrl($clickUrl), [
            'User-Agent' => 'RazbudiSe click browser',
        ])
            ->assertRedirect('https://advertiser.example.test/landing');

        $this->assertDatabaseHas(AdClick::class, [
            'campaign_id' => $campaign->id,
            'creative_id' => $creative->id,
            'zone_id' => $zone->id,
            'device' => 'desktop',
            'target_url' => 'https://advertiser.example.test/landing',
            'user_agent' => 'RazbudiSe click browser',
        ]);
    }

    private function createCreative(AdCampaign $campaign, AdZone $zone, string $name, array $overrides = []): AdCreative
    {
        return AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => $name,
            'type' => 'image',
            'click_url' => 'https://example.test/'.$name,
            'width' => 728,
            'height' => 90,
            'is_fallback' => false,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function assertSignedClickTrackingUrl(
        string $url,
        string $expectedBase,
        string $deliveryToken,
        string $zoneKey,
    ): void {
        $this->assertStringStartsWith($expectedBase.'?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($deliveryToken, $query['delivery_token'] ?? null);
        $this->assertSame($zoneKey, $query['zone_key'] ?? null);
    }

    private function relativeUrl(string $absoluteUrl): string
    {
        $path = parse_url($absoluteUrl, PHP_URL_PATH) ?: '/';
        $query = parse_url($absoluteUrl, PHP_URL_QUERY);

        return $query ? $path.'?'.$query : $path;
    }
}
