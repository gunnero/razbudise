<?php

namespace App\Models;

use Database\Factories\AdBookingLineItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdBookingLineItem extends Model
{
    /** @use HasFactory<AdBookingLineItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_political_or_sensitive' => 'boolean',
            'technical_review_required' => 'boolean',
            'political_multiplier' => 'decimal:2',
        ];
    }

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(AdBookingRequest::class, 'ad_booking_request_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(AdZone::class, 'ad_zone_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function creative(): BelongsTo
    {
        return $this->belongsTo(AdCreative::class, 'ad_creative_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'image_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AdCreativeRevision::class);
    }
}
