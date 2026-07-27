<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class AdCreative extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_fallback' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'campaign_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(AdZone::class, 'zone_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'image_id');
    }

    public function impressions(): HasMany
    {
        return $this->hasMany(AdImpression::class, 'creative_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AdClick::class, 'creative_id');
    }

    public function bookingLineItems(): HasMany
    {
        return $this->hasMany(AdBookingLineItem::class, 'ad_creative_id');
    }

    public function revisions(): HasManyThrough
    {
        return $this->hasManyThrough(
            AdCreativeRevision::class,
            AdBookingLineItem::class,
            'ad_creative_id',
            'ad_booking_line_item_id',
            'id',
            'id',
        );
    }
}
