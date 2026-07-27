<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasLocalizedPageNavigation;
use App\Services\Ads\AdReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class AdReports extends Page
{
    use HasLocalizedPageNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Ad Reports';

    protected static ?int $navigationSort = 80;

    protected static ?string $title = 'Ad reports';

    protected string $view = 'filament.pages.ad-reports';

    /**
     * @var array<string, mixed>
     */
    public array $report = [];

    public function mount(): void
    {
        $this->report = app(AdReportService::class)->summary(now()->subDays(30), now());
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('ads.view') ?? false;
    }
}
