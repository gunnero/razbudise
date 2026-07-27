<?php

namespace App\Services\Finance;

use App\Models\AdInvoice;
use App\Models\FinanceCategory;
use App\Models\FinanceExpense;
use App\Models\FinanceInvoice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinanceReportService
{
    private const AD_INVOICE_CATEGORY_SLUG = 'reklamiranje';

    /**
     * @var array<int, string>
     */
    private const ACCOUNTANT_EXPORT_HEADERS = [
        'source',
        'number',
        'party_name',
        'category',
        'status',
        'issued_or_expense_date',
        'due_date',
        'paid_date',
        'subtotal_denars',
        'tax_denars',
        'total_denars',
        'notes',
    ];

    /**
     * @var array<int, string>
     */
    private const MONTHLY_EXPORT_HEADERS = [
        'month',
        'paid_income_denars',
        'paid_expenses_denars',
        'net_result_denars',
    ];

    /**
     * @return array<string, mixed>
     */
    public function dashboardSummary(
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
        array $filters = [],
    ): array {
        $filters = $this->normalizeFilters($filters);
        [$fromAt, $toAt] = $this->dateRange($from, $to);
        $rowFilters = array_merge($filters, [
            'date_from' => $fromAt,
            'date_to' => $toAt,
        ]);
        $monthly = $this->monthlySummary($fromAt, $toAt, $filters);

        $unpaidInvoices = $this->adReceivableTotal($filters) + $this->financeReceivableTotal($filters);
        $overdueInvoices = $this->adOverdueTotal($filters) + $this->financeOverdueTotal($filters);
        $unpaidExpenses = $this->expensePayableTotal($filters);
        $overdueExpenses = $this->expenseOverdueTotal($filters);

        return array_merge($monthly, [
            'unpaid_invoices_denars' => $unpaidInvoices,
            'overdue_invoices_denars' => $overdueInvoices,
            'unpaid_expenses_denars' => $unpaidExpenses,
            'overdue_expenses_denars' => $overdueExpenses,
            'recent_income_rows' => $this->recentIncomeRows(10, $rowFilters),
            'recent_expense_rows' => $this->recentExpenseRows(10, $rowFilters),
            'overdue_invoice_rows' => $this->overdueInvoiceRows(10, $rowFilters),
            'upcoming_expense_rows' => $this->upcomingExpenseRows(10, $rowFilters),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function monthlySummary(
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
        array $filters = [],
    ): array {
        $filters = $this->normalizeFilters($filters);
        [$fromAt, $toAt] = $this->dateRange($from, $to);

        $paidIncome = $this->paidAdIncomeTotal($fromAt, $toAt, $filters)
            + $this->paidFinanceIncomeTotal($fromAt, $toAt, $filters);
        $paidExpenses = $this->paidExpenseTotal($fromAt, $toAt, $filters);

        return [
            'range' => [
                'from' => $fromAt->toDateString(),
                'to' => $toAt->toDateString(),
            ],
            'paid_income_denars' => $paidIncome,
            'paid_expenses_denars' => $paidExpenses,
            'net_result_denars' => $paidIncome - $paidExpenses,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentIncomeRows(int $limit = 10, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $adRows = collect();

        if ($this->includesSource($filters, 'ad_invoice') && $this->categoryAllowsAdInvoices($filters)) {
            $adRows = AdInvoice::query()
                ->with('bookingRequest')
                ->notVoid()
                ->tap(fn (Builder $query): Builder => $this->applyStatusFilter($query, $filters))
                ->tap(fn (Builder $query): Builder => $this->applyDateRangeFilter($query, $filters, 'issued_at'))
                ->latest('issued_at')
                ->limit($limit)
                ->get()
                ->map(fn (AdInvoice $invoice): array => $this->adInvoiceRow($invoice));
        }

        $financeRows = collect();

        if ($this->includesSource($filters, 'finance_invoice')) {
            $financeRows = FinanceInvoice::query()
                ->with('category')
                ->notVoid()
                ->tap(fn (Builder $query): Builder => $this->applyStatusFilter($query, $filters))
                ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
                ->tap(fn (Builder $query): Builder => $this->applyDateRangeFilter($query, $filters, 'issued_at'))
                ->latest('issued_at')
                ->limit($limit)
                ->get()
                ->map(fn (FinanceInvoice $invoice): array => $this->financeInvoiceRow($invoice));
        }

        return $this->sortRowsByDateDesc($adRows->concat($financeRows), $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentExpenseRows(int $limit = 10, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        if (! $this->includesSource($filters, 'finance_expense')) {
            return [];
        }

        return FinanceExpense::query()
            ->with('category')
            ->notVoid()
            ->tap(fn (Builder $query): Builder => $this->applyStatusFilter($query, $filters))
            ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
            ->tap(fn (Builder $query): Builder => $this->applyDateRangeFilter($query, $filters, 'expense_date'))
            ->latest('expense_date')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (FinanceExpense $expense): array => $this->expenseRow($expense))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function overdueInvoiceRows(int $limit = 10, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $adRows = collect();

        if ($this->includesSource($filters, 'ad_invoice') && $this->categoryAllowsAdInvoices($filters) && $this->statusAllows($filters, ['issued', 'overdue'])) {
            $adRows = AdInvoice::query()
                ->with('bookingRequest')
                ->overdue()
                ->tap(fn (Builder $query): Builder => $this->applyDateRangeFilter($query, $filters, 'due_at'))
                ->oldest('due_at')
                ->limit($limit)
                ->get()
                ->map(fn (AdInvoice $invoice): array => $this->adInvoiceRow($invoice));
        }

        $financeRows = collect();

        if ($this->includesSource($filters, 'finance_invoice') && $this->statusAllows($filters, ['issued', 'overdue'])) {
            $financeRows = FinanceInvoice::query()
                ->with('category')
                ->overdue()
                ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
                ->tap(fn (Builder $query): Builder => $this->applyDateRangeFilter($query, $filters, 'due_at'))
                ->oldest('due_at')
                ->limit($limit)
                ->get()
                ->map(fn (FinanceInvoice $invoice): array => $this->financeInvoiceRow($invoice));
        }

        return $adRows
            ->concat($financeRows)
            ->sortBy('due_date')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function upcomingExpenseRows(int $limit = 10, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        if (! $this->includesSource($filters, 'finance_expense') || ! $this->statusAllows($filters, ['unpaid', 'overdue'])) {
            return [];
        }

        return FinanceExpense::query()
            ->with('category')
            ->payable()
            ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
            ->tap(fn (Builder $query): Builder => $this->applyDateRangeFilter($query, $filters, 'due_at'))
            ->when(! $filters['date_from'], fn (Builder $query): Builder => $query->where('due_at', '>=', now()))
            ->oldest('due_at')
            ->limit($limit)
            ->get()
            ->map(fn (FinanceExpense $expense): array => $this->expenseRow($expense))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function incomeExportRows(
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
        array $filters = [],
    ): array {
        $filters = $this->normalizeFilters($filters);
        [$fromAt, $toAt] = $this->dateRange($from, $to);

        $adRows = collect();

        if ($this->includesSource($filters, 'ad_invoice') && $this->categoryAllowsAdInvoices($filters) && $this->statusAllows($filters, ['paid'])) {
            $adRows = AdInvoice::query()
                ->with('bookingRequest')
                ->paidBetween($fromAt, $toAt)
                ->oldest('paid_at')
                ->get()
                ->map(fn (AdInvoice $invoice): array => $this->adInvoiceRow($invoice));
        }

        $financeRows = collect();

        if ($this->includesSource($filters, 'finance_invoice') && $this->statusAllows($filters, ['paid'])) {
            $financeRows = FinanceInvoice::query()
                ->with('category')
                ->paidBetween($fromAt, $toAt)
                ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
                ->oldest('paid_at')
                ->get()
                ->map(fn (FinanceInvoice $invoice): array => $this->financeInvoiceRow($invoice));
        }

        return $adRows
            ->concat($financeRows)
            ->sortBy('paid_date')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function incomeExportHeaders(): array
    {
        return self::ACCOUNTANT_EXPORT_HEADERS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function expenseExportRows(
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
        array $filters = [],
    ): array {
        $filters = $this->normalizeFilters($filters);
        [$fromAt, $toAt] = $this->dateRange($from, $to);

        if (! $this->includesSource($filters, 'finance_expense') || ! $this->statusAllows($filters, ['paid'])) {
            return [];
        }

        return FinanceExpense::query()
            ->with('category')
            ->paidBetween($fromAt, $toAt)
            ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
            ->oldest('paid_at')
            ->get()
            ->map(fn (FinanceExpense $expense): array => $this->expenseRow($expense))
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function expenseExportHeaders(): array
    {
        return self::ACCOUNTANT_EXPORT_HEADERS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monthlyExportRows(
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
        array $filters = [],
    ): array {
        $filters = $this->normalizeFilters($filters);
        [$fromAt, $toAt] = $this->dateRange($from, $to);
        $cursor = $fromAt->copy()->startOfMonth();
        $rows = [];

        while ($cursor->lte($toAt)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();
            $periodStart = $monthStart->lt($fromAt) ? $fromAt->copy() : $monthStart;
            $periodEnd = $monthEnd->gt($toAt) ? $toAt->copy() : $monthEnd;
            $summary = $this->monthlySummary($periodStart, $periodEnd, $filters);

            $rows[] = [
                'month' => $monthStart->format('Y-m'),
                'paid_income_denars' => $summary['paid_income_denars'],
                'paid_expenses_denars' => $summary['paid_expenses_denars'],
                'net_result_denars' => $summary['net_result_denars'],
            ];

            $cursor->addMonth();
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function monthlyExportHeaders(): array
    {
        return self::MONTHLY_EXPORT_HEADERS;
    }

    /**
     * @return array{CarbonInterface, CarbonInterface}
     */
    private function dateRange(CarbonInterface|string|null $from, CarbonInterface|string|null $to): array
    {
        $fromAt = $this->carbon($from ?? now()->startOfMonth());
        $toAt = $this->carbon($to ?? now()->endOfMonth());

        return [
            $fromAt->copy()->startOfDay(),
            $toAt->copy()->endOfDay(),
        ];
    }

    private function carbon(CarbonInterface|string $value): Carbon
    {
        return $value instanceof CarbonInterface
            ? Carbon::instance($value)
            : Carbon::parse($value);
    }

    private function paidAdIncomeTotal(CarbonInterface $fromAt, CarbonInterface $toAt, array $filters): int
    {
        if (! $this->includesSource($filters, 'ad_invoice') || ! $this->categoryAllowsAdInvoices($filters) || ! $this->statusAllows($filters, ['paid'])) {
            return 0;
        }

        return (int) AdInvoice::query()->paidBetween($fromAt, $toAt)->sum('total_denars');
    }

    private function paidFinanceIncomeTotal(CarbonInterface $fromAt, CarbonInterface $toAt, array $filters): int
    {
        if (! $this->includesSource($filters, 'finance_invoice') || ! $this->statusAllows($filters, ['paid'])) {
            return 0;
        }

        return (int) FinanceInvoice::query()
            ->paidBetween($fromAt, $toAt)
            ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
            ->sum('total_denars');
    }

    private function paidExpenseTotal(CarbonInterface $fromAt, CarbonInterface $toAt, array $filters): int
    {
        if (! $this->includesSource($filters, 'finance_expense') || ! $this->statusAllows($filters, ['paid'])) {
            return 0;
        }

        return (int) FinanceExpense::query()
            ->paidBetween($fromAt, $toAt)
            ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
            ->sum('total_denars');
    }

    private function adReceivableTotal(array $filters): int
    {
        if (! $this->includesSource($filters, 'ad_invoice') || ! $this->categoryAllowsAdInvoices($filters) || ! $this->statusAllows($filters, ['issued', 'overdue'])) {
            return 0;
        }

        return (int) AdInvoice::query()->receivable()->sum('total_denars');
    }

    private function financeReceivableTotal(array $filters): int
    {
        if (! $this->includesSource($filters, 'finance_invoice') || ! $this->statusAllows($filters, ['issued', 'overdue'])) {
            return 0;
        }

        return (int) FinanceInvoice::query()
            ->receivable()
            ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
            ->sum('total_denars');
    }

    private function adOverdueTotal(array $filters): int
    {
        if (! $this->includesSource($filters, 'ad_invoice') || ! $this->categoryAllowsAdInvoices($filters) || ! $this->statusAllows($filters, ['issued', 'overdue'])) {
            return 0;
        }

        return (int) AdInvoice::query()->overdue()->sum('total_denars');
    }

    private function financeOverdueTotal(array $filters): int
    {
        if (! $this->includesSource($filters, 'finance_invoice') || ! $this->statusAllows($filters, ['issued', 'overdue'])) {
            return 0;
        }

        return (int) FinanceInvoice::query()
            ->overdue()
            ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
            ->sum('total_denars');
    }

    private function expensePayableTotal(array $filters): int
    {
        if (! $this->includesSource($filters, 'finance_expense') || ! $this->statusAllows($filters, ['unpaid', 'overdue'])) {
            return 0;
        }

        return (int) FinanceExpense::query()
            ->payable()
            ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
            ->sum('total_denars');
    }

    private function expenseOverdueTotal(array $filters): int
    {
        if (! $this->includesSource($filters, 'finance_expense') || ! $this->statusAllows($filters, ['unpaid', 'overdue'])) {
            return 0;
        }

        return (int) FinanceExpense::query()
            ->overdue()
            ->tap(fn (Builder $query): Builder => $this->applyFinanceCategoryFilter($query, $filters))
            ->sum('total_denars');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{source: ?string, status: ?string, finance_category_id: ?string, date_from: ?CarbonInterface, date_to: ?CarbonInterface}
     */
    private function normalizeFilters(array $filters): array
    {
        [$dateFrom, $dateTo] = $this->optionalDateRange(
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );

        return [
            'source' => $this->blankFilter($filters['source'] ?? null),
            'status' => $this->blankFilter($filters['status'] ?? null),
            'finance_category_id' => $this->blankFilter($filters['finance_category_id'] ?? null),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /**
     * @return array{?CarbonInterface, ?CarbonInterface}
     */
    private function optionalDateRange(mixed $from, mixed $to): array
    {
        if ($from === null && $to === null) {
            return [null, null];
        }

        return $this->dateRange($from, $to);
    }

    private function blankFilter(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || $value === 'all' ? null : $value;
    }

    /**
     * @param  array{source: ?string, status: ?string, finance_category_id: ?string, date_from: ?CarbonInterface, date_to: ?CarbonInterface}  $filters
     */
    private function includesSource(array $filters, string $source): bool
    {
        return $filters['source'] === null || $filters['source'] === $source;
    }

    /**
     * @param  array{source: ?string, status: ?string, finance_category_id: ?string, date_from: ?CarbonInterface, date_to: ?CarbonInterface}  $filters
     * @param  array<int, string>  $statuses
     */
    private function statusAllows(array $filters, array $statuses): bool
    {
        return $filters['status'] === null || in_array($filters['status'], $statuses, true);
    }

    /**
     * @param  array{source: ?string, status: ?string, finance_category_id: ?string, date_from: ?CarbonInterface, date_to: ?CarbonInterface}  $filters
     */
    private function categoryAllowsAdInvoices(array $filters): bool
    {
        if ($filters['finance_category_id'] === null) {
            return true;
        }

        return FinanceCategory::query()
            ->whereKey((int) $filters['finance_category_id'])
            ->where('slug', self::AD_INVOICE_CATEGORY_SLUG)
            ->exists();
    }

    /**
     * @param  array{source: ?string, status: ?string, finance_category_id: ?string, date_from: ?CarbonInterface, date_to: ?CarbonInterface}  $filters
     */
    private function applyFinanceCategoryFilter(Builder $query, array $filters): Builder
    {
        if ($filters['finance_category_id'] !== null) {
            $query->where('finance_category_id', (int) $filters['finance_category_id']);
        }

        return $query;
    }

    /**
     * @param  array{source: ?string, status: ?string, finance_category_id: ?string, date_from: ?CarbonInterface, date_to: ?CarbonInterface}  $filters
     */
    private function applyStatusFilter(Builder $query, array $filters): Builder
    {
        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    /**
     * @param  array{source: ?string, status: ?string, finance_category_id: ?string, date_from: ?CarbonInterface, date_to: ?CarbonInterface}  $filters
     */
    private function applyDateRangeFilter(Builder $query, array $filters, string $column): Builder
    {
        if ($filters['date_from'] && $filters['date_to']) {
            $query->whereBetween($column, [$filters['date_from'], $filters['date_to']]);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function adInvoiceRow(AdInvoice $invoice): array
    {
        return [
            'source' => 'ad_invoice',
            'number' => $invoice->number,
            'party_name' => $invoice->bookingRequest?->billing_company_name,
            'category' => 'Рекламирање',
            'status' => $invoice->status,
            'issued_or_expense_date' => $invoice->issued_at?->toDateString(),
            'due_date' => $invoice->due_at?->toDateString(),
            'paid_date' => $invoice->paid_at?->toDateString(),
            'subtotal_denars' => (int) $invoice->subtotal_denars,
            'tax_denars' => (int) $invoice->tax_denars,
            'total_denars' => (int) $invoice->total_denars,
            'notes' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financeInvoiceRow(FinanceInvoice $invoice): array
    {
        return [
            'source' => 'finance_invoice',
            'number' => $invoice->number,
            'party_name' => $invoice->customer_name,
            'category' => $invoice->category?->name,
            'status' => $invoice->status,
            'issued_or_expense_date' => $invoice->issued_at?->toDateString(),
            'due_date' => $invoice->due_at?->toDateString(),
            'paid_date' => $invoice->paid_at?->toDateString(),
            'subtotal_denars' => (int) $invoice->subtotal_denars,
            'tax_denars' => (int) $invoice->tax_denars,
            'total_denars' => (int) $invoice->total_denars,
            'notes' => $invoice->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseRow(FinanceExpense $expense): array
    {
        return [
            'source' => 'finance_expense',
            'number' => null,
            'party_name' => $expense->vendor_name,
            'category' => $expense->category?->name,
            'status' => $expense->status,
            'issued_or_expense_date' => $expense->expense_date?->toDateString(),
            'due_date' => $expense->due_at?->toDateString(),
            'paid_date' => $expense->paid_at?->toDateString(),
            'subtotal_denars' => (int) $expense->subtotal_denars,
            'tax_denars' => (int) $expense->tax_denars,
            'total_denars' => (int) $expense->total_denars,
            'notes' => $expense->notes,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortRowsByDateDesc(Collection $rows, int $limit): array
    {
        return $rows
            ->sortByDesc('issued_or_expense_date')
            ->take($limit)
            ->values()
            ->all();
    }
}
