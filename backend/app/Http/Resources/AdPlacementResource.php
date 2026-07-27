<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdPlacementResource extends JsonResource
{
    /**
     * @var array{token: string, expires_at: string, zone_key: string, context: array{device: string|null, category: string|null, category_id: int|null, article_id: int|null}}
     */
    private array $delivery;

    /**
     * @param  array{token: string, expires_at: string, zone_key: string, context: array{device: string|null, category: string|null, category_id: int|null, article_id: int|null}}  $delivery
     */
    public function withDelivery(array $delivery): static
    {
        $this->delivery = $delivery;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $zoneKey = $this->delivery['zone_key'];
        $clickQuery = [
            'delivery_token' => $this->delivery['token'],
            'zone_key' => $zoneKey,
        ];

        foreach (['device'] as $key) {
            $value = $this->delivery['context'][$key];

            if ($value !== null) {
                $clickQuery[$key] = $value;
            }
        }

        return [
            'id' => $this->id,
            'zone_key' => $zoneKey,
            'name' => $this->name,
            'type' => $this->type,
            'image' => MediaAssetResource::make($this->whenLoaded('image')),
            'html' => $this->html,
            'click_url' => $this->click_url,
            'click_tracking_url' => $this->click_url
                ? $this->publicUrl('/api/ads/click/'.$this->id).'?'.http_build_query($clickQuery, encoding_type: PHP_QUERY_RFC3986)
                : null,
            'impression_tracking_url' => $this->publicUrl('/api/ads/impression'),
            'delivery_token' => $this->delivery['token'],
            'delivery_token_expires_at' => $this->delivery['expires_at'],
            'width' => $this->width,
            'height' => $this->height,
            'is_fallback' => $this->is_fallback,
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store');
    }

    private function publicUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
