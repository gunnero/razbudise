<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasLocalizedPageNavigation;
use App\Services\Wordpress\WordpressMigrationReportService;
use App\Services\Wordpress\WordpressRestImporter;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;

class WordpressDryRunDashboard extends Page
{
    use HasLocalizedPageNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|\UnitEnum|null $navigationGroup = 'Migration';

    protected static ?string $navigationLabel = 'WordPress Dry Run';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'WordPress dry run';

    protected string $view = 'filament.pages.wordpress-dry-run-dashboard';

    public string $baseUrl = '';

    public int $perPage = 10;

    public int $page = 1;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $preview = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $migrationReport = null;

    public function mount(WordpressMigrationReportService $reports): void
    {
        $this->baseUrl = (string) config('services.wordpress.base_url', '');
        $this->migrationReport = $reports->summary();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('imports.view') ?? false;
    }

    public function runDryRun(WordpressRestImporter $importer): void
    {
        $this->validate([
            'baseUrl' => ['required', 'url'],
            'perPage' => ['required', 'integer', 'min:1', 'max:100'],
            'page' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->preview = $importer->dryRunPostsPage($this->baseUrl, $this->perPage, $this->page);
            $this->migrationReport = app(WordpressMigrationReportService::class)->summary();
        } catch (Throwable $exception) {
            $this->preview = null;

            Notification::make()
                ->title('WordPress dry run failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
