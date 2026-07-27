<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdCustomer;
use App\Models\AdPrice;
use App\Models\AdZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdBookingSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_request_stores_required_billing_snapshot_and_line_items(): void
    {
        $zone = AdZone::factory()->create([
            'key' => 'home_in_feed_1',
            'allowed_sizes' => [[300, 250]],
        ]);

        AdPrice::factory()->create([
            'ad_zone_id' => $zone->id,
            'duration_weeks' => 2,
            'creative_type' => 'image',
            'price_denars' => 15000,
            'is_active' => true,
        ]);

        $customer = AdCustomer::factory()->create([
            'company_name' => 'Тест Компанија ДООЕЛ',
            'tax_number' => 'MK4030999999999',
        ]);

        $request = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'reference' => 'RAZ-2026-0001',
                'billing_company_name' => 'Тест Компанија ДООЕЛ',
                'billing_tax_number' => 'MK4030999999999',
                'billing_registration_number' => '1234567',
                'billing_address' => 'Широк Сокак 1',
                'billing_city' => 'Битола',
                'billing_country' => 'MK',
                'contact_name' => 'Александар',
                'contact_email' => 'client@example.test',
                'contact_phone' => '+38970111222',
                'status' => 'pending_review',
                'subtotal_denars' => 15000,
                'discount_denars' => 0,
                'tax_rate' => 0,
                'tax_denars' => 0,
                'total_denars' => 15000,
            ]);

        AdBookingLineItem::factory()
            ->for($request, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'duration_weeks' => 2,
                'start_at' => now()->addWeek()->startOfDay(),
                'end_at' => now()->addWeeks(3)->startOfDay(),
                'creative_type' => 'image',
                'width' => 300,
                'height' => 250,
                'price_denars' => 15000,
                'status' => 'pending_review',
            ]);

        $this->assertDatabaseHas('ad_booking_requests', [
            'reference' => 'RAZ-2026-0001',
            'billing_company_name' => 'Тест Компанија ДООЕЛ',
            'billing_tax_number' => 'MK4030999999999',
            'status' => 'pending_review',
            'total_denars' => 15000,
        ]);

        $this->assertSame(1, $request->lineItems()->count());
        $this->assertSame('Тест Компанија ДООЕЛ', $customer->bookingRequests()->first()->billing_company_name);
    }
}
