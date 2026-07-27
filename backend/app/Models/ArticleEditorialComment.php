<?php

namespace App\Models;

use Database\Factories\ArticleEditorialCommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleEditorialComment extends Model
{
    /** @use HasFactory<ArticleEditorialCommentFactory> */
    use HasFactory;

    public const VISIBILITY_INTERNAL = 'internal_editorial';

    public const VISIBILITY_AUTHOR = 'author_visible';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_blocking' => 'boolean',
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
            'addressed_at' => 'datetime',
        ];
    }

    public function scopeUnresolvedBlocking(Builder $query): Builder
    {
        return $query
            ->where('is_blocking', true)
            ->where('is_resolved', false);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ArticleChangeRequest::class, 'change_request_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function addressedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressed_by');
    }
}
