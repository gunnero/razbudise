<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Laravel\Scout\Searchable;

class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory, Searchable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'changes_requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'archived_at' => 'datetime',
            'publication_failed_at' => 'datetime',
            'last_editorial_activity_at' => 'datetime',
            'is_sponsored' => 'boolean',
            'is_ai_generated' => 'boolean',
            'is_ai_assisted' => 'boolean',
            'onefivefour_metadata' => 'array',
            'guide_metadata' => 'array',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeVisibleToEditorialUser(Builder $query, User $user): Builder
    {
        if ($user->isAdmin() || $user->hasRole('editor_in_chief')) {
            return $query;
        }

        if ($user->hasRole('editor')) {
            $managedAuthorIds = $user->editorialSupervisions()
                ->active()
                ->pluck('author_id');

            return $query->where(function (Builder $query) use ($user, $managedAuthorIds): void {
                $query
                    ->where('created_by', $user->id)
                    ->orWhere('updated_by', $user->id)
                    ->orWhere('assigned_editor_id', $user->id)
                    ->orWhere('supervising_editor_id', $user->id)
                    ->orWhereIn('author_id', $managedAuthorIds);
            });
        }

        if ($user->hasRole('author') || $user->hasRole('contributor')) {
            return $query->where('author_id', $user->teamMember?->author_id ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeInCategory(Builder $query, Category|int $category): Builder
    {
        $categoryId = $category instanceof Category ? $category->getKey() : $category;

        return $query->where(function (Builder $query) use ($categoryId): void {
            $query
                ->where('category_id', $categoryId)
                ->orWhereHas('categories', fn (Builder $categoryQuery) => $categoryQuery->whereKey($categoryId));
        });
    }

    public function getReadingTimeMinutesAttribute(): int
    {
        $plainText = html_entity_decode(strip_tags((string) $this->body_html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/[\p{L}\p{N}]+(?:[’\'-][\p{L}\p{N}]+)*/u', $plainText, $matches);
        $wordCount = count($matches[0]);

        return max(1, (int) ceil($wordCount / 220));
    }

    public function searchableAs(): string
    {
        return 'articles';
    }

    public function shouldBeSearchable(): bool
    {
        return $this->status === 'published'
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['author', 'category']);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'body' => trim(strip_tags((string) $this->body_html)),
            'type' => $this->type,
            'is_sponsored' => (bool) $this->is_sponsored,
            'category_slug' => $this->category?->slug,
            'category_name' => $this->category?->name,
            'author_slug' => $this->author?->slug,
            'author_name' => $this->author?->name,
            'published_at' => $this->published_at?->timestamp,
        ];
    }

    public function publish(?CarbonInterface $publishedAt = null): void
    {
        $this->forceFill([
            'status' => 'published',
            'published_at' => $publishedAt ?? now(),
            'scheduled_at' => null,
        ])->save();
    }

    public function scheduleFor(CarbonInterface|string $scheduledAt): void
    {
        $this->forceFill([
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt instanceof CarbonInterface ? $scheduledAt : Carbon::parse($scheduledAt),
            'published_at' => null,
        ])->save();
    }

    public function archive(): void
    {
        $this->forceFill([
            'status' => 'archived',
        ])->save();
    }

    public function sponsoredDisclosureLabel(): ?string
    {
        if ($this->sponsoredContentDetail?->disclosure_label) {
            return $this->sponsoredContentDetail->disclosure_label;
        }

        if ($this->is_sponsored) {
            return 'Спонзорирана објава';
        }

        return match ($this->type) {
            'pr' => 'ПР текст',
            'sponsored' => 'Спонзорирана објава',
            default => null,
        };
    }

    public function publicUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/{$this->slug}";
    }

    public function previewUrl(): string
    {
        return $this->publicUrl().'?preview_path='.rawurlencode($this->signedPreviewPath());
    }

    public function signedPreviewPath(): string
    {
        return URL::temporarySignedRoute(
            'api.articles.preview',
            now()->addHour(),
            ['article' => $this],
            absolute: false,
        );
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function supervisingEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervising_editor_id');
    }

    public function assignedEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_editor_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function changesRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changes_requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function editorialComments(): HasMany
    {
        return $this->hasMany(ArticleEditorialComment::class)->latest();
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ArticleChangeRequest::class)->latest();
    }

    public function latestChangeRequest(): HasOne
    {
        return $this->hasOne(ArticleChangeRequest::class)->latestOfMany();
    }

    public function unresolvedBlockingEditorialComments(): HasMany
    {
        return $this->hasMany(ArticleEditorialComment::class)->unresolvedBlocking();
    }

    public function views(): HasMany
    {
        return $this->hasMany(ArticleView::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ArticleRevision::class)->latest('version');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withTimestamps();
    }

    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'featured_image_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(ArticleBlock::class)->orderBy('sort_order');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function seoMetadata(): MorphOne
    {
        return $this->morphOne(SeoMetadata::class, 'seoable');
    }

    public function sponsoredContentDetail(): HasOne
    {
        return $this->hasOne(SponsoredContentDetail::class);
    }

    public function wordpressImportRecord(): MorphOne
    {
        return $this->morphOne(WordpressImportRecord::class, 'importable');
    }
}
