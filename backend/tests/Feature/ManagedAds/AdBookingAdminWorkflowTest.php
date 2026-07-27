<?php

namespace Tests\Feature\ManagedAds;

use App\Filament\Resources\AdBookingRequests\AdBookingRequestResource;
use App\Filament\Resources\AdBookingRequests\Pages\EditAdBookingRequest;
use App\Filament\Resources\AdBookingRequests\Pages\ListAdBookingRequests;
use App\Filament\Resources\AdBookingRequests\RelationManagers\LineItemsRelationManager;
use App\Filament\Resources\AdDiscountCodes\AdDiscountCodeResource;
use App\Filament\Resources\AdInvoices\Pages\CreateAdInvoice;
use App\Filament\Resources\AdInvoices\Pages\EditAdInvoice;
use App\Filament\Resources\AdPrices\Pages\CreateAdPrice;
use App\Filament\Resources\AdPrices\Pages\EditAdPrice;
use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdDiscountCode;
use App\Models\AdInvoice;
use App\Models\AdPrice;
use App\Models\AdZone;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdBookingAdminWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_mark_booking_paid_and_publish_campaign(): void
    {
        $this->seed();
        $admin = $this->userWithRole('ad_manager');
        $this->actingAs($admin);
        $paidAt = now('Europe/Skopje')->subDay()->setSeconds(0)->setMicrosecond(0);

        $zone = AdZone::factory()->create([
            'key' => 'admin_workflow_billboard',
            'name' => 'Admin workflow billboard',
            'allowed_sizes' => [[728, 90]],
        ]);

        $booking = AdBookingRequest::factory()->create([
            'payment_status' => 'unpaid',
            'status' => 'pending_review',
            'payment_method' => null,
            'payment_reference' => null,
            'paid_at' => null,
        ]);

        $lineItem = AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'creative_type' => 'html',
                'html' => '<a href="https://example.test">Advert</a>',
                'width' => 728,
                'height' => 90,
            ]);

        $pendingLineItem = AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->for(AdZone::factory()->create([
                'key' => 'admin_workflow_sidebar',
                'name' => 'Admin workflow sidebar',
            ]), 'zone')
            ->create([
                'status' => 'pending_review',
                'creative_type' => 'html',
                'html' => '<a href="https://example.test/pending">Pending advert</a>',
                'width' => 300,
                'height' => 250,
            ]);

        $rejectedLineItem = AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->for(AdZone::factory()->create([
                'key' => 'admin_workflow_mobile',
                'name' => 'Admin workflow mobile',
            ]), 'zone')
            ->create([
                'status' => 'rejected',
                'creative_type' => 'html',
                'html' => '<a href="https://example.test/rejected">Rejected advert</a>',
                'width' => 320,
                'height' => 50,
            ]);

        Livewire::test(ListAdBookingRequests::class)
            ->callAction(TestAction::make('markPaid')->table($booking), [
                'payment_method' => 'manual_bank_transfer',
                'payment_reference' => 'bank statement 42',
                'paid_at' => $paidAt->format('Y-m-d H:i:s'),
            ])
            ->callAction(TestAction::make('approveAndPublish')->table($booking));

        $booking->refresh();
        $lineItem->refresh();
        $pendingLineItem->refresh();
        $rejectedLineItem->refresh();

        $this->assertSame('paid', $booking->payment_status);
        $this->assertSame('manual_bank_transfer', $booking->payment_method);
        $this->assertSame('bank statement 42', $booking->payment_reference);
        $this->assertTrue($paidAt->utc()->equalTo($booking->paid_at));
        $this->assertStringContainsString('Marked paid by admin #'.$admin->id, (string) $booking->admin_notes);

        $this->assertSame('published', $booking->status);
        $this->assertSame('published', $lineItem->status);
        $this->assertNotNull($lineItem->ad_campaign_id);
        $this->assertNotNull($lineItem->ad_creative_id);

        $this->assertSame('pending_review', $pendingLineItem->status);
        $this->assertNull($pendingLineItem->ad_campaign_id);
        $this->assertNull($pendingLineItem->ad_creative_id);

        $this->assertSame('rejected', $rejectedLineItem->status);
        $this->assertNull($rejectedLineItem->ad_campaign_id);
        $this->assertNull($rejectedLineItem->ad_creative_id);
    }

    public function test_admin_rejection_stores_customer_visible_reason(): void
    {
        $this->seed();
        $this->actingAs($this->userWithRole('ad_manager'));

        $booking = AdBookingRequest::factory()->create([
            'status' => 'published',
            'admin_notes' => 'Existing operations note',
        ]);
        $zone = AdZone::factory()->create();
        $campaign = AdCampaign::factory()->create(['status' => 'active']);
        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Live booking creative',
            'type' => 'html',
            'html' => '<a href="https://example.test">Ad</a>',
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'published',
            ]);
        $campaign->forceFill(['notes' => $this->managedCampaignNotes($booking, $lineItem)])->save();
        $creative->forceFill(['name' => $this->managedCreativeName($booking, $lineItem)])->save();
        $lineItem->forceFill([
            'ad_campaign_id' => $campaign->id,
            'ad_creative_id' => $creative->id,
        ])->save();

        $reason = 'Campaign dates need to be adjusted before publication.';

        Livewire::test(ListAdBookingRequests::class)
            ->callAction(TestAction::make('reject')->table($booking), [
                'reason' => $reason,
            ]);

        $booking->refresh();

        $this->assertSame('rejected', $booking->status);
        $this->assertSame($reason, $booking->rejection_reason);
        $this->assertStringContainsString('Existing operations note', $booking->admin_notes);
        $this->assertStringContainsString('Rejection reason: Campaign dates need to be adjusted before publication.', $booking->admin_notes);
        $this->assertSame('rejected', $lineItem->refresh()->status);
        $this->assertSame('paused', $campaign->refresh()->status);
        $this->assertFalse($creative->refresh()->is_active);
    }

    public function test_discount_code_resource_requires_manage_ads_permission(): void
    {
        $this->seed();

        $discountCode = AdDiscountCode::factory()->create();

        $viewer = User::factory()->create();
        $viewerRole = Role::query()->create([
            'name' => 'ad_viewer',
            'label' => 'Ad viewer',
        ]);
        $viewerRole->permissions()->attach(
            Permission::query()->where('name', 'ads.view')->firstOrFail()
        );
        $viewer->roles()->attach($viewerRole);

        $this->actingAs($viewer);
        $this->assertTrue(AdDiscountCodeResource::canAccess());
        $this->assertFalse(AdDiscountCodeResource::canCreate());
        $this->assertFalse(AdDiscountCodeResource::canEdit($discountCode));
        $this->assertFalse(AdDiscountCodeResource::canDelete($discountCode));

        $this->actingAs($this->userWithRole('ad_manager'));
        $this->assertTrue(AdDiscountCodeResource::canAccess());
        $this->assertTrue(AdDiscountCodeResource::canCreate());
        $this->assertTrue(AdDiscountCodeResource::canEdit($discountCode));
        $this->assertTrue(AdDiscountCodeResource::canDelete($discountCode));
    }

    public function test_line_item_actions_update_review_fields_and_deactivate_linked_live_ads(): void
    {
        $this->seed();
        $this->actingAs($this->userWithRole('ad_manager'));

        $booking = AdBookingRequest::factory()->create();
        $lineItem = AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->create([
                'status' => 'rejected',
                'rejection_reason' => 'Earlier rejection',
                'technical_review_required' => true,
            ]);

        Livewire::test(LineItemsRelationManager::class, [
            'ownerRecord' => $booking,
            'pageClass' => EditAdBookingRequest::class,
        ])->callTableAction('approve', $lineItem);

        $this->assertSame('approved', $lineItem->refresh()->status);
        $this->assertNull($lineItem->rejection_reason);
        $this->assertFalse($lineItem->technical_review_required);

        $campaign = AdCampaign::factory()->create(['status' => 'active']);
        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $lineItem->ad_zone_id,
            'name' => 'Live line item creative',
            'type' => 'html',
            'html' => '<a href="https://example.test">Ad</a>',
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);
        $campaign->forceFill(['notes' => $this->managedCampaignNotes($booking, $lineItem)])->save();
        $creative->forceFill(['name' => $this->managedCreativeName($booking, $lineItem)])->save();
        $lineItem->forceFill([
            'status' => 'published',
            'technical_review_required' => true,
            'ad_campaign_id' => $campaign->id,
            'ad_creative_id' => $creative->id,
        ])->save();

        Livewire::test(LineItemsRelationManager::class, [
            'ownerRecord' => $booking,
            'pageClass' => EditAdBookingRequest::class,
        ])->callTableAction('reject', $lineItem, [
            'reason' => 'Landing page URL needs replacement.',
        ]);

        $this->assertSame('rejected', $lineItem->refresh()->status);
        $this->assertSame('Landing page URL needs replacement.', $lineItem->rejection_reason);
        $this->assertFalse($lineItem->technical_review_required);
        $this->assertSame('paused', $campaign->refresh()->status);
        $this->assertFalse($creative->refresh()->is_active);
    }

    public function test_booking_requests_cannot_be_deleted_from_admin_workflow(): void
    {
        $this->seed();
        $this->actingAs($this->userWithRole('ad_manager'));

        $booking = AdBookingRequest::factory()->create();

        $this->assertFalse(AdBookingRequestResource::canDelete($booking));
        $this->assertFalse(AdBookingRequestResource::canDeleteAny());

        Livewire::test(ListAdBookingRequests::class)
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(EditAdBookingRequest::class, ['record' => $booking->id])
            ->assertActionDoesNotExist('delete');
    }

    public function test_booking_edit_form_does_not_dehydrate_workflow_payment_or_total_fields(): void
    {
        $this->seed();
        $this->actingAs($this->userWithRole('ad_manager'));

        $booking = AdBookingRequest::factory()->create([
            'status' => 'pending_review',
            'payment_status' => 'unpaid',
            'subtotal_denars' => 15000,
            'discount_denars' => 0,
            'tax_rate' => 0,
            'tax_denars' => 0,
            'total_denars' => 15000,
            'payment_method' => null,
            'payment_reference' => null,
            'paid_at' => null,
            'external_payment_provider' => null,
            'external_payment_id' => null,
        ]);

        Livewire::test(EditAdBookingRequest::class, ['record' => $booking->id])
            ->fillForm([
                'status' => 'published',
                'payment_status' => 'paid',
                'subtotal_denars' => 1,
                'discount_denars' => 1,
                'tax_rate' => 18,
                'tax_denars' => 1,
                'total_denars' => 1,
                'payment_method' => 'card',
                'payment_reference' => 'manual bypass',
                'paid_at' => now()->format('Y-m-d H:i:s'),
                'external_payment_provider' => 'provider',
                'external_payment_id' => 'external-id',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $booking->refresh();

        $this->assertSame('pending_review', $booking->status);
        $this->assertSame('unpaid', $booking->payment_status);
        $this->assertSame(15000, $booking->subtotal_denars);
        $this->assertSame(0, $booking->discount_denars);
        $this->assertSame('0.00', $booking->tax_rate);
        $this->assertSame(0, $booking->tax_denars);
        $this->assertSame(15000, $booking->total_denars);
        $this->assertNull($booking->payment_method);
        $this->assertNull($booking->payment_reference);
        $this->assertNull($booking->paid_at);
        $this->assertNull($booking->external_payment_provider);
        $this->assertNull($booking->external_payment_id);
    }

    public function test_invoice_and_price_forms_validate_unique_constrained_records(): void
    {
        $this->seed();
        $this->actingAs($this->userWithRole('ad_manager'));

        $booking = AdBookingRequest::factory()->create();
        $invoice = AdInvoice::factory()->for($booking, 'bookingRequest')->create();

        Livewire::test(EditAdInvoice::class, ['record' => $invoice->id])
            ->fillForm([
                'number' => $invoice->number,
                'ad_booking_request_id' => $booking->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(CreateAdInvoice::class)
            ->fillForm([
                'number' => 'INV-UNIQUE-TEST',
                'ad_booking_request_id' => $booking->id,
                'type' => 'invoice',
                'status' => 'issued',
            ])
            ->call('create')
            ->assertHasFormErrors(['ad_booking_request_id' => 'unique']);

        $zone = AdZone::factory()->create();
        $price = AdPrice::factory()->for($zone, 'zone')->create([
            'duration_weeks' => 2,
            'creative_type' => 'image',
        ]);

        Livewire::test(EditAdPrice::class, ['record' => $price->id])
            ->fillForm([
                'ad_zone_id' => $zone->id,
                'duration_weeks' => 2,
                'creative_type' => 'image',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(CreateAdPrice::class)
            ->fillForm([
                'ad_zone_id' => $zone->id,
                'duration_weeks' => 2,
                'creative_type' => 'image',
                'price_denars' => 12000,
                'political_multiplier' => 1,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['ad_zone_id' => 'unique']);
    }

    public function test_ads_viewer_cannot_see_booking_or_line_item_workflow_actions(): void
    {
        $this->seed();

        $viewer = User::factory()->create();
        $viewerRole = Role::query()->create([
            'name' => 'ad_viewer',
            'label' => 'Ad viewer',
        ]);
        $viewerRole->permissions()->attach(
            Permission::query()->where('name', 'ads.view')->firstOrFail()
        );
        $viewer->roles()->attach($viewerRole);

        $booking = AdBookingRequest::factory()->create(['payment_status' => 'unpaid']);
        $lineItem = AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->create();

        $this->actingAs($viewer);

        Livewire::test(ListAdBookingRequests::class)
            ->assertTableActionHidden('markPaid', $booking)
            ->assertTableActionHidden('approveAndPublish', $booking)
            ->assertTableActionHidden('reject', $booking);

        Livewire::test(LineItemsRelationManager::class, [
            'ownerRecord' => $booking,
            'pageClass' => EditAdBookingRequest::class,
        ])
            ->assertTableActionHidden('approve', $lineItem)
            ->assertTableActionHidden('reject', $lineItem)
            ->assertTableActionHidden('needsTechnicalReview', $lineItem);
    }

    public function test_refunded_bookings_cannot_be_marked_paid_from_admin(): void
    {
        $this->seed();
        $this->actingAs($this->userWithRole('ad_manager'));

        $booking = AdBookingRequest::factory()->create([
            'status' => 'approved',
            'payment_status' => 'refunded',
        ]);

        Livewire::test(ListAdBookingRequests::class)
            ->assertTableActionHidden('markPaid', $booking);
    }

    public function test_line_item_review_actions_are_hidden_for_terminal_parent_bookings(): void
    {
        $this->seed();
        $this->actingAs($this->userWithRole('ad_manager'));

        foreach (['rejected', 'cancelled'] as $status) {
            $booking = AdBookingRequest::factory()->create(['status' => $status]);
            $lineItem = AdBookingLineItem::factory()
                ->for($booking, 'bookingRequest')
                ->create(['status' => 'pending_review']);

            Livewire::test(LineItemsRelationManager::class, [
                'ownerRecord' => $booking,
                'pageClass' => EditAdBookingRequest::class,
            ])
                ->assertTableActionHidden('approve', $lineItem)
                ->assertTableActionHidden('reject', $lineItem)
                ->assertTableActionHidden('needsTechnicalReview', $lineItem);
        }
    }

    public function test_publish_action_is_hidden_until_booking_is_paid_open_and_has_publishable_line_items(): void
    {
        $this->seed();
        $this->actingAs($this->userWithRole('ad_manager'));

        $unpaidBooking = AdBookingRequest::factory()->create(['payment_status' => 'unpaid']);
        $paidBooking = AdBookingRequest::factory()->create([
            'status' => 'approved',
            'payment_status' => 'paid',
        ]);
        $paidWithoutPublishableItems = AdBookingRequest::factory()->create([
            'status' => 'approved',
            'payment_status' => 'paid',
        ]);
        $rejectedBooking = AdBookingRequest::factory()->create([
            'status' => 'rejected',
            'payment_status' => 'paid',
        ]);

        AdBookingLineItem::factory()
            ->for($paidBooking, 'bookingRequest')
            ->create(['status' => 'approved']);
        AdBookingLineItem::factory()
            ->for($paidWithoutPublishableItems, 'bookingRequest')
            ->create(['status' => 'pending_review']);
        AdBookingLineItem::factory()
            ->for($rejectedBooking, 'bookingRequest')
            ->create(['status' => 'approved']);

        Livewire::test(ListAdBookingRequests::class)
            ->assertTableActionHidden('approveAndPublish', $unpaidBooking)
            ->assertTableActionVisible('approveAndPublish', $paidBooking)
            ->assertTableActionHidden('approveAndPublish', $paidWithoutPublishableItems)
            ->assertTableActionHidden('approveAndPublish', $rejectedBooking);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
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
