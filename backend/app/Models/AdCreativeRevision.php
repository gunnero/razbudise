<?php

namespace App\Models;

use Database\Factories\AdCreativeRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCreativeRevision extends Model
{
    /** @use HasFactory<AdCreativeRevisionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'technical_review_required' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function bookingLineItem(): BelongsTo
    {
        return $this->belongsTo(AdBookingLineItem::class, 'ad_booking_line_item_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'image_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
