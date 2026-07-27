<?php

namespace App\Models;

use App\Support\UrlPath;
use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (Redirect $redirect): void {
            $redirect->source_path_hash = self::sourcePathHash($redirect->source_url);
        });
    }

    public static function sourcePathHash(?string $url): ?string
    {
        $path = UrlPath::normalize($url);

        return $path === '' ? null : hash('sha256', $path);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
