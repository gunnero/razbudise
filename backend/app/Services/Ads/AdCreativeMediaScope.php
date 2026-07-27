<?php

namespace App\Services\Ads;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AdCreativeMediaScope
{
    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($this->canViewAllAdCreativeMedia($user)) {
            return $query;
        }

        if (! $user->hasPermission('media.manage')) {
            return $query
                ->where(fn (Builder $builder): Builder => $this->whereAdCreative($builder))
                ->where('uploaded_by', $user->getKey());
        }

        return $query->where(function (Builder $builder) use ($user): void {
            $builder
                ->where(fn (Builder $query): Builder => $this->whereNotAdCreative($query))
                ->orWhere(function (Builder $query) use ($user): void {
                    $this->whereAdCreative($query);
                    $query->where('uploaded_by', $user->getKey());
                });
        });
    }

    public function canView(MediaAsset $image, User $user): bool
    {
        if ($this->canViewAllAdCreativeMedia($user)) {
            return true;
        }

        if ($this->isAdCreativeMedia($image)) {
            return (int) $image->uploaded_by === (int) $user->getKey();
        }

        return $user->hasPermission('media.manage');
    }

    public function canViewAllAdCreativeMedia(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('ads.manage');
    }

    public function isAdCreativeMedia(MediaAsset $image): bool
    {
        $metadata = $image->metadata ?? [];

        return ($metadata['purpose'] ?? null) === 'ad_creative'
            || ($metadata['ad_creative_upload'] ?? false) === true;
    }

    public function isPublicBookingAdCreativeMedia(MediaAsset $image): bool
    {
        return $this->isAdCreativeMedia($image)
            && $image->uploaded_by === null;
    }

    public function isCustomerAdCreativeMedia(MediaAsset $image, int $userId): bool
    {
        return $this->isAdCreativeMedia($image)
            && (int) $image->uploaded_by === $userId;
    }

    private function whereNotAdCreative(Builder $builder): Builder
    {
        return $builder
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('metadata->purpose')
                    ->orWhere('metadata->purpose', '!=', 'ad_creative');
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('metadata->ad_creative_upload')
                    ->orWhere('metadata->ad_creative_upload', '!=', true);
            });
    }

    private function whereAdCreative(Builder $builder): Builder
    {
        return $builder->where(function (Builder $query): void {
            $query
                ->where('metadata->purpose', 'ad_creative')
                ->orWhere('metadata->ad_creative_upload', true);
        });
    }
}
