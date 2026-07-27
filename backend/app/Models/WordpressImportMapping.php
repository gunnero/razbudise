<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WordpressImportMapping extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'wordpress_id' => 'integer',
        ];
    }

    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'target_type', 'target_id');
    }
}
