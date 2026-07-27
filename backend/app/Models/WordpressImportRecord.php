<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WordpressImportRecord extends Model
{
    /** @var list<string> */
    public const REDIRECTABLE_TYPES = ['post', 'category', 'tag', 'author'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_payload' => 'array',
            'warnings' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function importable(): MorphTo
    {
        return $this->morphTo();
    }
}
