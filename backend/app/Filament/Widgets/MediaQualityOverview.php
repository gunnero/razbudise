<?php

namespace App\Filament\Widgets;

use App\Services\Media\MediaQualityReportService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MediaQualityOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Media quality';

    protected ?string $description = 'Editorial readiness checks for image metadata, variants, and stored files.';

    protected function getStats(): array
    {
        $report = app(MediaQualityReportService::class)->summary();

        return [
            Stat::make('Media assets', number_format($report['totals']['assets_checked']))
                ->description('Assets checked')
                ->color('info'),
            Stat::make('Quality issues', number_format($report['totals']['issues']))
                ->description($report['totals']['issues'] > 0 ? 'Needs cleanup' : 'No issues found')
                ->color($report['totals']['issues'] > 0 ? 'warning' : 'success'),
            Stat::make('Missing alt text', number_format($report['totals']['missing_alt_text']))
                ->description('Accessibility and SEO')
                ->color($report['totals']['missing_alt_text'] > 0 ? 'warning' : 'success'),
            Stat::make('Missing variants', number_format($report['totals']['missing_variants']))
                ->description('Responsive images')
                ->color($report['totals']['missing_variants'] > 0 ? 'warning' : 'success'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
