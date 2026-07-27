<?php

namespace App\Models;

use Database\Factories\AdvertiserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Advertiser extends Model
{
    /** @use HasFactory<AdvertiserFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(AdCustomer::class);
    }

    public function bookingRequests(): HasManyThrough
    {
        return $this->hasManyThrough(
            AdBookingRequest::class,
            AdCustomer::class,
            'advertiser_id',
            'ad_customer_id',
        );
    }
}
