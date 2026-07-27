<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleChangeRequest extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'deadline_at' => 'date',
            'resubmitted_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resubmitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resubmitted_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ArticleEditorialComment::class, 'change_request_id')
            ->whereNull('parent_id')
            ->orderByDesc('is_blocking')
            ->orderBy('is_resolved')
            ->oldest();
    }
}
