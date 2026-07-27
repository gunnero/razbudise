<?php

namespace App\Services\Authors;

use App\Models\Author;
use App\Services\Media\MediaAssetUploader;
use Illuminate\Support\Arr;

class AuthorProfileFormDataService
{
    public function __construct(private readonly MediaAssetUploader $mediaUploader) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(array $data, ?Author $author = null): array
    {
        $restoreDefault = (bool) Arr::pull($data, 'use_default_avatar', false);
        $uploadedPath = Arr::pull($data, 'avatar_upload');
        $uploadedPath = is_array($uploadedPath) ? Arr::first($uploadedPath) : $uploadedPath;
        $existingPath = $author?->avatar?->path;

        if ($restoreDefault) {
            $data['avatar_id'] = null;
        } elseif (filled($uploadedPath) && $uploadedPath !== $existingPath) {
            $data['avatar_id'] = $this->mediaUploader->registerStoredPath(
                (string) $uploadedPath,
                [
                    'uploaded_by' => auth()->id(),
                    'alt_text' => filled($data['name'] ?? null)
                        ? 'Профилна слика за '.$data['name']
                        : __('admin.fields.avatar'),
                    'purpose' => 'author_profile',
                ],
            )->getKey();
        }

        return $data;
    }
}
