<?php

namespace App\Filament\Widgets;

use App\Services\Wordpress\WordpressRestImporter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WordpressImportOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $heading = 'WordPress migration';

    protected ?string $description = 'Import records, failures, and missing featured-image reports.';

    protected function getStats(): array
    {
        $report = app(WordpressRestImporter::class)->report();

        return [
            Stat::make('Imported posts', number_format($report['imported_count']))
                ->description(number_format($report['total_post_records']).' total post records')
                ->color('success'),
            Stat::make('Pending imports', number_format($report['pending_count']))
                ->description('Records waiting for processing')
                ->color($report['pending_count'] > 0 ? 'warning' : 'gray'),
            Stat::make('Failed imports', number_format($report['failed_count']))
                ->description('Review before launch')
                ->color($report['failed_count'] > 0 ? 'danger' : 'success'),
            Stat::make('Missing images', number_format($report['missing_featured_image_count']))
                ->description('Imported posts without featured images')
                ->color($report['missing_featured_image_count'] > 0 ? 'warning' : 'success'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
