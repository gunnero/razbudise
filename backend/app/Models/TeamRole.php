<?php

namespace App\Models;

use Database\Factories\TeamRoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TeamRole extends Model
{
    /** @use HasFactory<TeamRoleFactory> */
    use HasFactory;

    protected $guarded = [];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(TeamMember::class, 'team_member_team_role')
            ->withTimestamps()
            ->orderBy('team_members.sort_order')
            ->orderBy('team_members.name');
    }
}
