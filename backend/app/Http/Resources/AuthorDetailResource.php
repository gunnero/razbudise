<?php

namespace App\Http\Resources;

use App\Services\Authors\AuthorAvatarResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AuthorDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metaDescription = $this->metaDescription();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sex' => $this->sex,
            'avatar' => app(AuthorAvatarResolver::class)->forAuthor($this->resource, $request),
            'short_bio' => $this->short_bio,
            'bio_preview' => $this->short_bio ?: ($this->full_bio ? Str::limit(strip_tags($this->full_bio), 180) : null),
            'full_bio' => $this->full_bio,
            'job_title' => $this->job_title,
            'email' => $this->show_email ? $this->email : null,
            'social_links' => [
                'website' => $this->website_url,
                'facebook' => $this->facebook_url,
                'instagram' => $this->instagram_url,
                'linkedin' => $this->linkedin_url,
                'x' => $this->x_url,
            ],
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
            'seo' => [
                'title' => $this->seo_title ?? $this->seoMetadata?->seo_title ?? $this->name,
                'meta_description' => $metaDescription,
                'canonical_url' => $this->seoMetadata?->canonical_url,
                'og_title' => $this->seoMetadata?->og_title ?? $this->seo_title ?? $this->name,
                'og_description' => $this->seoMetadata?->og_description ?? $metaDescription,
                'og_image' => MediaAssetResource::make($this->whenLoaded('seoMetadata', fn () => $this->seoMetadata?->ogImage)),
            ],
            'url' => "/profil/{$this->slug}",
        ];
    }

    private function metaDescription(): string
    {
        $description = $this->meta_description
            ?? $this->seoMetadata?->meta_description
            ?? $this->short_bio
            ?? ($this->full_bio ? Str::limit(strip_tags($this->full_bio), 180) : null);

        return $description ?: "Текстови, профил и најнови објави од {$this->name} на Разбуди се.";
    }
}
