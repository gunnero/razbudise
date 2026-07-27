<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCreativeMediaBlob extends Model
{
    protected $primaryKey = 'content_sha256';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
