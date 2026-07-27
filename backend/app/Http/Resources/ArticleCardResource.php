<?php

namespace App\Http\Resources;

use App\Services\Authors\AuthorAvatarResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->homepage_title ?: $this->title,
            'canonical_title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->presentationExcerpt(),
            'url' => "/{$this->slug}",
            'type' => $this->type,
            'is_sponsored' => $this->is_sponsored,
            'sponsored_label' => $this->sponsoredDisclosureLabel(),
            'featured_image' => MediaAssetResource::make($this->whenLoaded('featuredImage')),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
                'slug' => $this->category?->slug,
                'url' => $this->category ? "/category/{$this->category->slug}" : null,
            ]),
            'author' => $this->whenLoaded('author', fn () => [
                'name' => $this->author?->name,
                'slug' => $this->author?->slug,
                'url' => $this->author ? "/profil/{$this->author->slug}" : null,
                'avatar' => $this->author ? app(AuthorAvatarResolver::class)->forAuthor($this->author, $request) : null,
            ]),
            'published_at' => $this->published_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'reading_time_minutes' => $this->reading_time_minutes,
        ];
    }

    private function presentationExcerpt(): ?string
    {
        $excerpt = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($this->homepage_excerpt ?: $this->excerpt))) ?? '');

        if ($excerpt === '') {
            return null;
        }

        if (mb_strlen($excerpt) <= 240) {
            return $excerpt;
        }

        $candidate = mb_substr($excerpt, 0, 240);
        $sentenceEnd = max(
            (int) mb_strrpos($candidate, '.'),
            (int) mb_strrpos($candidate, '!'),
            (int) mb_strrpos($candidate, '?'),
        );

        if ($sentenceEnd >= 100) {
            return trim(mb_substr($candidate, 0, $sentenceEnd + 1));
        }

        return rtrim(mb_substr($candidate, 0, 237)).'…';
    }
}
