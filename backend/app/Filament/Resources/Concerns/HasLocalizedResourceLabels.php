<?php

namespace App\Filament\Resources\Concerns;

use Illuminate\Support\Str;
use UnitEnum;

trait HasLocalizedResourceLabels
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
        return __(static::translationKey('plural'));
    }

    public static function getModelLabel(): string
    {
        return __(static::translationKey('singular'));
    }

    public static function getPluralModelLabel(): string
    {
        return __(static::translationKey('plural'));
    }

    private static function translationKey(string $form): string
    {
        $resource = Str::beforeLast(class_basename(static::class), 'Resource');

        return sprintf('admin.resources.%s.%s', Str::snake($resource), $form);
    }
}
