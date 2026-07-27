<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdClick;
use App\Models\AdCreative;
use App\Models\AdImpression;
use App\Models\AdZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdDeliverySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_tracking_requests_are_rejected_and_tracking_routes_use_named_throttles(): void
    {
        $zone = AdZone::factory()->create(['key' => 'article_after_title']);
        $creative = $this->createCreative($this->activeCampaign(), $zone);

        $this->postJson('/api/ads/impression', [
            'creative_id' => $creative->id,
            'zone_key' => $zone->key,
        ])->assertUnprocessable();

        $response = $this->get('/api/ads/click/'.$creative->id.'?zone_key='.$zone->key);

        $response->assertUnprocessable();
        $this->assertNull($response->headers->get('Location'));
        $this->assertDatabaseCount(AdImpression::class, 0);
        $this->assertDatabaseCount(AdClick::class, 0);
        $this->assertContains('throttle:ad-impressions', Route::getRoutes()->getByName('api.ads.impression')->gatherMiddleware());
        $this->assertContains('throttle:ad-clicks', Route::getRoutes()->getByName('api.ads.click')->gatherMiddleware());
        $this->assertContains('throttle:ad-clicks', Route::getRoutes()->getByName('api.ads.redirect')->gatherMiddleware());
    }

    public function test_placement_limiter_keeps_loopback_ssr_capacity_high_while_bounding_external_clients(): void
    {
        $limiter = RateLimiter::limiter('ad-placements');

        $this->assertNotNull($limiter);

        $externalLimits = $limiter(Request::create(
            '/api/ads/article_after_title',
            'GET',
            server: ['REMOTE_ADDR' => '203.0.113.20'],
        ));
        $loopbackLimits = $limiter(Request::create(
            '/api/ads/article_after_title',
            'GET',
            server: ['REMOTE_ADDR' => '127.0.0.1'],
        ));

        $this->assertSame([20, 300], collect($externalLimits)->pluck('maxAttempts')->all());
        $this->assertSame([250, 12000], collect($loopbackLimits)->pluck('maxAttempts')->all());
        $this->assertContains('throttle:ad-placements', Route::getRoutes()->getByName('api.ads.placement')->gatherMiddleware());
    }

    public function test_one_delivery_counts_at_most_one_impression_and_one_click_while_click_replays_still_redirect(): void
    {
        $zone = AdZone::factory()->create(['key' => 'article_after_title']);
        $creative = $this->createCreative($this->activeCampaign(), $zone, [
            'click_url' => 'https://advertiser.example.test/landing',
        ]);

        $placement = $this->getJson('/api/ads/article_after_title?device=mobile')
            ->assertOk()
            ->assertJsonPath('data.id', $creative->id)
            ->json('data');

        $impression = [
            'delivery_token' => $placement['delivery_token'],
            'creative_id' => $creative->id,
            'zone_key' => $zone->key,
            'device' => 'mobile',
        ];

        $this->postJson('/api/ads/impression', $impression)
            ->assertCreated()
            ->assertJsonPath('data.recorded', true);

        $this->postJson('/api/ads/impression', $impression)
            ->assertOk()
            ->assertJsonPath('data.recorded', false)
            ->assertJsonPath('data.duplicate', true);

        $clickPath = $this->relativeUrl($placement['click_tracking_url']);

        $this->get($clickPath)->assertRedirect('https://advertiser.example.test/landing');
        $this->get($clickPath)->assertRedirect('https://advertiser.example.test/landing');

        $this->assertDatabaseCount(AdImpression::class, 1);
        $this->assertDatabaseCount(AdClick::class, 1);
        $this->assertNotNull(AdImpression::query()->value('delivery_id'));
        $this->assertNotNull(AdClick::query()->value('delivery_id'));
    }

    public function test_delivery_token_rejects_wrong_creative_zone_and_device_without_counting(): void
    {
        $servedZone = AdZone::factory()->create(['key' => 'article_sidebar_top']);
        $otherZone = AdZone::factory()->create(['key' => 'category_top']);
        $servedCreative = $this->createCreative($this->activeCampaign(), $servedZone);
        $otherCreative = $this->createCreative($this->activeCampaign(), $otherZone);

        $token = $this->getJson('/api/ads/article_sidebar_top?device=desktop')
            ->assertOk()
            ->assertJsonPath('data.id', $servedCreative->id)
            ->json('data.delivery_token');

        $basePayload = [
            'delivery_token' => $token,
            'creative_id' => $servedCreative->id,
            'zone_key' => $servedZone->key,
            'device' => 'desktop',
        ];

        $this->postJson('/api/ads/impression', [
            ...$basePayload,
            'creative_id' => $otherCreative->id,
        ])->assertForbidden();

        $this->postJson('/api/ads/impression', [
            ...$basePayload,
            'zone_key' => $otherZone->key,
        ])->assertForbidden();

        $this->postJson('/api/ads/impression', [
            ...$basePayload,
            'device' => 'mobile',
        ])->assertForbidden();

        $wrongCreativeClick = '/api/ads/click/'.$otherCreative->id.'?'.http_build_query([
            'delivery_token' => $token,
            'zone_key' => $servedZone->key,
            'device' => 'desktop',
        ]);

        $this->get($wrongCreativeClick)->assertForbidden();

        $this->assertDatabaseCount(AdImpression::class, 0);
        $this->assertDatabaseCount(AdClick::class, 0);
    }

    public function test_fresh_tokens_for_the_same_client_and_slot_are_deduplicated_within_the_event_window(): void
    {
        $zone = AdZone::factory()->create(['key' => 'home_after_featured']);
        $creative = $this->createCreative($this->activeCampaign(), $zone);
        $firstPlacement = $this->getJson('/api/ads/home_after_featured?device=desktop')->assertOk()->json('data');
        $secondPlacement = $this->getJson('/api/ads/home_after_featured?device=desktop')->assertOk()->json('data');

        $this->assertNotSame($firstPlacement['delivery_token'], $secondPlacement['delivery_token']);

        foreach ([$firstPlacement, $secondPlacement] as $index => $placement) {
            $response = $this->postJson('/api/ads/impression', [
                'delivery_token' => $placement['delivery_token'],
                'creative_id' => $creative->id,
                'zone_key' => $zone->key,
                'device' => 'desktop',
            ]);

            $index === 0
                ? $response->assertCreated()->assertJsonPath('data.recorded', true)
                : $response->assertOk()->assertJsonPath('data.recorded', false);
        }

        $this->get($this->relativeUrl($firstPlacement['click_tracking_url']))
            ->assertRedirect('https://advertiser.example.test/default');
        $this->get($this->relativeUrl($secondPlacement['click_tracking_url']))
            ->assertRedirect('https://advertiser.example.test/default');

        $this->assertDatabaseCount(AdImpression::class, 1);
        $this->assertDatabaseCount(AdClick::class, 1);
    }

    public function test_tampered_and_expired_delivery_tokens_are_rejected_without_counting(): void
    {
        config(['ads.delivery_token_ttl_minutes' => 1]);

        $zone = AdZone::factory()->create(['key' => 'home_top_billboard']);
        $creative = $this->createCreative($this->activeCampaign(), $zone);

        $token = $this->getJson('/api/ads/home_top_billboard?device=desktop')
            ->assertOk()
            ->json('data.delivery_token');

        $tamperedToken = substr($token, 0, -1).(str_ends_with($token, 'A') ? 'B' : 'A');
        $payload = [
            'creative_id' => $creative->id,
            'zone_key' => $zone->key,
            'device' => 'desktop',
        ];

        $this->postJson('/api/ads/impression', [
            ...$payload,
            'delivery_token' => $tamperedToken,
        ])->assertForbidden();

        $this->travel(2)->minutes();

        $this->postJson('/api/ads/impression', [
            ...$payload,
            'delivery_token' => $token,
        ])->assertForbidden();

        $this->assertDatabaseCount(AdImpression::class, 0);
    }

    public function test_authentic_expired_click_redirect_does_not_record_but_post_click_stays_strict(): void
    {
        config(['ads.delivery_token_ttl_minutes' => 1]);

        $zone = AdZone::factory()->create(['key' => 'article_sidebar_sticky']);
        $creative = $this->createCreative($this->activeCampaign(), $zone, [
            'click_url' => 'https://advertiser.example.test/long-read',
        ]);
        $placement = $this->getJson('/api/ads/article_sidebar_sticky?device=desktop')
            ->assertOk()
            ->json('data');

        $this->travel(2)->minutes();

        $this->get($this->relativeUrl($placement['click_tracking_url']))
            ->assertRedirect('https://advertiser.example.test/long-read');

        $this->postJson('/api/ads/click/'.$creative->id, [
            'delivery_token' => $placement['delivery_token'],
            'zone_key' => $zone->key,
            'device' => 'desktop',
        ])->assertForbidden();

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
            'name' => 'Delivery security creative',
            'type' => 'html',
            'html' => '<strong>Ad</strong>',
            'click_url' => 'https://advertiser.example.test/default',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function relativeUrl(string $absoluteUrl): string
    {
        $path = parse_url($absoluteUrl, PHP_URL_PATH) ?: '/';
        $query = parse_url($absoluteUrl, PHP_URL_QUERY);

        return $query ? $path.'?'.$query : $path;
    }
}
