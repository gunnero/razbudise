<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variants = $this->metadata['responsive_variants'] ?? [];

        return [
            'id' => $this->id,
            'url' => $this->url,
            // Public article/card resources still use the stable relative path
            // as a compatibility fallback. Only the authenticated generic
            // media index suppresses storage internals.
            'path' => $this->when(! $request->routeIs('api.media-assets.index'), $this->path),
            'mime_type' => $this->mime_type,
            'alt_text' => $this->alt_text,
            'caption' => $this->caption,
            'credit' => $this->metadata['credit'] ?? null,
            'source_url' => $this->when(
                ! $request->routeIs('api.media-assets.index'),
                $this->metadata['source_url'] ?? null,
            ),
            'purpose' => $this->when($this->shouldExposePurpose($request), $this->metadata['purpose'] ?? null),
            'focal_point' => $this->metadata['focal_point'] ?? null,
            'width' => $this->width,
            'height' => $this->height,
            'responsive_urls' => [
                'original' => $this->url,
                'large' => $variants['large']['url'] ?? $this->url,
                'medium' => $variants['medium']['url'] ?? $this->url,
                'thumbnail' => $variants['thumbnail']['url'] ?? $this->url,
            ],
        ];
    }

    private function shouldExposePurpose(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user
            && ((int) $this->uploaded_by === (int) $user->id || $user->isAdmin());
    }
}
