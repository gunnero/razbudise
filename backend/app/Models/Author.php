<?php

namespace App\Models;

use Database\Factories\AuthorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Author extends Model
{
    /** @use HasFactory<AuthorFactory> */
    use HasFactory;

    public const SEX_MALE = 'male';

    public const SEX_FEMALE = 'female';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'show_email' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'avatar_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function editorialSupervisions(): HasMany
    {
        return $this->hasMany(EditorialSupervision::class);
    }

    public function primarySupervision(): HasOne
    {
        return $this->hasOne(EditorialSupervision::class)
            ->active()
            ->where('is_primary', true)
            ->latestOfMany();
    }

    public function latestPublishedArticle(): HasOne
    {
        return $this->hasOne(Article::class)
            ->ofMany(['published_at' => 'max'], fn (Builder $query) => $query->published());
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function featuredCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'author_featured_category');
    }

    public function seoMetadata(): MorphOne
    {
        return $this->morphOne(SeoMetadata::class, 'seoable');
    }
}
