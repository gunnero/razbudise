<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasLocalizedPageNavigation;
use App\Services\Wordpress\WordpressMigrationReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class WordpressReviewQueue extends Page
{
    use HasLocalizedPageNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Migration';

    protected static ?string $navigationLabel = 'WordPress Review Queue';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'WordPress review queue';

    protected string $view = 'filament.pages.wordpress-review-queue';

    /**
     * @var array<string, mixed>
     */
    public array $report = [];

    public function mount(WordpressMigrationReportService $reports): void
    {
        $this->report = $reports->summary();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('imports.view') ?? false;
    }
}
