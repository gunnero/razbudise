<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdZone;
use App\Services\Ads\AdAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_zone_is_unavailable_when_paid_booking_overlaps_requested_window(): void
    {
        $zone = AdZone::factory()->create();
        $start = now()->addWeek()->startOfDay();
        $end = $start->copy()->addWeeks(2);

        $request = AdBookingRequest::factory()->create([
            'status' => 'paid',
            'payment_status' => 'paid',
            'hold_expires_at' => now()->addDay(),
        ]);

        AdBookingLineItem::factory()
            ->for($request, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'start_at' => $start,
                'end_at' => $end,
            ]);

        $service = app(AdAvailabilityService::class);

        $this->assertFalse($service->isAvailable($zone, $start->copy()->addDays(3), $end->copy()->addDays(3)));
    }

    public function test_paid_line_item_blocks_inventory_even_when_booking_hold_expired(): void
    {
        $zone = AdZone::factory()->create();
        $start = now()->addWeek()->startOfDay();
        $end = $start->copy()->addWeeks(2);

        $request = AdBookingRequest::factory()->create([
            'status' => 'paid',
            'payment_status' => 'paid',
            'hold_expires_at' => now()->subDay(),
        ]);

        AdBookingLineItem::factory()
            ->for($request, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'paid',
                'start_at' => $start,
                'end_at' => $end,
            ]);

        $service = app(AdAvailabilityService::class);

        $this->assertFalse($service->isAvailable($zone, $start->copy()->addDay(), $end->copy()->subDay()));
    }

    public function test_zone_is_available_when_existing_booking_ends_before_requested_window(): void
    {
        $zone = AdZone::factory()->create();
        $start = now()->addWeek()->startOfDay();
        $end = $start->copy()->addWeeks(2);
        $nextStart = $end->copy();
        $nextEnd = $nextStart->copy()->addWeeks(2);

        $request = AdBookingRequest::factory()->create([
            'status' => 'paid',
            'payment_status' => 'paid',
            'hold_expires_at' => now()->addDay(),
        ]);

        AdBookingLineItem::factory()
            ->for($request, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'start_at' => $start,
                'end_at' => $end,
            ]);

        $service = app(AdAvailabilityService::class);

        $this->assertTrue($service->isAvailable($zone, $nextStart, $nextEnd));
    }

    public function test_pending_unpaid_booking_blocks_inventory_until_hold_expires(): void
    {
        $zone = AdZone::factory()->create();
        $start = now()->addWeek()->startOfDay();
        $end = $start->copy()->addWeeks(2);

        $request = AdBookingRequest::factory()->create([
            'status' => 'needs_payment',
            'payment_status' => 'unpaid',
            'hold_expires_at' => now()->addHour(),
        ]);

        AdBookingLineItem::factory()
            ->for($request, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'pending_review',
                'start_at' => $start,
                'end_at' => $end,
            ]);

        $service = app(AdAvailabilityService::class);

        $this->assertFalse($service->isAvailable($zone, $start->copy()->addDay(), $end->copy()->subDay()));
    }

    public function test_expired_hold_does_not_block_inventory(): void
    {
        $zone = AdZone::factory()->create();
        $start = now()->addWeek()->startOfDay();
        $end = $start->copy()->addWeeks(2);

        $request = AdBookingRequest::factory()->create([
            'status' => 'needs_payment',
            'payment_status' => 'unpaid',
            'hold_expires_at' => now()->subMinute(),
        ]);

        AdBookingLineItem::factory()
            ->for($request, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'pending_review',
                'start_at' => $start,
                'end_at' => $end,
            ]);

        $service = app(AdAvailabilityService::class);

        $this->assertTrue($service->isAvailable($zone, $start->copy()->addDay(), $end->copy()->subDay()));
    }

    public function test_next_available_start_moves_after_latest_overlapping_blocker(): void
    {
        $zone = AdZone::factory()->create();
        $desiredStart = now()->addWeek()->startOfDay();
        $firstEnd = $desiredStart->copy()->addWeeks(2);
        $secondEnd = $desiredStart->copy()->addWeeks(3);
        $request = AdBookingRequest::factory()->create([
            'status' => 'paid',
            'payment_status' => 'paid',
            'hold_expires_at' => now()->addDay(),
        ]);

        AdBookingLineItem::factory()
            ->for($request, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'start_at' => $desiredStart,
                'end_at' => $firstEnd,
            ]);
        AdBookingLineItem::factory()
            ->for($request, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'status' => 'approved',
                'start_at' => $desiredStart->copy()->addWeek(),
                'end_at' => $secondEnd,
            ]);

        $nextStart = app(AdAvailabilityService::class)->nextAvailableStart($zone, $desiredStart, 1);

        $this->assertTrue($secondEnd->equalTo($nextStart));
    }
}
