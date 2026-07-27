<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Analytics\EditorialAnalyticsService;
use App\Support\CsvExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EditorialAnalytics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Статистика / Analytics';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Статистика на објави';

    protected string $view = 'filament.pages.editorial-analytics';

    #[Url(as: 'period', except: 'last_30_days', history: true)]
    public string $range = 'last_30_days';

    #[Url(as: 'from', except: '', history: true)]
    public string $customFrom = '';

    #[Url(as: 'to', except: '', history: true)]
    public string $customTo = '';

    #[Url(as: 'compare', except: true, history: true)]
    public bool $compare = true;

    #[Url(as: 'metric', except: 'page_views', history: true)]
    public string $metric = 'page_views';

    #[Url(as: 'author', except: 'all', history: true)]
    public string $authorId = 'all';

    #[Url(as: 'category', except: 'all', history: true)]
    public string $categoryId = 'all';

    #[Url(as: 'article', except: 'all', history: true)]
    public string $articleId = 'all';

    #[Url(as: 'type', except: 'all', history: true)]
    public string $articleType = 'all';

    #[Url(as: 'source', except: 'all', history: true)]
    public string $source = 'all';

    #[Url(as: 'device', except: 'all', history: true)]
    public string $device = 'all';

    public function mount(): void
    {
        if (blank($this->customFrom)) {
            $this->customFrom = today()->subDays(29)->toDateString();
        }
        if (blank($this->customTo)) {
            $this->customTo = today()->toDateString();
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return ['report' => app(EditorialAnalyticsService::class)->report($user, $this->reportFilters())];
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Извези CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => $this->downloadCsv()),
        ];
    }

    public function resetFilters(): void
    {
        $this->range = 'last_30_days';
        $this->customFrom = today()->subDays(29)->toDateString();
        $this->customTo = today()->toDateString();
        $this->compare = true;
        $this->metric = 'page_views';
        $this->authorId = 'all';
        $this->categoryId = 'all';
        $this->articleId = 'all';
        $this->articleType = 'all';
        $this->source = 'all';
        $this->device = 'all';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('analytics.view') ?? false;
    }

    private function downloadCsv(): StreamedResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $analytics = app(EditorialAnalyticsService::class);
        $headers = $analytics->exportHeaders();
        $rows = $analytics->exportRows($user, $this->reportFilters());

        return response()->streamDownload(function () use ($headers, $rows): void {
            echo "\xEF\xBB\xBF";
            foreach (CsvExport::lines($headers, $rows) as $line) {
                echo $line."\n";
            }
        }, 'razbudise-editorial-analytics-'.today()->toDateString().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function reportFilters(): array
    {
        return [
            'range' => $this->range,
            'custom_from' => $this->customFrom,
            'custom_to' => $this->customTo,
            'compare' => $this->compare,
            'metric' => $this->metric,
            'author_id' => $this->authorId,
            'category_id' => $this->categoryId,
            'article_id' => $this->articleId,
            'type' => $this->articleType,
            'source' => $this->source,
            'device' => $this->device,
        ];
    }
}
