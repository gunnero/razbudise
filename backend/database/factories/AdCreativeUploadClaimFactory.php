<?php

namespace Database\Factories;

use App\Models\AdCreativeUploadClaim;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdCreativeUploadClaim>
 */
class AdCreativeUploadClaimFactory extends Factory
{
    protected $model = AdCreativeUploadClaim::class;

    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory(),
            'claimant_user_id' => null,
            'token_hash' => AdCreativeUploadClaim::digest(Str::random(AdCreativeUploadClaim::TOKEN_LENGTH)),
            'original_name' => 'banner.png',
            'status' => 'pending',
            'expires_at' => now()->addDay(),
            'attached_at' => null,
            'revoked_at' => null,
            'attached_booking_request_id' => null,
        ];
    }
}
