<?php

namespace App\Models;

use App\Services\Media\MediaAssetUsageService;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->getRawOriginal('display_name')
            ?: $this->title
            ?: $this->alt_text
            ?: basename((string) $this->path);
    }

    public function getFilenameAttribute(): string
    {
        return basename((string) $this->path);
    }

    public function getPhotoCreditAttribute(): ?string
    {
        return $this->metadata['credit'] ?? null;
    }

    public function getUsageCountAttribute(): int
    {
        if (array_key_exists('usage_count', $this->attributes)) {
            return (int) $this->attributes['usage_count'];
        }

        return app(MediaAssetUsageService::class)->count($this);
    }

    public function getAdminPreviewUrlAttribute(): ?string
    {
        if ($this->url) {
            if (filter_var($this->url, FILTER_VALIDATE_URL)) {
                return $this->url;
            }

            if (str_starts_with($this->url, '/')) {
                return rtrim((string) config('app.frontend_url'), '/').$this->url;
            }

            return $this->url;
        }

        if (! $this->path) {
            return null;
        }

        $url = Storage::disk($this->disk ?: 'public')->url($this->path);

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    public function featuredArticles(): HasMany
    {
        return $this->hasMany(Article::class, 'featured_image_id');
    }

    public function avatarAuthors(): HasMany
    {
        return $this->hasMany(Author::class, 'avatar_id');
    }

    public function teamMemberProfiles(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'profile_image_id');
    }

    public function adBookingLineItems(): HasMany
    {
        return $this->hasMany(AdBookingLineItem::class, 'image_id');
    }

    public function seoMetadata(): HasMany
    {
        return $this->hasMany(SeoMetadata::class, 'og_image_id');
    }

    public function adCreatives(): HasMany
    {
        return $this->hasMany(AdCreative::class, 'image_id');
    }

    public function sponsoredContentDetails(): HasMany
    {
        return $this->hasMany(SponsoredContentDetail::class, 'sponsor_logo_id');
    }

    public function adCreativeRevisions(): HasMany
    {
        return $this->hasMany(AdCreativeRevision::class, 'image_id');
    }

    public function adCreativeUploadClaims(): HasMany
    {
        return $this->hasMany(AdCreativeUploadClaim::class);
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeWithUsageCount(Builder $query): Builder
    {
        return app(MediaAssetUsageService::class)->withUsageCount($query);
    }

    public function scopeWhereUsed(Builder $query, bool $used = true): Builder
    {
        return app(MediaAssetUsageService::class)->whereUsed($query, $used);
    }

    public function scopeWhereUnused(Builder $query): Builder
    {
        return app(MediaAssetUsageService::class)->whereUsed($query, false);
    }
}
