<?php

namespace App\Support;

class AdminTranslations
{
    /** @return array<string, string> */
    public static function articleStatuses(): array
    {
        return __('admin.article_statuses');
    }

    /** @return array<string, string> */
    public static function articleTypes(): array
    {
        return __('admin.article_types');
    }

    public static function articleStatus(?string $status): string
    {
        return self::articleStatuses()[$status] ?? (string) $status;
    }

    public static function articleType(?string $type): string
    {
        return self::articleTypes()[$type] ?? (string) $type;
    }
}
