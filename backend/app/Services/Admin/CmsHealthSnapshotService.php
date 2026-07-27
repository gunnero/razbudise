<?php

namespace App\Services\Admin;

use App\Models\Article;
use App\Models\Author;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\WordpressImportRecord;
use Filament\Facades\Filament;

class CmsHealthSnapshotService
{
    /**
     * @return array{
     *     navigation: array{registered_resources: int, accessible_resources: int},
     *     content: array{published_articles: int, active_authors: int, media_assets: int},
     *     migration: array{post_records: int, imported_posts: int, failed_imports: int},
     *     admin: array{admin_users: int}
     * }
     */
    public function snapshot(): array
    {
        $resources = collect(Filament::getPanel('admin')->getResources());

        return [
            'navigation' => [
                'registered_resources' => $resources->count(),
                'accessible_resources' => $resources
                    ->filter(fn (string $resource): bool => $resource::canAccess())
                    ->count(),
            ],
            'content' => [
                'published_articles' => Article::query()->published()->count(),
                'active_authors' => Author::query()->where('is_active', true)->count(),
                'media_assets' => MediaAsset::query()->count(),
            ],
            'migration' => [
                'post_records' => WordpressImportRecord::query()
                    ->where('wordpress_type', 'post')
                    ->count(),
                'imported_posts' => WordpressImportRecord::query()
                    ->where('wordpress_type', 'post')
                    ->where('status', 'imported')
                    ->count(),
                'failed_imports' => WordpressImportRecord::query()
                    ->where('status', 'failed')
                    ->count(),
            ],
            'admin' => [
                'admin_users' => User::query()
                    ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))
                    ->count(),
            ],
        ];
    }
}
