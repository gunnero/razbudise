<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasLocalizedPageNavigation;
use App\Services\Wordpress\WordpressMigrationReadinessService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class WordpressMigrationReadiness extends Page
{
    use HasLocalizedPageNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Migration';

    protected static ?string $navigationLabel = 'Migration Readiness';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'WordPress migration readiness';

    protected string $view = 'filament.pages.wordpress-migration-readiness';

    /**
     * @var array<string, mixed>
     */
    public array $report = [];

    public function mount(WordpressMigrationReadinessService $readiness): void
    {
        $this->report = $readiness->summary();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('imports.view') ?? false;
    }
}
