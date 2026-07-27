<?php

namespace App\Models;

use Database\Factories\AdDiscountCodeFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AdDiscountCode extends Model
{
    /** @use HasFactory<AdDiscountCodeFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ad_zone_keys' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : Str::upper(trim($value)),
        );
    }

    public function bookingRequests(): HasMany
    {
        return $this->hasMany(AdBookingRequest::class, 'discount_code_id');
    }
}
