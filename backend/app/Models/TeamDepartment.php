<?php

namespace App\Models;

use Database\Factories\TeamDepartmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamDepartment extends Model
{
    /** @use HasFactory<TeamDepartmentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function parentDepartment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_department_id');
    }

    public function childDepartments(): HasMany
    {
        return $this->hasMany(self::class, 'parent_department_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'manager_team_member_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'primary_department_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
