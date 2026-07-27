<?php

namespace App\Filament\Resources\MediaAssets\Pages;

use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Services\Media\MediaAssetUploader;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateMediaAsset extends CreateRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $metadata = $data['metadata'] ?? [];
        $attributes = Arr::only($data, [
            'display_name',
            'title',
            'alt_text',
            'caption',
            'description',
            'photographer',
            'source_name',
            'license',
            'copyright_notice',
            'seo_filename_suggestion',
            'usage_notes',
        ]);
        $attributes['credit'] = data_get($metadata, 'credit');
        $attributes['source_url'] = data_get($metadata, 'source_url');
        $attributes['focal_point_x'] = data_get($metadata, 'focal_point.x');
        $attributes['focal_point_y'] = data_get($metadata, 'focal_point.y');
        $attributes['uploaded_by'] = $data['uploaded_by'] ?? auth()->id();

        return app(MediaAssetUploader::class)->registerStoredPath(
            (string) $data['path'],
            $attributes,
            (string) ($data['disk'] ?? 'public'),
        );
    }
}
