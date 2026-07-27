<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthorArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'url' => "/{$this->slug}",
            'type' => $this->type,
            'is_sponsored' => $this->is_sponsored,
            'sponsored_label' => $this->sponsoredDisclosureLabel(),
            'featured_image' => MediaAssetResource::make($this->whenLoaded('featuredImage')),
            'category' => $this->whenLoaded('category', fn () => [
                'name' => $this->category?->name,
                'slug' => $this->category?->slug,
                'url' => $this->category ? "/category/{$this->category->slug}" : null,
            ]),
            'published_at' => $this->published_at?->toISOString(),
            'reading_time_minutes' => $this->reading_time_minutes,
        ];
    }
}
