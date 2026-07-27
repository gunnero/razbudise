<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdCustomer;
use App\Models\AdZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdCreativeRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_creative_change_creates_pending_revision_without_replacing_live_creative(): void
    {
        [$user, $lineItem, $creative] = $this->customerLineItemWithCreative('client@example.test');

        $this->actingAs($user)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'html',
                'html' => '<a href="https://example.test/new">New creative</a>',
                'click_url' => 'https://example.test/new',
                'width' => 300,
                'height' => 250,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_review');

        $this->assertDatabaseHas('ad_creative_revisions', [
            'ad_booking_line_item_id' => $lineItem->id,
            'creative_type' => 'html',
            'html' => '<a href="https://example.test/new">New creative</a>',
            'click_url' => 'https://example.test/new',
            'status' => 'pending_review',
        ]);

        $creative->refresh();

        $this->assertSame('<a href="https://example.test/live">Live creative</a>', $creative->html);
        $this->assertSame('https://example.test/live', $creative->click_url);
        $this->assertSame($creative->id, $lineItem->refresh()->ad_creative_id);
    }

    public function test_html_revision_requires_technical_review(): void
    {
        [$user, $lineItem] = $this->customerLineItemWithCreative('client@example.test');

        $this->actingAs($user)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'html',
                'html' => '<a href="https://example.test/new">New creative</a>',
                'click_url' => 'https://example.test/new',
                'width' => 300,
                'height' => 250,
            ])
            ->assertCreated()
            ->assertJsonPath('data.technical_review_required', true);

        $this->assertDatabaseHas('ad_creative_revisions', [
            'ad_booking_line_item_id' => $lineItem->id,
            'technical_review_required' => true,
        ]);
    }

    public function test_customer_cannot_edit_another_customer_creative(): void
    {
        [, $lineItem] = $this->customerLineItemWithCreative('client@example.test');
        [$otherUser] = $this->customerLineItemWithCreative('other@example.test');

        $this->actingAs($otherUser)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'html',
                'html' => '<a href="https://example.test/new">New creative</a>',
                'click_url' => 'https://example.test/new',
                'width' => 300,
                'height' => 250,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('ad_creative_revisions', [
            'ad_booking_line_item_id' => $lineItem->id,
        ]);
    }

    private function customerLineItemWithCreative(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $customer = AdCustomer::factory()->create([
            'user_id' => $user->id,
            'contact_email' => $email,
            'status' => 'active',
        ]);
        $booking = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'paid',
                'payment_status' => 'paid',
            ]);
        $zone = AdZone::factory()->create([
            'key' => 'article_after_title_'.fake()->unique()->numberBetween(10, 999),
            'allowed_sizes' => [[300, 250]],
        ]);
        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subWeek(),
            'end_at' => now()->addMonth(),
        ]);
        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Live creative',
            'type' => 'html',
            'html' => '<a href="https://example.test/live">Live creative</a>',
            'click_url' => 'https://example.test/live',
            'width' => 300,
            'height' => 250,
            'is_active' => true,
        ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'ad_campaign_id' => $campaign->id,
                'ad_creative_id' => $creative->id,
                'creative_type' => 'html',
                'html' => $creative->html,
                'click_url' => $creative->click_url,
                'width' => 300,
                'height' => 250,
                'status' => 'approved',
            ]);

        return [$user, $lineItem->refresh(), $creative->refresh()];
    }
}
