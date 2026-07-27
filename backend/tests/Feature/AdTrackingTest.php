<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdClick;
use App\Models\AdCreative;
use App\Models\AdImpression;
use App\Models\AdZone;
use App\Services\Ads\AdDeliveryTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_creative_impression_is_rejected_without_recording(): void
    {
        $zone = AdZone::factory()->create(['key' => 'article_after_title']);
        $creative = $this->createCreative($this->activeCampaign(), $zone, [
            'is_active' => false,
        ]);
        $token = $this->deliveryToken($creative, $zone);

        $this->postJson('/api/ads/impression', [
            'delivery_token' => $token,
            'creative_id' => $creative->id,
            'zone_key' => 'article_after_title',
        ])->assertNotFound();

        $this->assertDatabaseCount(AdImpression::class, 0);
    }

    public function test_paused_campaign_impression_is_rejected_without_recording(): void
    {
        $zone = AdZone::factory()->create(['key' => 'article_after_title']);
        $creative = $this->createCreative(AdCampaign::factory()->create([
            'status' => 'paused',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]), $zone);
        $token = $this->deliveryToken($creative, $zone);

        $this->postJson('/api/ads/impression', [
            'delivery_token' => $token,
            'creative_id' => $creative->id,
            'zone_key' => 'article_after_title',
        ])->assertNotFound();

        $this->assertDatabaseCount(AdImpression::class, 0);
    }

    public function test_inactive_creative_click_is_rejected_without_recording(): void
    {
        $zone = AdZone::factory()->create(['key' => 'article_sidebar_top']);
        $creative = $this->createCreative($this->activeCampaign(), $zone, [
            'click_url' => 'https://advertiser.example.test/landing',
            'is_active' => false,
        ]);
        $token = $this->deliveryToken($creative, $zone);

        $this->postJson('/api/ads/click/'.$creative->id, [
            'delivery_token' => $token,
            'zone_key' => 'article_sidebar_top',
        ])->assertNotFound();

        $this->assertDatabaseCount(AdClick::class, 0);
    }

    public function test_paused_campaign_redirect_is_rejected_without_click_or_external_redirect(): void
    {
        $zone = AdZone::factory()->create(['key' => 'article_sidebar_top']);
        $creative = $this->createCreative(AdCampaign::factory()->create([
            'status' => 'paused',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]), $zone, [
            'click_url' => 'https://advertiser.example.test/landing',
        ]);
        $query = http_build_query([
            'delivery_token' => $this->deliveryToken($creative, $zone),
            'zone_key' => 'article_sidebar_top',
        ]);

        $response = $this->get('/api/ads/click/'.$creative->id.'?'.$query);

        $response->assertNotFound();
        $this->assertNotSame('https://advertiser.example.test/landing', $response->headers->get('Location'));
        $this->assertDatabaseCount(AdClick::class, 0);
    }

    public function test_active_fallback_creative_tracks_when_campaign_is_paused_and_expired(): void
    {
        $zone = AdZone::factory()->create(['key' => 'article_after_paragraph_3']);
        $creative = $this->createCreative(AdCampaign::factory()->create([
            'status' => 'paused',
            'start_at' => now()->subDays(10),
            'end_at' => now()->subDay(),
        ]), $zone, [
            'is_fallback' => true,
            'is_active' => true,
            'click_url' => 'https://advertiser.example.test/fallback',
        ]);
        $token = $this->deliveryToken($creative, $zone, ['device' => 'mobile']);

        $this->postJson('/api/ads/impression', [
            'delivery_token' => $token,
            'creative_id' => $creative->id,
            'zone_key' => 'article_after_paragraph_3',
            'device' => 'mobile',
        ])->assertCreated()
            ->assertJsonPath('data.recorded', true);

        $this->postJson('/api/ads/click/'.$creative->id, [
            'delivery_token' => $token,
            'zone_key' => 'article_after_paragraph_3',
            'device' => 'mobile',
        ])->assertCreated()
            ->assertJsonPath('data.recorded', true)
            ->assertJsonPath('data.target_url', 'https://advertiser.example.test/fallback');

        $this->get('/api/ads/click/'.$creative->id.'?'.http_build_query([
            'delivery_token' => $token,
            'zone_key' => 'article_after_paragraph_3',
            'device' => 'mobile',
        ]))
            ->assertRedirect('https://advertiser.example.test/fallback');

        $this->assertDatabaseHas('ad_impressions', [
            'campaign_id' => $creative->campaign_id,
            'creative_id' => $creative->id,
            'zone_id' => $zone->id,
            'device' => 'mobile',
        ]);
        $this->assertDatabaseCount(AdClick::class, 1);
    }

    public function test_inactive_fallback_creative_is_rejected_without_tracking(): void
    {
        $zone = AdZone::factory()->create(['key' => 'article_after_paragraph_7']);
        $creative = $this->createCreative(AdCampaign::factory()->create([
            'status' => 'paused',
            'start_at' => now()->subDays(10),
            'end_at' => now()->subDay(),
        ]), $zone, [
            'is_fallback' => true,
            'is_active' => false,
            'click_url' => 'https://advertiser.example.test/inactive-fallback',
        ]);
        $token = $this->deliveryToken($creative, $zone);

        $this->postJson('/api/ads/impression', [
            'delivery_token' => $token,
            'creative_id' => $creative->id,
            'zone_key' => 'article_after_paragraph_7',
        ])->assertNotFound();

        $response = $this->get('/api/ads/click/'.$creative->id.'?'.http_build_query([
            'delivery_token' => $token,
            'zone_key' => 'article_after_paragraph_7',
        ]));

        $response->assertNotFound();
        $this->assertNotSame('https://advertiser.example.test/inactive-fallback', $response->headers->get('Location'));
        $this->assertDatabaseCount(AdImpression::class, 0);
        $this->assertDatabaseCount(AdClick::class, 0);
    }

    private function activeCampaign(): AdCampaign
    {
        return AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
    }

    private function createCreative(AdCampaign $campaign, AdZone $zone, array $overrides = []): AdCreative
    {
        return AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Tracking test creative',
            'type' => 'html',
            'html' => '<a href="https://example.test">Ad</a>',
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /**
     * @param  array{device?: string|null, category?: string|null, category_id?: int|null, article_id?: int|null}  $context
     */
    private function deliveryToken(AdCreative $creative, AdZone $zone, array $context = []): string
    {
        return app(AdDeliveryTokenService::class)->issue($creative, $zone, $context)['token'];
    }
}
