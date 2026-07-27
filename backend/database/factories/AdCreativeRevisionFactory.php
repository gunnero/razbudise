<?php

namespace Database\Factories;

use App\Models\AdBookingLineItem;
use App\Models\AdCreativeRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdCreativeRevision>
 */
class AdCreativeRevisionFactory extends Factory
{
    protected $model = AdCreativeRevision::class;

    public function definition(): array
    {
        return [
            'ad_booking_line_item_id' => AdBookingLineItem::factory(),
            'submitted_by' => null,
            'image_id' => null,
            'creative_type' => 'image',
            'html' => null,
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
            'status' => 'pending_review',
            'technical_review_required' => false,
            'rejection_reason' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ];
    }
}
