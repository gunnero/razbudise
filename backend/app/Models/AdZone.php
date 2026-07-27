<?php

namespace App\Models;

use Database\Factories\AdZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdZone extends Model
{
    /** @use HasFactory<AdZoneFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allowed_sizes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(AdCreative::class, 'zone_id');
    }

    public function impressions(): HasMany
    {
        return $this->hasMany(AdImpression::class, 'zone_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AdClick::class, 'zone_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(AdPrice::class, 'ad_zone_id');
    }

    public function bookingLineItems(): HasMany
    {
        return $this->hasMany(AdBookingLineItem::class, 'ad_zone_id');
    }
}
