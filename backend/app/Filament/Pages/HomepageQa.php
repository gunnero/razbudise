<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasLocalizedPageNavigation;
use App\Services\Homepage\HomepageSectionQaService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class HomepageQa extends Page
{
    use HasLocalizedPageNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $navigationLabel = 'Homepage QA';

    protected static ?int $navigationSort = 15;

    protected static ?string $title = 'Homepage QA';

    protected string $view = 'filament.pages.homepage-qa';

    /**
     * @var array<string, mixed>
     */
    public array $report = [];

    public function mount(HomepageSectionQaService $qa): void
    {
        $this->report = $qa->summary();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('settings.manage') ?? false;
    }
}
