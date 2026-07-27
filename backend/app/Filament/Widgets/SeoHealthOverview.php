<?php

namespace App\Filament\Widgets;

use App\Services\Seo\SeoHealthCheckService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SeoHealthOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $heading = 'SEO health';

    protected ?string $description = 'Published article checks for metadata, images, and migrated URL redirects.';

    protected function getStats(): array
    {
        $summary = app(SeoHealthCheckService::class)->summary();
        $issues = collect($summary['issues']);

        return [
            Stat::make('Articles checked', number_format($summary['totals']['articles_checked']))
                ->description('Published public articles')
                ->color('info'),
            Stat::make('SEO issues', number_format($summary['totals']['issues']))
                ->description($summary['totals']['issues'] > 0 ? 'Needs editorial review' : 'No gaps found')
                ->color($summary['totals']['issues'] > 0 ? 'warning' : 'success'),
            Stat::make('Missing descriptions', number_format($issues->where('code', 'missing_meta_description')->count()))
                ->description('Meta description or excerpt fallback')
                ->color($issues->where('code', 'missing_meta_description')->count() > 0 ? 'warning' : 'success'),
            Stat::make('Missing redirects', number_format($issues->where('code', 'missing_redirect')->count()))
                ->description('Imported old URLs without active 301s')
                ->color($issues->where('code', 'missing_redirect')->count() > 0 ? 'danger' : 'success'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
