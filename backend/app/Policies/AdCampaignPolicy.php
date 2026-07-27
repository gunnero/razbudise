<?php

namespace App\Policies;

use App\Models\AdCampaign;
use App\Models\User;

class AdCampaignPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('ads.view') || $user->hasPermission('ads.manage');
    }

    public function view(User $user, AdCampaign $campaign): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('ads.manage');
    }

    public function update(User $user, AdCampaign $campaign): bool
    {
        return $user->hasPermission('ads.manage');
    }

    public function delete(User $user, AdCampaign $campaign): bool
    {
        return $user->hasPermission('ads.manage');
    }
}
