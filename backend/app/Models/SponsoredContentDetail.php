<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SponsoredContentDetail extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'campaign_start_at' => 'datetime',
            'campaign_end_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function sponsorLogo(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'sponsor_logo_id');
    }
}
