<?php

namespace App\Services\Authors;

use App\Models\TeamMember;
use App\Services\Media\MediaAssetUploader;
use Illuminate\Support\Arr;

class TeamMemberProfileFormDataService
{
    public function __construct(private readonly MediaAssetUploader $mediaUploader) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(array $data, ?TeamMember $member = null): array
    {
        $uploadedPath = Arr::pull($data, 'profile_image_upload');
        $uploadedPath = is_array($uploadedPath) ? Arr::first($uploadedPath) : $uploadedPath;
        $existingPath = $member?->profileImage?->path;

        if (filled($uploadedPath) && $uploadedPath !== $existingPath) {
            $data['profile_image_id'] = $this->mediaUploader->registerStoredPath(
                (string) $uploadedPath,
                [
                    'uploaded_by' => auth()->id(),
                    'alt_text' => filled($data['name'] ?? null)
                        ? 'Профилна слика за '.$data['name']
                        : 'Team profile image',
                    'purpose' => 'team_profile',
                ],
            )->getKey();
        }

        return $data;
    }
}
