<?php

namespace App\Models;

use Database\Factories\AdBookingRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AdBookingRequest extends Model
{
    /** @use HasFactory<AdBookingRequestFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2',
            'paid_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'payment_metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(AdCustomer::class, 'ad_customer_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(AdBookingLineItem::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(AdInvoice::class);
    }

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(AdDiscountCode::class, 'discount_code_id');
    }

    public function creativeUploadClaims(): HasMany
    {
        return $this->hasMany(AdCreativeUploadClaim::class, 'attached_booking_request_id');
    }
}
