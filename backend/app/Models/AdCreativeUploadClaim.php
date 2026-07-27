<?php

namespace App\Models;

use Database\Factories\AdCreativeUploadClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCreativeUploadClaim extends Model
{
    /** @use HasFactory<AdCreativeUploadClaimFactory> */
    use HasFactory;

    public const int TOKEN_LENGTH = 48;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_ATTACHED = 'attached';

    public const string STATUS_REVOKED = 'revoked';

    public const string STATUS_EXPIRED = 'expired';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'attached_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimant_user_id');
    }

    public function attachedBookingRequest(): BelongsTo
    {
        return $this->belongsTo(AdBookingRequest::class, 'attached_booking_request_id');
    }

    public static function digest(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->attached_booking_request_id === null
            && $this->revoked_at === null
            && $this->expires_at?->isFuture();
    }
}
