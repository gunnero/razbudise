<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasLocalizedPageNavigation;
use App\Services\Seo\RedirectQaReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class RedirectQa extends Page
{
    use HasLocalizedPageNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'SEO & Operations';

    protected static ?string $navigationLabel = 'Redirect QA';

    protected static ?int $navigationSort = 15;

    protected static ?string $title = 'Redirect QA';

    protected string $view = 'filament.pages.redirect-qa';

    /**
     * @var array<string, mixed>
     */
    public array $report = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    public function mount(RedirectQaReportService $qa): void
    {
        $this->report = $qa->summary();
        $this->rows = $qa->exportRows();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
