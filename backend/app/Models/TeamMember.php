<?php

namespace App\Models;

use Database\Factories\TeamMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamMember extends Model
{
    /** @use HasFactory<TeamMemberFactory> */
    use HasFactory;

    public const SEX_MALE = 'male';

    public const SEX_FEMALE = 'female';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'metadata' => 'array',
            'social_links' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profileImage(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'profile_image_id');
    }

    public function primaryDepartment(): BelongsTo
    {
        return $this->belongsTo(TeamDepartment::class, 'primary_department_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_team_member_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_team_member_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function managedDepartments(): HasMany
    {
        return $this->hasMany(TeamDepartment::class, 'manager_team_member_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(TeamRole::class, 'team_member_team_role')
            ->withTimestamps()
            ->orderBy('team_roles.sort_order')
            ->orderBy('team_roles.name');
    }
}
