<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Resources\WordpressImportRecords\WordpressImportRecordResource;
use App\Services\Admin\CmsHealthSnapshotService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CmsHealthOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -4;

    protected ?string $heading = 'CMS health';

    protected ?string $description = 'Admin access, content inventory, migration state, and useful shortcuts.';

    protected function getStats(): array
    {
        $snapshot = app(CmsHealthSnapshotService::class)->snapshot();
        $navigation = $snapshot['navigation'];
        $content = $snapshot['content'];
        $migration = $snapshot['migration'];
        $admin = $snapshot['admin'];

        return [
            Stat::make('Admin navigation', "{$navigation['accessible_resources']} / {$navigation['registered_resources']}")
                ->description(number_format($admin['admin_users']).' admin users seeded')
                ->color($navigation['accessible_resources'] === $navigation['registered_resources'] ? 'success' : 'danger')
                ->url(ArticleResource::getUrl()),
            Stat::make('Published articles', number_format($content['published_articles']))
                ->description(number_format($content['active_authors']).' active authors')
                ->color('success')
                ->url(ArticleResource::getUrl()),
            Stat::make('WordPress records', number_format($migration['post_records']))
                ->description(number_format($migration['failed_imports']).' failed imports')
                ->color($migration['failed_imports'] > 0 ? 'danger' : 'success')
                ->url(WordpressImportRecordResource::getUrl()),
            Stat::make('Media assets', number_format($content['media_assets']))
                ->description('Open media QA and asset review')
                ->color('info')
                ->url(MediaAssetResource::getUrl()),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
