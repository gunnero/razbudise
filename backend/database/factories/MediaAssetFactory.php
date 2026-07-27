<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    public function definition(): array
    {
        return [
            'disk' => 'public',
            'path' => 'uploads/'.fake()->uuid().'.jpg',
            'url' => fake()->imageUrl(),
            'mime_type' => 'image/jpeg',
            'alt_text' => fake()->sentence(3),
            'width' => 1200,
            'height' => 800,
            'size_bytes' => 250000,
            'metadata' => [],
        ];
    }
}
