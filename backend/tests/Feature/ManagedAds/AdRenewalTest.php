<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdCustomer;
use App\Models\AdPrice;
use App\Models\AdZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_request_renewal_when_next_window_is_available(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test', ['duration_weeks' => 2]);

        $this->actingAs($user)
            ->postJson('/api/customer/bookings/'.$lineItem->id.'/renew', [
                'duration_weeks' => 4,
            ])
            ->assertAccepted()
            ->assertJsonPath('data.line_item_id', $lineItem->id)
            ->assertJsonPath('data.status', 'renewal_request_received');

        $renewal = AdBookingRequest::query()
            ->whereKeyNot($lineItem->ad_booking_request_id)
            ->with('lineItems')
            ->sole();
        $renewalLineItem = $renewal->lineItems->sole();

        $this->assertSame($lineItem->bookingRequest->ad_customer_id, $renewal->ad_customer_id);
        $this->assertSame('needs_payment', $renewal->status);
        $this->assertSame('unpaid', $renewal->payment_status);
        $this->assertSame($lineItem->bookingRequest->billing_company_name, $renewal->billing_company_name);
        $this->assertSame($lineItem->bookingRequest->contact_email, $renewal->contact_email);
        $this->assertSame($lineItem->ad_zone_id, $renewalLineItem->ad_zone_id);
        $this->assertSame(4, (int) $renewalLineItem->duration_weeks);
        $this->assertSame($lineItem->creative_type, $renewalLineItem->creative_type);
        $this->assertSame($lineItem->click_url, $renewalLineItem->click_url);
        $this->assertSame($lineItem->width, $renewalLineItem->width);
        $this->assertSame($lineItem->height, $renewalLineItem->height);
    }

    public function test_customer_cannot_renew_when_next_window_is_taken(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test', ['duration_weeks' => 2]);

        $blockingBooking = AdBookingRequest::factory()->create([
            'status' => 'paid',
            'payment_status' => 'paid',
        ]);
        AdBookingLineItem::factory()
            ->for($blockingBooking, 'bookingRequest')
            ->for($lineItem->zone, 'zone')
            ->create([
                'duration_weeks' => 1,
                'start_at' => $lineItem->end_at,
                'end_at' => $lineItem->end_at->copy()->addWeek(),
                'status' => 'approved',
            ]);

        $this->actingAs($user)
            ->postJson('/api/customer/bookings/'.$lineItem->id.'/renew', [
                'duration_weeks' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');

        $this->assertDatabaseCount('ad_booking_requests', 2);
    }

    public function test_paid_renewal_continues_without_gap(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test', [
            'duration_weeks' => 2,
            'start_at' => now()->addDays(10)->startOfDay(),
            'end_at' => now()->addDays(24)->startOfDay(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/customer/bookings/'.$lineItem->id.'/renew', [
                'duration_weeks' => 1,
            ])
            ->assertAccepted()
            ->assertJsonStructure([
                'data' => [
                    'line_item_id',
                    'renewal_booking_request_id',
                    'reference',
                    'status',
                ],
            ]);

        $renewal = AdBookingRequest::query()
            ->whereKeyNot($lineItem->ad_booking_request_id)
            ->with('lineItems')
            ->sole();
        $renewalLineItem = $renewal->lineItems->sole();

        $this->assertSame($lineItem->end_at->toDateTimeString(), $renewalLineItem->start_at->toDateTimeString());
        $this->assertSame(
            $lineItem->end_at->copy()->addWeek()->toDateTimeString(),
            $renewalLineItem->end_at->toDateTimeString(),
        );
        $this->assertSame('needs_payment', $renewal->status);
        $this->assertSame('unpaid', $renewal->payment_status);
    }

    public function test_renewal_uses_configured_price_for_selected_duration_and_political_multiplier(): void
    {
        [$user, $lineItem] = $this->customerLineItem('political-client@example.test', [
            'duration_weeks' => 1,
            'is_political_or_sensitive' => true,
            'political_multiplier' => 1,
            'price_denars' => 12000,
        ]);

        AdPrice::query()
            ->where('ad_zone_id', $lineItem->ad_zone_id)
            ->where('duration_weeks', 4)
            ->where('creative_type', $lineItem->creative_type)
            ->update([
                'price_denars' => 40000,
                'political_multiplier' => 2.5,
            ]);

        $this->actingAs($user)
            ->postJson('/api/customer/bookings/'.$lineItem->id.'/renew', [
                'duration_weeks' => 4,
            ])
            ->assertAccepted()
            ->assertJsonPath('data.total_denars', 100000);

        $renewal = AdBookingRequest::query()
            ->whereKeyNot($lineItem->ad_booking_request_id)
            ->with('lineItems')
            ->sole();
        $renewalLineItem = $renewal->lineItems->sole();

        $this->assertSame(100000, (int) $renewal->subtotal_denars);
        $this->assertSame(100000, (int) $renewal->total_denars);
        $this->assertSame(100000, (int) $renewalLineItem->price_denars);
        $this->assertSame('2.50', (string) $renewalLineItem->political_multiplier);
    }

    public function test_customer_dashboard_exposes_per_duration_renewal_options_with_prices(): void
    {
        [$user, $lineItem] = $this->customerLineItem('dashboard-client@example.test', ['duration_weeks' => 1]);

        $blockingBooking = AdBookingRequest::factory()->create([
            'status' => 'paid',
            'payment_status' => 'paid',
        ]);
        AdBookingLineItem::factory()
            ->for($blockingBooking, 'bookingRequest')
            ->for($lineItem->zone, 'zone')
            ->create([
                'duration_weeks' => 1,
                'start_at' => $lineItem->end_at->copy()->addWeek(),
                'end_at' => $lineItem->end_at->copy()->addWeeks(2),
                'status' => 'approved',
            ]);

        $this->actingAs($user)
            ->getJson('/api/customer/ads')
            ->assertOk()
            ->assertJsonPath('data.bookings.0.items.0.can_renew', true)
            ->assertJsonPath('data.bookings.0.items.0.renewal_options.0.duration_weeks', 1)
            ->assertJsonPath('data.bookings.0.items.0.renewal_options.0.available', true)
            ->assertJsonPath('data.bookings.0.items.0.renewal_options.0.price_denars', 9000)
            ->assertJsonPath('data.bookings.0.items.0.renewal_options.1.duration_weeks', 2)
            ->assertJsonPath('data.bookings.0.items.0.renewal_options.1.available', false)
            ->assertJsonPath('data.bookings.0.items.0.renewal_options.1.price_denars', 16000)
            ->assertJsonPath('data.bookings.0.items.0.renewal_options.2.duration_weeks', 4)
            ->assertJsonPath('data.bookings.0.items.0.renewal_options.2.available', false)
            ->assertJsonPath('data.bookings.0.items.0.renewal_options.2.price_denars', 30000);
    }

    public function test_renewal_without_active_price_returns_validation_error_and_creates_no_booking(): void
    {
        [$user, $lineItem] = $this->customerLineItem('missing-price-client@example.test');

        AdPrice::query()
            ->where('ad_zone_id', $lineItem->ad_zone_id)
            ->where('duration_weeks', 4)
            ->where('creative_type', $lineItem->creative_type)
            ->update(['is_active' => false]);

        $this->actingAs($user)
            ->postJson('/api/customer/bookings/'.$lineItem->id.'/renew', [
                'duration_weeks' => 4,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duration_weeks');

        $this->assertDatabaseCount('ad_booking_requests', 1);
    }

    private function customerLineItem(string $email, array $lineItemOverrides = []): array
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
        $lineItem = AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->for($zone = AdZone::factory()->create(['key' => 'home_in_feed_'.fake()->unique()->numberBetween(10, 999)]), 'zone')
            ->create(array_merge([
                'status' => 'approved',
                'creative_type' => 'html',
                'html' => '<a href="https://example.test">Original creative</a>',
                'click_url' => 'https://example.test/original',
                'width' => 300,
                'height' => 250,
            ], $lineItemOverrides));

        foreach ([1 => 9000, 2 => 16000, 4 => 30000] as $durationWeeks => $priceDenars) {
            AdPrice::factory()->create([
                'ad_zone_id' => $zone->id,
                'duration_weeks' => $durationWeeks,
                'creative_type' => $lineItem->creative_type,
                'price_denars' => $priceDenars,
                'political_multiplier' => 2,
                'is_active' => true,
            ]);
        }

        return [$user, $lineItem->refresh()];
    }
}
