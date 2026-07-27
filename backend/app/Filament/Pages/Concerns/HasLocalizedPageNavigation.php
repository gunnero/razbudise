<?php

namespace App\Filament\Pages\Concerns;

use Illuminate\Support\Str;
use UnitEnum;

trait HasLocalizedPageNavigation
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = parent::getNavigationGroup();

        if (! is_string($group)) {
            return $group;
        }

        $key = match ($group) {
            'Media Library' => 'media_library',
            'SEO & Operations' => 'seo_operations',
            'Users & Access' => 'users_access',
            default => Str::snake($group),
        };

        return __(sprintf('admin.navigation.groups.%s', $key));
    }

    public static function getNavigationLabel(): string
    {
        return __(sprintf('admin.pages.%s', Str::snake(class_basename(static::class))));
    }
}
