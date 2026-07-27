<?php

namespace App\Filament\Widgets;

use App\Services\Ads\AdReportService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdPerformanceOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected ?string $heading = 'Ad performance';

    protected ?string $description = 'Last 30 days of tracked direct-ad impressions and clicks.';

    protected function getStats(): array
    {
        $report = app(AdReportService::class)->summary(now()->subDays(30), now());
        $topCampaign = $report['campaigns'][0] ?? null;
        $topZone = $report['zones'][0] ?? null;

        return [
            Stat::make('Impressions', number_format($report['totals']['impressions']))
                ->description('Tracked ad displays')
                ->color('info'),
            Stat::make('Clicks', number_format($report['totals']['clicks']))
                ->description('Tracked ad clicks')
                ->color('success'),
            Stat::make('CTR', $report['totals']['ctr_percent'].'%')
                ->description($topCampaign ? 'Top campaign: '.$topCampaign['name'] : 'No tracked campaign activity')
                ->color($report['totals']['clicks'] > 0 ? 'success' : 'gray'),
            Stat::make('Top zone', $topZone['key'] ?? 'No activity')
                ->description($topZone ? number_format($topZone['impressions']).' impressions' : 'Awaiting tracked delivery')
                ->color($topZone ? 'warning' : 'gray'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
