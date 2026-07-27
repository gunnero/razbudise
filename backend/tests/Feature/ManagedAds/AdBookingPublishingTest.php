<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdCustomer;
use App\Models\AdInvoice;
use App\Models\Advertiser;
use App\Models\AdZone;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Ads\AdBookingPublishingService;
use App\Services\Finance\FinanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdBookingPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_approved_booking_publishes_campaigns_and_creatives(): void
    {
        $advertiser = Advertiser::factory()->create();
        $customer = AdCustomer::factory()->for($advertiser, 'advertiser')->create();
        $zone = AdZone::factory()->create(['allowed_sizes' => [[300, 250]]]);
        $image = MediaAsset::factory()->create(['width' => 300, 'height' => 250]);
        $bookingRequest = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'approved',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->for($image, 'image')
            ->create([
                'status' => 'approved',
                'creative_type' => 'image',
                'width' => 300,
                'height' => 250,
                'click_url' => 'https://example.test/click',
            ]);

        app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());

        $this->assertDatabaseHas('ad_campaigns', [
            'advertiser_id' => $advertiser->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('ad_creatives', [
            'zone_id' => $zone->id,
            'type' => 'image',
            'is_active' => true,
        ]);
        $lineItem->refresh();

        $this->assertSame('published', $bookingRequest->refresh()->status);
        $this->assertNotNull($lineItem->ad_campaign_id);
        $this->assertNotNull($lineItem->ad_creative_id);
        $this->assertStringContainsString(
            'managed_booking_request:'.$bookingRequest->id,
            $lineItem->campaign->notes,
        );
        $this->assertStringContainsString(
            'managed_booking_line_item:'.$lineItem->id,
            $lineItem->campaign->notes,
        );
        $this->assertStringContainsString(
            'managed_booking_request:'.$bookingRequest->id,
            $lineItem->creative->name,
        );
        $this->assertStringContainsString(
            'managed_booking_line_item:'.$lineItem->id,
            $lineItem->creative->name,
        );
    }

    public function test_published_line_item_can_be_republished_idempotently(): void
    {
        $customer = AdCustomer::factory()->create();
        $zone = AdZone::factory()->create(['allowed_sizes' => [[300, 250]]]);
        $image = MediaAsset::factory()->create(['width' => 300, 'height' => 250]);
        $bookingRequest = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'approved',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->for($image, 'image')
            ->create([
                'status' => 'approved',
                'creative_type' => 'image',
                'width' => 300,
                'height' => 250,
                'click_url' => 'https://example.test/first-click',
            ]);

        $service = app(AdBookingPublishingService::class);
        $service->publish($bookingRequest, User::factory()->create());

        $lineItem->refresh();
        $campaignId = $lineItem->ad_campaign_id;
        $creativeId = $lineItem->ad_creative_id;

        $lineItem->forceFill([
            'click_url' => 'https://example.test/updated-click',
        ])->save();

        $service->publish($bookingRequest->refresh(), User::factory()->create());

        $this->assertDatabaseCount('ad_campaigns', 1);
        $this->assertDatabaseCount('ad_creatives', 1);
        $this->assertSame($campaignId, $lineItem->refresh()->ad_campaign_id);
        $this->assertSame($creativeId, $lineItem->ad_creative_id);
        $this->assertDatabaseHas('ad_creatives', [
            'id' => $creativeId,
            'click_url' => 'https://example.test/updated-click',
        ]);
    }

    public function test_mark_paid_updates_payment_fields_and_admin_note(): void
    {
        $bookingRequest = AdBookingRequest::factory()->create([
            'payment_status' => 'unpaid',
            'payment_method' => null,
            'payment_reference' => null,
            'admin_notes' => 'Initial note',
        ]);
        $admin = User::factory()->create();
        $paidAt = now()->subHour();

        app(AdBookingPublishingService::class)->markPaid($bookingRequest, [
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'PAY-123',
            'paid_at' => $paidAt,
            'metadata' => ['bank' => 'test'],
        ], $admin);

        $bookingRequest->refresh();

        $this->assertSame('paid', $bookingRequest->payment_status);
        $this->assertSame('bank_transfer', $bookingRequest->payment_method);
        $this->assertSame('PAY-123', $bookingRequest->payment_reference);
        $this->assertSame($paidAt->timestamp, $bookingRequest->paid_at->timestamp);
        $this->assertSame(['bank' => 'test'], $bookingRequest->payment_metadata);
        $this->assertStringContainsString('Initial note', $bookingRequest->admin_notes);
        $this->assertStringContainsString('Marked paid by admin #'.$admin->id, $bookingRequest->admin_notes);
    }

    public function test_mark_paid_updates_related_ad_invoice_for_finance_reporting(): void
    {
        $bookingRequest = AdBookingRequest::factory()->create([
            'payment_status' => 'unpaid',
            'payment_method' => null,
            'payment_reference' => null,
            'paid_at' => null,
        ]);
        $invoice = AdInvoice::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->create([
                'status' => 'issued',
                'paid_at' => null,
                'total_denars' => 15000,
            ]);
        $paidAt = now()->setDate(2026, 7, 12)->setTime(10, 30);

        app(AdBookingPublishingService::class)->markPaid($bookingRequest, [
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'BANK-2026-42',
            'paid_at' => $paidAt,
        ], User::factory()->create());

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertSame($paidAt->timestamp, $invoice->paid_at->timestamp);
        $this->assertSame(15000, app(FinanceReportService::class)->monthlySummary('2026-07-01', '2026-07-31')['paid_income_denars']);
    }

    public function test_terminal_booking_cannot_be_marked_paid_directly(): void
    {
        $bookingRequest = AdBookingRequest::factory()->create([
            'status' => 'rejected',
            'payment_status' => 'unpaid',
            'payment_method' => null,
            'payment_reference' => null,
            'paid_at' => null,
        ]);

        try {
            app(AdBookingPublishingService::class)->markPaid($bookingRequest, [
                'payment_method' => 'bank_transfer',
                'payment_reference' => 'PAY-REJECTED',
            ], User::factory()->create());
            $this->fail('Expected validation exception for terminal booking payment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $bookingRequest->refresh();

        $this->assertSame('unpaid', $bookingRequest->payment_status);
        $this->assertNull($bookingRequest->payment_method);
        $this->assertNull($bookingRequest->payment_reference);
        $this->assertNull($bookingRequest->paid_at);
    }

    public function test_refunded_booking_cannot_be_marked_paid_directly(): void
    {
        $paidAt = now()->subMonth();
        $bookingRequest = AdBookingRequest::factory()->create([
            'status' => 'approved',
            'payment_status' => 'refunded',
            'payment_method' => 'manual_bank_transfer',
            'payment_reference' => 'REFUNDED-ORIGINAL',
            'paid_at' => $paidAt,
        ]);

        try {
            app(AdBookingPublishingService::class)->markPaid($bookingRequest, [
                'payment_method' => 'manual_bank_transfer',
                'payment_reference' => 'PAY-AGAIN',
            ], User::factory()->create());
            $this->fail('Expected validation exception for refunded booking payment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_status', $exception->errors());
        }

        $bookingRequest->refresh();

        $this->assertSame('refunded', $bookingRequest->payment_status);
        $this->assertSame('manual_bank_transfer', $bookingRequest->payment_method);
        $this->assertSame('REFUNDED-ORIGINAL', $bookingRequest->payment_reference);
        $this->assertSame($paidAt->timestamp, $bookingRequest->paid_at->timestamp);
    }

    public function test_reject_booking_deactivates_linked_live_ads_and_preserves_admin_notes(): void
    {
        $bookingRequest = AdBookingRequest::factory()->create([
            'status' => 'published',
            'admin_notes' => 'Existing internal note',
        ]);
        $zone = AdZone::factory()->create();
        $campaign = AdCampaign::factory()->create(['status' => 'active']);
        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Live managed creative',
            'type' => 'html',
            'html' => '<a href="https://example.test">Ad</a>',
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'published',
            ]);
        $campaign->forceFill(['notes' => $this->managedCampaignNotes($bookingRequest, $lineItem)])->save();
        $creative->forceFill(['name' => $this->managedCreativeName($bookingRequest, $lineItem)])->save();
        $lineItem->forceFill([
            'ad_campaign_id' => $campaign->id,
            'ad_creative_id' => $creative->id,
        ])->save();

        app(AdBookingPublishingService::class)->rejectBooking(
            $bookingRequest,
            'Creative does not match the booked placement.',
            User::factory()->create(),
        );

        $this->assertSame('rejected', $bookingRequest->refresh()->status);
        $this->assertSame('Creative does not match the booked placement.', $bookingRequest->rejection_reason);
        $this->assertStringContainsString('Existing internal note', $bookingRequest->admin_notes);
        $this->assertStringContainsString('Rejection reason: Creative does not match the booked placement.', $bookingRequest->admin_notes);
        $this->assertSame('rejected', $lineItem->refresh()->status);
        $this->assertSame('paused', $campaign->refresh()->status);
        $this->assertFalse($creative->refresh()->is_active);
    }

    public function test_reject_line_item_deactivates_linked_live_ads_and_clears_technical_review(): void
    {
        $zone = AdZone::factory()->create();
        $campaign = AdCampaign::factory()->create(['status' => 'active']);
        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Published creative',
            'type' => 'html',
            'html' => '<a href="https://example.test">Ad</a>',
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($zone, 'zone')
            ->create([
                'status' => 'published',
                'technical_review_required' => true,
            ]);
        $bookingRequest = $lineItem->bookingRequest;
        $campaign->forceFill(['notes' => $this->managedCampaignNotes($bookingRequest, $lineItem)])->save();
        $creative->forceFill(['name' => $this->managedCreativeName($bookingRequest, $lineItem)])->save();
        $lineItem->forceFill([
            'ad_campaign_id' => $campaign->id,
            'ad_creative_id' => $creative->id,
        ])->save();

        app(AdBookingPublishingService::class)->rejectLineItem(
            $lineItem,
            'Landing page URL is not allowed.',
            User::factory()->create(),
        );

        $this->assertSame('rejected', $lineItem->refresh()->status);
        $this->assertSame('Landing page URL is not allowed.', $lineItem->rejection_reason);
        $this->assertFalse($lineItem->technical_review_required);
        $this->assertSame('paused', $campaign->refresh()->status);
        $this->assertFalse($creative->refresh()->is_active);
    }

    public function test_terminal_booking_cannot_be_rejected_directly(): void
    {
        $bookingRequest = AdBookingRequest::factory()->create([
            'status' => 'rejected',
            'admin_notes' => 'Already rejected',
        ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->create(['status' => 'approved']);

        try {
            app(AdBookingPublishingService::class)->rejectBooking(
                $bookingRequest,
                'Second rejection should fail.',
                User::factory()->create(),
            );
            $this->fail('Expected validation exception for terminal booking rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame('rejected', $bookingRequest->refresh()->status);
        $this->assertSame('Already rejected', $bookingRequest->admin_notes);
        $this->assertSame('approved', $lineItem->refresh()->status);
    }

    public function test_line_item_under_terminal_booking_cannot_be_rejected_directly(): void
    {
        $bookingRequest = AdBookingRequest::factory()->create(['status' => 'published']);
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->create(['status' => 'approved']);

        try {
            app(AdBookingPublishingService::class)->rejectLineItem(
                $lineItem,
                'Parent is terminal.',
                User::factory()->create(),
            );
            $this->fail('Expected validation exception for line item under terminal parent.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame('approved', $lineItem->refresh()->status);
        $this->assertNull($lineItem->rejection_reason);
    }

    public function test_reject_line_item_refuses_unmanaged_linked_ads(): void
    {
        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'notes' => 'Direct campaign created outside managed booking.',
        ]);
        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => AdZone::factory()->create()->id,
            'name' => 'Direct unmanaged creative',
            'type' => 'html',
            'html' => '<a href="https://example.test">Ad</a>',
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);
        $lineItem = AdBookingLineItem::factory()->create([
            'status' => 'approved',
            'ad_campaign_id' => $campaign->id,
            'ad_creative_id' => $creative->id,
        ]);

        try {
            app(AdBookingPublishingService::class)->rejectLineItem(
                $lineItem,
                'Should not touch unmanaged inventory.',
                User::factory()->create(),
            );
            $this->fail('Expected validation exception for unmanaged linked ads.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ad_campaign_id', $exception->errors());
        }

        $this->assertSame('approved', $lineItem->refresh()->status);
        $this->assertNull($lineItem->rejection_reason);
        $this->assertSame('active', $campaign->refresh()->status);
        $this->assertTrue($creative->refresh()->is_active);
    }

    public function test_reject_line_item_refuses_another_managed_line_items_ads(): void
    {
        $zone = AdZone::factory()->create();
        $otherBookingRequest = AdBookingRequest::factory()->create();
        $otherLineItem = AdBookingLineItem::factory()
            ->for($otherBookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create(['status' => 'published']);
        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'notes' => $this->managedCampaignNotes($otherBookingRequest, $otherLineItem),
        ]);
        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => $this->managedCreativeName($otherBookingRequest, $otherLineItem),
            'type' => 'html',
            'html' => '<a href="https://example.test">Ad</a>',
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'ad_campaign_id' => $campaign->id,
                'ad_creative_id' => $creative->id,
            ]);

        try {
            app(AdBookingPublishingService::class)->rejectLineItem(
                $lineItem,
                'Should not touch another managed line item.',
                User::factory()->create(),
            );
            $this->fail('Expected validation exception for foreign managed linked ads.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ad_campaign_id', $exception->errors());
        }

        $this->assertSame('approved', $lineItem->refresh()->status);
        $this->assertSame('active', $campaign->refresh()->status);
        $this->assertTrue($creative->refresh()->is_active);
    }

    public function test_reject_line_item_refuses_unmanaged_linked_creative(): void
    {
        $zone = AdZone::factory()->create();
        $bookingRequest = AdBookingRequest::factory()->create();
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create(['status' => 'approved']);
        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'notes' => $this->managedCampaignNotes($bookingRequest, $lineItem),
        ]);
        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Unmanaged creative without booking markers',
            'type' => 'html',
            'html' => '<a href="https://example.test">Ad</a>',
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);
        $lineItem->forceFill([
            'ad_campaign_id' => $campaign->id,
            'ad_creative_id' => $creative->id,
        ])->save();

        try {
            app(AdBookingPublishingService::class)->rejectLineItem(
                $lineItem,
                'Should not touch unmanaged creative.',
                User::factory()->create(),
            );
            $this->fail('Expected validation exception for unmanaged linked creative.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ad_creative_id', $exception->errors());
        }

        $this->assertSame('approved', $lineItem->refresh()->status);
        $this->assertSame('active', $campaign->refresh()->status);
        $this->assertTrue($creative->refresh()->is_active);
    }

    public function test_reject_line_item_refuses_another_managed_line_items_creative(): void
    {
        $zone = AdZone::factory()->create();
        $bookingRequest = AdBookingRequest::factory()->create();
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create(['status' => 'approved']);
        $otherBookingRequest = AdBookingRequest::factory()->create();
        $otherLineItem = AdBookingLineItem::factory()
            ->for($otherBookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create(['status' => 'published']);
        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'notes' => $this->managedCampaignNotes($bookingRequest, $lineItem),
        ]);
        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => $this->managedCreativeName($otherBookingRequest, $otherLineItem),
            'type' => 'html',
            'html' => '<a href="https://example.test">Ad</a>',
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);
        $lineItem->forceFill([
            'ad_campaign_id' => $campaign->id,
            'ad_creative_id' => $creative->id,
        ])->save();

        try {
            app(AdBookingPublishingService::class)->rejectLineItem(
                $lineItem,
                'Should not touch another managed creative.',
                User::factory()->create(),
            );
            $this->fail('Expected validation exception for foreign managed linked creative.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ad_creative_id', $exception->errors());
        }

        $this->assertSame('approved', $lineItem->refresh()->status);
        $this->assertSame('active', $campaign->refresh()->status);
        $this->assertTrue($creative->refresh()->is_active);
    }

    public function test_paid_line_item_without_publishable_approval_fails_without_creating_ads(): void
    {
        $bookingRequest = AdBookingRequest::factory()->create([
            'status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->create(['status' => 'paid']);

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception when no line items are publishable.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ad_campaigns', 0);
            $this->assertDatabaseCount('ad_creatives', 0);
        }

        $this->assertDatabaseCount('ad_campaigns', 0);
        $this->assertDatabaseCount('ad_creatives', 0);
        $this->assertSame('paid', $lineItem->refresh()->status);
        $this->assertNull($lineItem->ad_campaign_id);
        $this->assertNull($lineItem->ad_creative_id);
    }

    public function test_unpaid_booking_cannot_publish_campaigns(): void
    {
        $bookingRequest = AdBookingRequest::factory()->create([
            'status' => 'approved',
            'payment_status' => 'unpaid',
        ]);
        AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->create(['status' => 'approved']);

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception for unpaid booking.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ad_campaigns', 0);
        }

    }

    public function test_rejected_line_item_fails_publish_without_creating_ads(): void
    {
        $customer = AdCustomer::factory()->create();
        $bookingRequest = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'approved',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->create(['status' => 'rejected']);

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception when all line items are rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ad_campaigns', 0);
            $this->assertDatabaseCount('ad_creatives', 0);
        }

        $this->assertDatabaseCount('ad_campaigns', 0);
        $this->assertDatabaseCount('ad_creatives', 0);
        $this->assertNull($lineItem->refresh()->ad_campaign_id);
        $this->assertNull($lineItem->ad_creative_id);
    }

    public function test_rejected_booking_cannot_be_published_directly(): void
    {
        $bookingRequest = AdBookingRequest::factory()->create([
            'status' => 'rejected',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);
        AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->create([
                'status' => 'approved',
                'creative_type' => 'html',
                'html' => '<a href="https://example.test">Ad</a>',
            ]);

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception for rejected booking.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ad_campaigns', 0);
            $this->assertDatabaseCount('ad_creatives', 0);
        }
    }

    public function test_mismatched_existing_campaign_id_is_not_overwritten(): void
    {
        $customer = AdCustomer::factory()->create();
        $zone = AdZone::factory()->create();
        $bookingRequest = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'approved',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);
        $unrelatedCampaign = AdCampaign::factory()->create([
            'advertiser_id' => Advertiser::factory()->create()->id,
            'name' => 'Unrelated campaign',
            'status' => 'draft',
        ]);
        AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'ad_campaign_id' => $unrelatedCampaign->id,
            ]);

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception for mismatched campaign.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('ad_campaigns', [
                'id' => $unrelatedCampaign->id,
                'name' => 'Unrelated campaign',
                'status' => 'draft',
            ]);
        }
    }

    public function test_same_advertiser_unmanaged_existing_campaign_id_is_not_overwritten(): void
    {
        $advertiser = Advertiser::factory()->create();
        $customer = AdCustomer::factory()->for($advertiser, 'advertiser')->create();
        $zone = AdZone::factory()->create();
        $bookingRequest = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'approved',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);
        $unmanagedCampaign = AdCampaign::factory()->create([
            'advertiser_id' => $advertiser->id,
            'name' => 'Same advertiser unmanaged campaign',
            'status' => 'draft',
            'notes' => 'Direct ad created outside managed booking.',
        ]);
        AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'ad_campaign_id' => $unmanagedCampaign->id,
            ]);

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception for unmanaged campaign.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('ad_campaigns', [
                'id' => $unmanagedCampaign->id,
                'name' => 'Same advertiser unmanaged campaign',
                'status' => 'draft',
                'notes' => 'Direct ad created outside managed booking.',
            ]);
        }
    }

    public function test_same_advertiser_managed_campaign_for_different_line_item_is_not_overwritten(): void
    {
        $advertiser = Advertiser::factory()->create();
        $customer = AdCustomer::factory()->for($advertiser, 'advertiser')->create();
        $zone = AdZone::factory()->create();
        $bookingRequest = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'approved',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);
        $managedCampaign = AdCampaign::factory()->create([
            'advertiser_id' => $advertiser->id,
            'name' => 'Other managed campaign',
            'status' => 'active',
            'notes' => "Published from managed booking by admin #1\nmanaged_booking_request:999\nmanaged_booking_line_item:888",
        ]);
        AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'ad_campaign_id' => $managedCampaign->id,
            ]);

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception for foreign managed campaign marker.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('ad_campaigns', [
                'id' => $managedCampaign->id,
                'name' => 'Other managed campaign',
                'notes' => "Published from managed booking by admin #1\nmanaged_booking_request:999\nmanaged_booking_line_item:888",
            ]);
        }
    }

    public function test_prefix_collision_campaign_marker_is_not_treated_as_current_line_ownership(): void
    {
        $advertiser = Advertiser::factory()->create();
        $customer = AdCustomer::factory()->for($advertiser, 'advertiser')->create();
        $zone = AdZone::factory()->create();
        $bookingRequest = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'approved',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create(['status' => 'approved']);
        $collidingNotes = implode("\n", [
            'Published from managed booking by admin #1',
            'managed_booking_request:'.$bookingRequest->id.'0',
            'managed_booking_line_item:'.$lineItem->id.'0',
        ]);
        $managedCampaign = AdCampaign::factory()->create([
            'advertiser_id' => $advertiser->id,
            'name' => 'Prefix collision campaign',
            'status' => 'active',
            'notes' => $collidingNotes,
        ]);

        $lineItem->forceFill(['ad_campaign_id' => $managedCampaign->id])->save();

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception for prefix-collision campaign marker.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('ad_campaigns', [
                'id' => $managedCampaign->id,
                'name' => 'Prefix collision campaign',
                'notes' => $collidingNotes,
            ]);
            $this->assertNull($lineItem->refresh()->ad_creative_id);
        }
    }

    public function test_mismatched_existing_creative_id_is_not_overwritten(): void
    {
        $customer = AdCustomer::factory()->create();
        $zone = AdZone::factory()->create();
        $otherZone = AdZone::factory()->create();
        $bookingRequest = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'approved',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);
        $unrelatedCampaign = AdCampaign::factory()->create();
        $unrelatedCreative = AdCreative::query()->create([
            'campaign_id' => $unrelatedCampaign->id,
            'zone_id' => $otherZone->id,
            'name' => 'Unrelated creative',
            'type' => 'image',
            'click_url' => 'https://unrelated.example.test',
            'width' => 728,
            'height' => 90,
            'is_fallback' => false,
            'is_active' => false,
        ]);
        AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'ad_creative_id' => $unrelatedCreative->id,
            ]);

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception for mismatched creative.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('ad_creatives', [
                'id' => $unrelatedCreative->id,
                'campaign_id' => $unrelatedCampaign->id,
                'zone_id' => $otherZone->id,
                'name' => 'Unrelated creative',
                'is_active' => false,
            ]);
        }
    }

    public function test_same_zone_managed_creative_for_different_line_item_is_not_overwritten(): void
    {
        $advertiser = Advertiser::factory()->create();
        $customer = AdCustomer::factory()->for($advertiser, 'advertiser')->create();
        $zone = AdZone::factory()->create();
        $bookingRequest = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'approved',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
            ]);
        $campaign = AdCampaign::factory()->create([
            'advertiser_id' => $advertiser->id,
            'notes' => "Published from managed booking by admin #1\nmanaged_booking_request:{$bookingRequest->id}\nmanaged_booking_line_item:{$lineItem->id}",
        ]);
        $foreignCreative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Other managed creative managed_booking_request:999 managed_booking_line_item:888',
            'type' => 'image',
            'click_url' => 'https://foreign.example.test',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);

        $lineItem->forceFill([
            'ad_campaign_id' => $campaign->id,
            'ad_creative_id' => $foreignCreative->id,
        ])->save();

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception for foreign managed creative marker.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('ad_creatives', [
                'id' => $foreignCreative->id,
                'name' => 'Other managed creative managed_booking_request:999 managed_booking_line_item:888',
                'click_url' => 'https://foreign.example.test',
            ]);
        }
    }

    public function test_prefix_collision_creative_marker_is_not_treated_as_current_line_ownership(): void
    {
        $advertiser = Advertiser::factory()->create();
        $customer = AdCustomer::factory()->for($advertiser, 'advertiser')->create();
        $zone = AdZone::factory()->create();
        $bookingRequest = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'approved',
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'click_url' => 'https://current.example.test',
            ]);
        $campaign = AdCampaign::factory()->create([
            'advertiser_id' => $advertiser->id,
            'notes' => implode("\n", [
                'Published from managed booking by admin #1',
                'managed_booking_request:'.$bookingRequest->id,
                'managed_booking_line_item:'.$lineItem->id,
            ]),
        ]);
        $collidingName = 'Prefix creative managed_booking_request:'
            .$bookingRequest->id
            .'0 managed_booking_line_item:'
            .$lineItem->id
            .'0';
        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => $collidingName,
            'type' => 'image',
            'click_url' => 'https://foreign.example.test',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);

        $lineItem->forceFill([
            'ad_campaign_id' => $campaign->id,
            'ad_creative_id' => $creative->id,
        ])->save();

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception for prefix-collision creative marker.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('ad_creatives', [
                'id' => $creative->id,
                'name' => $collidingName,
                'click_url' => 'https://foreign.example.test',
            ]);
        }
    }

    public function test_existing_customer_tax_number_does_not_reuse_existing_advertiser_when_request_has_no_customer_id(): void
    {
        $advertiser = Advertiser::factory()->create(['name' => 'Existing Advertiser']);
        $customer = AdCustomer::factory()
            ->for($advertiser, 'advertiser')
            ->create([
                'company_name' => 'Existing Customer Company',
                'tax_number' => 'MK4030123456789',
            ]);
        $zone = AdZone::factory()->create(['allowed_sizes' => [[300, 250]]]);
        $image = MediaAsset::factory()->create(['width' => 300, 'height' => 250]);
        $bookingRequest = AdBookingRequest::factory()->create([
            'ad_customer_id' => null,
            'billing_company_name' => 'Existing Customer Company Updated',
            'billing_tax_number' => 'MK4030123456789',
            'status' => 'approved',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);
        AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->for($image, 'image')
            ->create([
                'status' => 'approved',
                'creative_type' => 'image',
                'width' => 300,
                'height' => 250,
            ]);

        app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());

        $this->assertDatabaseCount('advertisers', 2);
        $this->assertNotSame($customer->id, $bookingRequest->refresh()->ad_customer_id);
        $this->assertSame($advertiser->id, $customer->refresh()->advertiser_id);
        $this->assertSame('Existing Customer Company', $customer->company_name);
        $this->assertDatabaseHas('ad_campaigns', [
            'advertiser_id' => $bookingRequest->customer->advertiser_id,
            'status' => 'active',
        ]);
    }

    public function test_invalid_image_dimensions_prevent_publish_without_creating_campaign_or_creative(): void
    {
        $zone = AdZone::factory()->create(['allowed_sizes' => [[728, 90]]]);
        $image = MediaAsset::factory()->create(['width' => 300, 'height' => 250]);
        $bookingRequest = AdBookingRequest::factory()->create([
            'status' => 'approved',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);
        AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->for($image, 'image')
            ->create([
                'status' => 'approved',
                'creative_type' => 'image',
                'width' => 300,
                'height' => 250,
            ]);

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception for invalid image dimensions.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ad_campaigns', 0);
            $this->assertDatabaseCount('ad_creatives', 0);
        }
    }

    public function test_missing_image_prevents_image_creative_publish_without_creating_campaign_or_creative(): void
    {
        $zone = AdZone::factory()->create(['allowed_sizes' => [[300, 250]]]);
        $bookingRequest = AdBookingRequest::factory()->create([
            'status' => 'approved',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);
        AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'creative_type' => 'image',
                'image_id' => null,
                'width' => 300,
                'height' => 250,
            ]);

        try {
            app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());
            $this->fail('Expected validation exception for missing image asset.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ad_campaigns', 0);
            $this->assertDatabaseCount('ad_creatives', 0);
        }
    }

    public function test_published_image_creative_dimensions_are_copied_from_validated_media_asset(): void
    {
        $zone = AdZone::factory()->create(['allowed_sizes' => [[728, 90]]]);
        $image = MediaAsset::factory()->create(['width' => 728, 'height' => 90]);
        $bookingRequest = AdBookingRequest::factory()->create([
            'status' => 'approved',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);
        AdBookingLineItem::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->for($zone, 'zone')
            ->for($image, 'image')
            ->create([
                'status' => 'approved',
                'creative_type' => 'image',
                'width' => 300,
                'height' => 250,
            ]);

        app(AdBookingPublishingService::class)->publish($bookingRequest, User::factory()->create());

        $this->assertDatabaseHas('ad_creatives', [
            'image_id' => $image->id,
            'width' => 728,
            'height' => 90,
            'is_active' => true,
        ]);
    }

    private function managedCampaignNotes(AdBookingRequest $bookingRequest, AdBookingLineItem $lineItem): string
    {
        return implode("\n", [
            'Published from managed booking by admin #1',
            'managed_booking_request:'.$bookingRequest->id,
            'managed_booking_line_item:'.$lineItem->id,
        ]);
    }

    private function managedCreativeName(AdBookingRequest $bookingRequest, AdBookingLineItem $lineItem): string
    {
        return 'Managed creative managed_booking_request:'
            .$bookingRequest->id
            .' managed_booking_line_item:'
            .$lineItem->id;
    }
}
