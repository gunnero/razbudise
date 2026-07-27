<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Services\Authors\AuthorAvatarResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ArticleDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metaDescription = $this->metaDescription();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'body_html' => $this->body_html,
            'blocks' => ArticleBlockResource::collection($this->whenLoaded('blocks')),
            'url' => "/{$this->slug}",
            'old_wordpress_id' => $this->old_wordpress_id,
            'old_url' => $this->old_url,
            'type' => $this->type,
            'presentation_type' => $this->presentation_type ?: 'standard',
            'guide' => $this->presentation_type === 'event_guide' ? $this->guidePayload() : null,
            'status' => $this->status,
            'published_at' => $this->published_at?->toISOString(),
            'reading_time_minutes' => $this->reading_time_minutes,
            'is_sponsored' => $this->is_sponsored,
            'is_ai_generated' => $this->is_ai_generated,
            'is_ai_assisted' => $this->is_ai_assisted,
            'featured_image' => MediaAssetResource::make($this->whenLoaded('featuredImage')),
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author?->id,
                'name' => $this->author?->name,
                'slug' => $this->author?->slug,
                'url' => $this->author ? "/profil/{$this->author->slug}" : null,
                'job_title' => $this->author?->job_title,
                'short_bio' => $this->author?->short_bio,
                'avatar' => $this->author ? app(AuthorAvatarResolver::class)->forAuthor($this->author, $request) : null,
                'article_count' => $this->author ? $this->author->articles()->published()->count() : 0,
                'editorial_focus' => $this->author ? $this->authorEditorialFocus($this->author->id) : [],
            ]),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
                'slug' => $this->category?->slug,
                'url' => $this->category ? "/category/{$this->category->slug}" : null,
            ]),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'sponsored_content' => $this->whenLoaded('sponsoredContentDetail', fn () => $this->sponsoredContentDetail ? [
                'sponsor_name' => $this->sponsoredContentDetail->sponsor_name,
                'sponsor_logo' => MediaAssetResource::make($this->sponsoredContentDetail->sponsorLogo),
                'sponsor_url' => $this->sponsoredContentDetail->sponsor_url,
                'disclosure_label' => $this->sponsoredContentDetail->disclosure_label,
                'campaign_start_at' => $this->sponsoredContentDetail->campaign_start_at?->toISOString(),
                'campaign_end_at' => $this->sponsoredContentDetail->campaign_end_at?->toISOString(),
                'reportable_views' => $this->sponsoredContentDetail->reportable_views,
                'reportable_clicks' => $this->sponsoredContentDetail->reportable_clicks,
            ] : null),
            'seo' => [
                'title' => $this->seoMetadata?->seo_title ?? $this->title,
                'meta_description' => $metaDescription,
                'canonical_url' => $this->seoMetadata?->canonical_url,
                'og_title' => $this->seoMetadata?->og_title ?? $this->seoMetadata?->seo_title ?? $this->title,
                'og_description' => $this->seoMetadata?->og_description ?? $metaDescription,
                'og_image' => MediaAssetResource::make($this->whenLoaded('seoMetadata', fn () => $this->seoMetadata?->ogImage)),
                'breadcrumbs' => $this->seoMetadata?->breadcrumbs ?: $this->breadcrumbs(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guidePayload(): array
    {
        $metadata = is_array($this->guide_metadata) ? $this->guide_metadata : [];
        $meta = is_array($metadata['meta'] ?? null) ? $metadata['meta'] : [];
        $events = collect(is_array($metadata['events'] ?? null) ? $metadata['events'] : [])
            ->map(fn ($event): array => $this->guideEventPayload(is_array($event) ? $event : []))
            ->filter(fn (array $event): bool => filled($event['title']))
            ->sortBy('display_order')
            ->values();

        return [
            'meta' => [
                'period_label' => $this->stringOrNull($meta['period_label'] ?? null),
                'city_label' => $this->stringOrNull($meta['city_label'] ?? null),
                'last_verified_at' => $this->dateStringOrNull($meta['last_verified_at'] ?? null),
                'event_count' => $events->count(),
            ],
            'events' => $events,
            'practical_notes' => collect(is_array($metadata['practical_notes'] ?? null) ? $metadata['practical_notes'] : [])
                ->map(fn ($note): ?string => $this->stringOrNull(is_array($note) ? ($note['note'] ?? null) : $note))
                ->filter()
                ->values(),
            'sources' => collect(is_array($metadata['sources'] ?? null) ? $metadata['sources'] : [])
                ->map(fn ($source): array => is_array($source) ? [
                    'label' => $this->stringOrNull($source['label'] ?? null),
                    'url' => $this->safeExternalUrl($source['url'] ?? null),
                ] : [])
                ->filter(fn (array $source): bool => filled($source['label'] ?? null) && filled($source['url'] ?? null))
                ->values(),
        ];
    }

    private function metaDescription(): string
    {
        $description = $this->seoMetadata?->meta_description
            ?? $this->excerpt
            ?? Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $this->body_html))) ?: '', 180);

        return $description ?: "Прочитајте ја објавата „{$this->title}“ на Разбуди се.";
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function guideEventPayload(array $event): array
    {
        return [
            'id' => $this->stringOrNull($event['id'] ?? null) ?: Str::slug((string) ($event['title'] ?? 'event')),
            'title' => $this->stringOrNull($event['title'] ?? null),
            'start_date' => $this->dateStringOrNull($event['start_date'] ?? null),
            'end_date' => $this->dateStringOrNull($event['end_date'] ?? null),
            'time' => $this->stringOrNull($event['time'] ?? null),
            'location' => $this->stringOrNull($event['location'] ?? null),
            'price_label' => $this->stringOrNull($event['price_label'] ?? null),
            'short_description' => $this->stringOrNull($event['short_description'] ?? null),
            'source_url' => $this->safeExternalUrl($event['source_url'] ?? null),
            'source_label' => $this->stringOrNull($event['source_label'] ?? null),
            'booking_url' => $this->safeExternalUrl($event['booking_url'] ?? null),
            'booking_label' => $this->stringOrNull($event['booking_label'] ?? null),
            'display_order' => (int) ($event['display_order'] ?? 0),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function dateStringOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        return $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function safeExternalUrl(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);

        if (! $url || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }

    /**
     * @return Collection<int, array{id: int, name: string, slug: string, url: string}>
     */
    private function authorEditorialFocus(?int $authorId): Collection
    {
        if (! $authorId) {
            return collect();
        }

        return Category::query()
            ->select(['categories.id', 'categories.name', 'categories.slug'])
            ->selectRaw('COUNT(articles.id) as published_articles_count')
            ->join('articles', 'articles.category_id', '=', 'categories.id')
            ->where('articles.author_id', $authorId)
            ->where('articles.status', 'published')
            ->whereNotNull('articles.published_at')
            ->where('articles.published_at', '<=', now())
            ->groupBy('categories.id', 'categories.name', 'categories.slug')
            ->orderByDesc('published_articles_count')
            ->orderBy('categories.name')
            ->limit(3)
            ->get()
            ->map(fn ($category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'url' => "/category/{$category->slug}",
            ]);
    }

    /**
     * @return array<int, array{name: string, url: string|null}>
     */
    private function breadcrumbs(): array
    {
        $breadcrumbs = [
            [
                'name' => 'Почетна',
                'url' => '/',
            ],
        ];

        if ($this->category) {
            $breadcrumbs[] = [
                'name' => $this->category->name,
                'url' => "/category/{$this->category->slug}",
            ];
        }

        $breadcrumbs[] = [
            'name' => $this->title,
            'url' => "/{$this->slug}",
        ];

        return $breadcrumbs;
    }
}
