<?php

namespace App\Http\Resources;

use App\Services\Authors\AuthorAvatarResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AuthorListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'job_title' => $this->job_title,
            'short_bio' => $this->short_bio,
            'bio_preview' => $this->short_bio ?: ($this->full_bio ? Str::limit(strip_tags($this->full_bio), 180) : null),
            'sex' => $this->sex,
            'avatar' => app(AuthorAvatarResolver::class)->forAuthor($this->resource, $request),
            'article_count' => $this->published_articles_count ?? 0,
            'editorial_focus' => $this->whenLoaded('editorialFocus', fn () => $this->editorialFocus->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'url' => "/category/{$category->slug}",
            ])->values()),
            'latest_article' => $this->whenLoaded('latestPublishedArticle', fn () => $this->latestPublishedArticle ? [
                'title' => $this->latestPublishedArticle->title,
                'slug' => $this->latestPublishedArticle->slug,
                'url' => "/{$this->latestPublishedArticle->slug}",
                'published_at' => $this->latestPublishedArticle->published_at?->toISOString(),
            ] : null),
            'url' => "/profil/{$this->slug}",
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
