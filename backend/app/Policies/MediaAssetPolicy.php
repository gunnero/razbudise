<?php

namespace App\Policies;

use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Ads\AdCreativeMediaScope;
use App\Services\Media\MediaAssetUsageService;

class MediaAssetPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('media.manage');
    }

    public function view(User $user, MediaAsset $asset): bool
    {
        if ($user->hasPermission('media.manage')) {
            return app(AdCreativeMediaScope::class)->canView($asset, $user);
        }

        return $this->hasActiveCustomerDashboard($user)
            && app(AdCreativeMediaScope::class)->isCustomerAdCreativeMedia($asset, (int) $user->getKey());
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('media.manage');
    }

    public function update(User $user, MediaAsset $asset): bool
    {
        return $user->hasPermission('media.manage')
            && ($this->canManageAllMedia($user) || $this->owns($user, $asset));
    }

    public function replace(User $user, MediaAsset $asset): bool
    {
        if (! $this->update($user, $asset)) {
            return false;
        }

        return $this->canManageAllMedia($user)
            || ! app(MediaAssetUsageService::class)->isUsedByPublishedContent($asset);
    }

    public function archive(User $user, MediaAsset $asset): bool
    {
        return $this->replace($user, $asset);
    }

    public function restore(User $user, MediaAsset $asset): bool
    {
        return $this->update($user, $asset);
    }

    public function delete(User $user, MediaAsset $asset): bool
    {
        return $this->update($user, $asset)
            && ! app(MediaAssetUsageService::class)->isUsed($asset);
    }

    private function canManageAllMedia(User $user): bool
    {
        return $user->hasRole('editor')
            || $user->hasRole('editor_in_chief')
            || $user->hasRole('media_manager');
    }

    private function owns(User $user, MediaAsset $asset): bool
    {
        return $asset->uploaded_by !== null
            && (int) $asset->uploaded_by === (int) $user->getKey();
    }

    private function hasActiveCustomerDashboard(User $user): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->adCustomer()
            ->where('status', 'active')
            ->whereNull('dashboard_deactivated_at')
            ->whereNull('former_customer_at')
            ->exists();
    }
}
