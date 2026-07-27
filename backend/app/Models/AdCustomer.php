<?php

namespace App\Models;

use Database\Factories\AdCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdCustomer extends Model
{
    /** @use HasFactory<AdCustomerFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
            'dashboard_deactivated_at' => 'datetime',
            'former_customer_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(Advertiser::class);
    }

    public function bookingRequests(): HasMany
    {
        return $this->hasMany(AdBookingRequest::class);
    }
}
