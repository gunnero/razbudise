<?php

namespace App\Services\Authors;

use App\Http\Resources\MediaAssetResource;
use App\Models\Author;
use App\Models\MediaAsset;
use App\Models\TeamMember;
use Illuminate\Http\Request;

class AuthorAvatarResolver
{
    public const SEX_MALE = 'male';

    public const SEX_FEMALE = 'female';

    private const DEFAULTS = [
        self::SEX_MALE => '/images/authors/default-male-avatar.png',
        self::SEX_FEMALE => '/images/authors/default-female-avatar.png',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forAuthor(Author $author, Request $request): array
    {
        if ($author->avatar instanceof MediaAsset && filled($author->avatar->admin_preview_url)) {
            return MediaAssetResource::make($author->avatar)->resolve($request);
        }

        return $this->defaultPayload(
            $this->normalizeSex($author->sex),
            $author->name,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forTeamMember(TeamMember $member, Request $request): array
    {
        if ($member->profileImage instanceof MediaAsset && filled($member->profileImage->admin_preview_url)) {
            return MediaAssetResource::make($member->profileImage)->resolve($request);
        }

        $sex = $this->normalizeSex($member->sex ?: $member->author?->sex);

        return $this->defaultPayload($sex, $member->name);
    }

    public function defaultUrl(?string $sex = null): string
    {
        return self::DEFAULTS[$this->normalizeSex($sex)];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPayload(string $sex, ?string $name): array
    {
        $url = self::DEFAULTS[$sex];
        $label = $sex === self::SEX_FEMALE ? 'женски' : 'машки';

        return [
            'id' => null,
            'url' => $url,
            'path' => $url,
            'mime_type' => 'image/png',
            'alt_text' => trim(sprintf('Стандардна %s профилна слика%s', $label, $name ? ' за '.$name : '')),
            'caption' => null,
            'credit' => null,
            'source_url' => null,
            'purpose' => 'author_profile_default',
            'focal_point' => null,
            'width' => 1254,
            'height' => 1254,
            'responsive_urls' => [
                'original' => $url,
                'large' => $url,
                'medium' => $url,
                'thumbnail' => $url,
            ],
        ];
    }

    private function normalizeSex(?string $sex): string
    {
        return $sex === self::SEX_FEMALE ? self::SEX_FEMALE : self::SEX_MALE;
    }
}
