<?php

namespace Database\Factories;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdBookingLineItem>
 */
class AdBookingLineItemFactory extends Factory
{
    protected $model = AdBookingLineItem::class;

    public function definition(): array
    {
        $start = now()->addWeek()->startOfDay();
        $durationWeeks = 2;

        return [
            'ad_booking_request_id' => AdBookingRequest::factory(),
            'ad_zone_id' => AdZone::factory(),
            'ad_campaign_id' => null,
            'ad_creative_id' => null,
            'image_id' => null,
            'duration_weeks' => $durationWeeks,
            'start_at' => $start,
            'end_at' => $start->copy()->addWeeks($durationWeeks),
            'creative_type' => 'image',
            'html' => null,
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
            'status' => 'pending_review',
            'is_political_or_sensitive' => false,
            'political_multiplier' => 1,
            'technical_review_required' => false,
            'review_notes' => null,
            'rejection_reason' => null,
            'price_denars' => 15000,
        ];
    }
}
