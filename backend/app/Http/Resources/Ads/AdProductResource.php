<?php

namespace App\Http\Resources\Ads;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'zone_key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'allowed_sizes' => $this->allowed_sizes ?? [],
            'page_context' => $this->page_context,
            'prices' => $this->prices->map(fn ($price): array => [
                'duration_weeks' => (int) $price->duration_weeks,
                'creative_type' => $price->creative_type,
                'price_denars' => (int) $price->price_denars,
                'political_multiplier' => (float) $price->political_multiplier,
            ])->values(),
            'availability_url' => $this->publicUrl('/api/ad-products/'.$this->key.'/availability'),
        ];
    }

    private function publicUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
