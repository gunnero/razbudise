<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MenuItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'opens_in_new_tab' => 'boolean',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'parent_id')
            ->where('is_active', true)
            ->with(['children', 'linkable'])
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function resolvedUrl(): ?string
    {
        if ($this->link_type === 'custom') {
            return $this->url;
        }

        if (! $this->linkable) {
            return null;
        }

        return match ($this->linkable::class) {
            Article::class => "/{$this->linkable->slug}",
            Author::class => "/profil/{$this->linkable->slug}",
            Category::class => "/category/{$this->linkable->slug}",
            Tag::class => "/tag/{$this->linkable->slug}",
            default => $this->url,
        };
    }
}
