<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'url' => "/tag/{$this->slug}",
            'updated_at' => $this->updated_at?->toISOString(),
            'seo' => [
                'title' => $this->seoMetadata?->seo_title ?? "#{$this->name}",
                'meta_description' => $this->seoMetadata?->meta_description ?? "Објави означени со {$this->name}.",
                'canonical_url' => $this->seoMetadata?->canonical_url,
                'og_title' => $this->seoMetadata?->og_title ?? $this->seoMetadata?->seo_title ?? "#{$this->name}",
                'og_description' => $this->seoMetadata?->og_description ?? $this->seoMetadata?->meta_description ?? "Објави означени со {$this->name}.",
                'og_image' => MediaAssetResource::make($this->whenLoaded('seoMetadata', fn () => $this->seoMetadata?->ogImage)),
                'breadcrumbs' => [
                    [
                        'name' => 'Почетна',
                        'url' => '/',
                    ],
                    [
                        'name' => "#{$this->name}",
                        'url' => "/tag/{$this->slug}",
                    ],
                ],
            ],
        ];
    }
}
