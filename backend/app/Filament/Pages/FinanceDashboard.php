<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasLocalizedPageNavigation;
use App\Models\FinanceCategory;
use App\Services\Finance\FinanceReportService;
use App\Support\CsvExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceDashboard extends Page
{
    use HasLocalizedPageNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Finance dashboard';

    protected string $view = 'filament.pages.finance-dashboard';

    /**
     * @var array<string, mixed>
     */
    public array $summary = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $recentIncome = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $recentExpenses = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $overdueInvoices = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $upcomingExpenses = [];

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $source = 'all';

    public string $status = 'all';

    public string $financeCategoryId = 'all';

    /**
     * @var array<string, string>
     */
    public array $categoryOptions = [];

    public function mount(): void
    {
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo = now()->endOfMonth()->toDateString();
        $this->categoryOptions = FinanceCategory::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])
            ->all();

        $this->loadDashboardData();
    }

    public function applyFilters(): void
    {
        $this->loadDashboardData();
    }

    private function loadDashboardData(): void
    {
        $reports = app(FinanceReportService::class);
        $filters = $this->reportFilters();

        $this->summary = $reports->dashboardSummary($this->dateFrom, $this->dateTo, $filters);
        $this->recentIncome = $this->summary['recent_income_rows'] ?? [];
        $this->recentExpenses = $this->summary['recent_expense_rows'] ?? [];
        $this->overdueInvoices = $this->summary['overdue_invoice_rows'] ?? [];
        $this->upcomingExpenses = $this->summary['upcoming_expense_rows'] ?? [];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('finance.view') ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportIncome')
                ->label('Export income CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->authorize(fn (): bool => $this->canExportFinance())
                ->visible(fn (): bool => $this->canExportFinance())
                ->action(fn (): StreamedResponse => $this->downloadIncomeCsv()),
            Action::make('exportExpenses')
                ->label('Export expenses CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->authorize(fn (): bool => $this->canExportFinance())
                ->visible(fn (): bool => $this->canExportFinance())
                ->action(fn (): StreamedResponse => $this->downloadExpenseCsv()),
            Action::make('exportMonthlySummary')
                ->label('Export monthly summary CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->authorize(fn (): bool => $this->canExportFinance())
                ->visible(fn (): bool => $this->canExportFinance())
                ->action(fn (): StreamedResponse => $this->downloadMonthlySummaryCsv()),
        ];
    }

    private function downloadIncomeCsv(): StreamedResponse
    {
        $reports = app(FinanceReportService::class);

        return $this->downloadCsv(
            'finance-income-'.now()->format('Y-m').'.csv',
            $reports->incomeExportHeaders(),
            $reports->incomeExportRows($this->dateFrom, $this->dateTo, $this->reportFilters()),
        );
    }

    private function downloadExpenseCsv(): StreamedResponse
    {
        $reports = app(FinanceReportService::class);

        return $this->downloadCsv(
            'finance-expenses-'.now()->format('Y-m').'.csv',
            $reports->expenseExportHeaders(),
            $reports->expenseExportRows($this->dateFrom, $this->dateTo, $this->reportFilters()),
        );
    }

    private function downloadMonthlySummaryCsv(): StreamedResponse
    {
        $reports = app(FinanceReportService::class);

        return $this->downloadCsv(
            'finance-monthly-summary-'.now()->format('Y-m').'.csv',
            $reports->monthlyExportHeaders(),
            $reports->monthlyExportRows($this->dateFrom, $this->dateTo, $this->reportFilters()),
        );
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function downloadCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            foreach (CsvExport::lines($headers, $rows) as $line) {
                echo $line."\n";
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function canExportFinance(): bool
    {
        return auth()->user()?->hasPermission('finance.export') ?? false;
    }

    /**
     * @return array{source: string, status: string, finance_category_id: string}
     */
    private function reportFilters(): array
    {
        return [
            'source' => $this->source,
            'status' => $this->status,
            'finance_category_id' => $this->financeCategoryId,
        ];
    }
}
