<?php

namespace App\Filament\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;

trait HasResourcePermissions
{
    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        return static::currentUserCan(static::resourceViewPermission())
            || static::currentUserCan(static::resourceManagePermission());
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::currentUserCan(static::resourceManagePermission());
    }

    public static function canEdit(Model $record): bool
    {
        return static::currentUserCan(static::resourceManagePermission());
    }

    public static function canDelete(Model $record): bool
    {
        return static::currentUserCan(static::resourceManagePermission());
    }

    public static function canDeleteAny(): bool
    {
        return static::currentUserCan(static::resourceManagePermission());
    }

    private static function resourceViewPermission(): ?string
    {
        return defined(static::class.'::VIEW_PERMISSION') ? static::VIEW_PERMISSION : null;
    }

    private static function resourceManagePermission(): ?string
    {
        return defined(static::class.'::MANAGE_PERMISSION') ? static::MANAGE_PERMISSION : null;
    }

    private static function currentUserCan(?string $permission): bool
    {
        if (defined(static::class.'::ADMIN_ONLY') && static::ADMIN_ONLY) {
            return auth()->user()?->isAdmin() ?? false;
        }

        if (! $permission) {
            return false;
        }

        return auth()->user()?->hasPermission($permission) ?? false;
    }
}
