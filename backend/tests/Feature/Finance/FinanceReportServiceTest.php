<?php

namespace Tests\Feature\Finance;

use App\Models\AdInvoice;
use App\Models\FinanceCategory;
use App\Models\FinanceExpense;
use App\Models\FinanceInvoice;
use App\Services\Finance\FinanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinanceReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_summary_combines_paid_income_and_paid_expenses(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');

        AdInvoice::factory()->create([
            'status' => 'paid',
            'paid_at' => '2026-07-05 10:00:00',
            'total_denars' => 18000,
        ]);
        FinanceInvoice::factory()->create([
            'status' => 'paid',
            'paid_at' => '2026-07-06 10:00:00',
            'total_denars' => 7000,
        ]);
        FinanceExpense::factory()->create([
            'status' => 'paid',
            'paid_at' => '2026-07-07 10:00:00',
            'total_denars' => 6000,
        ]);
        FinanceExpense::factory()->create([
            'status' => 'void',
            'paid_at' => '2026-07-08 10:00:00',
            'total_denars' => 100000,
        ]);

        $summary = app(FinanceReportService::class)->monthlySummary(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        );

        $this->assertSame(25000, $summary['paid_income_denars']);
        $this->assertSame(6000, $summary['paid_expenses_denars']);
        $this->assertSame(19000, $summary['net_result_denars']);
    }

    public function test_unpaid_and_overdue_rows_are_reported(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');

        AdInvoice::factory()->create([
            'number' => 'PROF-2026-000001',
            'status' => 'issued',
            'due_at' => '2026-07-01 10:00:00',
            'total_denars' => 18000,
        ]);
        FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-000001',
            'status' => 'issued',
            'due_at' => '2026-07-20 10:00:00',
            'total_denars' => 7000,
        ]);
        FinanceExpense::factory()->create([
            'vendor_name' => 'Hetzner',
            'status' => 'unpaid',
            'due_at' => '2026-07-01 10:00:00',
            'total_denars' => 3000,
        ]);

        $summary = app(FinanceReportService::class)->dashboardSummary();

        $this->assertSame(25000, $summary['unpaid_invoices_denars']);
        $this->assertSame(18000, $summary['overdue_invoices_denars']);
        $this->assertSame(3000, $summary['unpaid_expenses_denars']);
        $this->assertSame(3000, $summary['overdue_expenses_denars']);
    }

    public function test_dashboard_summary_filters_paid_totals_by_source_status_and_category(): void
    {
        $targetCategory = FinanceCategory::factory()->create(['name' => 'Консалтинг', 'type' => 'income']);
        $otherCategory = FinanceCategory::factory()->create(['name' => 'Друг приход', 'type' => 'income']);

        AdInvoice::factory()->create([
            'status' => 'paid',
            'paid_at' => '2026-07-05 10:00:00',
            'total_denars' => 18000,
        ]);
        FinanceInvoice::factory()
            ->for($targetCategory, 'category')
            ->create([
                'status' => 'paid',
                'paid_at' => '2026-07-06 10:00:00',
                'total_denars' => 7000,
            ]);
        FinanceInvoice::factory()
            ->for($otherCategory, 'category')
            ->create([
                'status' => 'paid',
                'paid_at' => '2026-07-06 10:00:00',
                'total_denars' => 5000,
            ]);
        FinanceInvoice::factory()
            ->for($targetCategory, 'category')
            ->create([
                'status' => 'issued',
                'paid_at' => null,
                'total_denars' => 11000,
            ]);

        $summary = app(FinanceReportService::class)->dashboardSummary(
            '2026-07-01',
            '2026-07-31',
            [
                'source' => 'finance_invoice',
                'status' => 'paid',
                'finance_category_id' => $targetCategory->id,
            ],
        );

        $this->assertSame(7000, $summary['paid_income_denars']);
        $this->assertSame(0, $summary['unpaid_invoices_denars']);
    }

    public function test_income_export_includes_invoice_paid_in_range_even_if_issued_earlier(): void
    {
        AdInvoice::factory()->create([
            'number' => 'PROF-2026-000002',
            'status' => 'paid',
            'issued_at' => '2026-06-15 10:00:00',
            'paid_at' => '2026-07-05 10:00:00',
            'total_denars' => 18000,
        ]);
        FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-000002',
            'status' => 'paid',
            'issued_at' => '2026-06-16 10:00:00',
            'paid_at' => '2026-07-06 10:00:00',
            'total_denars' => 7000,
        ]);

        $rows = app(FinanceReportService::class)->incomeExportRows('2026-07-01', '2026-07-31');

        $this->assertEqualsCanonicalizing(
            ['PROF-2026-000002', 'FIN-2026-000002'],
            collect($rows)->pluck('number')->all(),
        );
    }

    public function test_income_export_filters_by_source_status_and_category(): void
    {
        $targetCategory = FinanceCategory::factory()->create(['name' => 'Услуги', 'type' => 'income']);
        $otherCategory = FinanceCategory::factory()->create(['name' => 'Друг приход', 'type' => 'income']);

        AdInvoice::factory()->create([
            'number' => 'PROF-2026-000010',
            'status' => 'paid',
            'paid_at' => '2026-07-05 10:00:00',
            'total_denars' => 18000,
        ]);
        FinanceInvoice::factory()
            ->for($targetCategory, 'category')
            ->create([
                'number' => 'FIN-2026-FILTER',
                'status' => 'paid',
                'paid_at' => '2026-07-06 10:00:00',
                'total_denars' => 7000,
            ]);
        FinanceInvoice::factory()
            ->for($otherCategory, 'category')
            ->create([
                'number' => 'FIN-2026-OTHER',
                'status' => 'paid',
                'paid_at' => '2026-07-06 10:00:00',
                'total_denars' => 5000,
            ]);

        $rows = app(FinanceReportService::class)->incomeExportRows(
            '2026-07-01',
            '2026-07-31',
            [
                'source' => 'finance_invoice',
                'status' => 'paid',
                'finance_category_id' => $targetCategory->id,
            ],
        );

        $this->assertSame(['FIN-2026-FILTER'], collect($rows)->pluck('number')->all());
    }

    public function test_ad_invoice_income_is_included_when_advertising_category_is_selected(): void
    {
        $advertisingCategory = FinanceCategory::factory()->create([
            'name' => 'Рекламирање',
            'slug' => 'reklamiranje',
            'type' => 'income',
        ]);
        $otherCategory = FinanceCategory::factory()->create([
            'name' => 'Услуги',
            'slug' => 'uslugi',
            'type' => 'income',
        ]);

        AdInvoice::factory()->create([
            'number' => 'PROF-2026-AD',
            'status' => 'paid',
            'paid_at' => '2026-07-05 10:00:00',
            'total_denars' => 18000,
        ]);

        $adRows = app(FinanceReportService::class)->incomeExportRows(
            '2026-07-01',
            '2026-07-31',
            [
                'source' => 'ad_invoice',
                'finance_category_id' => $advertisingCategory->id,
            ],
        );
        $otherCategoryRows = app(FinanceReportService::class)->incomeExportRows(
            '2026-07-01',
            '2026-07-31',
            [
                'source' => 'ad_invoice',
                'finance_category_id' => $otherCategory->id,
            ],
        );

        $this->assertSame(['PROF-2026-AD'], collect($adRows)->pluck('number')->all());
        $this->assertSame([], $otherCategoryRows);
    }

    public function test_dashboard_rows_respect_requested_date_range(): void
    {
        AdInvoice::factory()->create([
            'number' => 'PROF-2026-JULY',
            'status' => 'issued',
            'issued_at' => '2026-07-10 10:00:00',
            'due_at' => '2026-07-20 10:00:00',
        ]);
        AdInvoice::factory()->create([
            'number' => 'PROF-2026-AUGUST',
            'status' => 'issued',
            'issued_at' => '2026-08-10 10:00:00',
            'due_at' => '2026-08-20 10:00:00',
        ]);
        FinanceExpense::factory()->create([
            'vendor_name' => 'July vendor',
            'status' => 'unpaid',
            'expense_date' => '2026-07-12',
            'due_at' => '2026-07-25 10:00:00',
        ]);
        FinanceExpense::factory()->create([
            'vendor_name' => 'August vendor',
            'status' => 'unpaid',
            'expense_date' => '2026-08-12',
            'due_at' => '2026-08-25 10:00:00',
        ]);

        $summary = app(FinanceReportService::class)->dashboardSummary('2026-07-01', '2026-07-31');

        $this->assertSame(['PROF-2026-JULY'], collect($summary['recent_income_rows'])->pluck('number')->all());
        $this->assertSame(['July vendor'], collect($summary['recent_expense_rows'])->pluck('party_name')->all());
        $this->assertSame(['July vendor'], collect($summary['upcoming_expense_rows'])->pluck('party_name')->all());
    }

    public function test_income_export_excludes_unpaid_invoice_in_range(): void
    {
        AdInvoice::factory()->create([
            'number' => 'PROF-2026-000003',
            'status' => 'issued',
            'issued_at' => '2026-07-05 10:00:00',
            'paid_at' => null,
            'total_denars' => 18000,
        ]);
        FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-000003',
            'status' => 'issued',
            'issued_at' => '2026-07-06 10:00:00',
            'paid_at' => null,
            'total_denars' => 7000,
        ]);

        $rows = app(FinanceReportService::class)->incomeExportRows('2026-07-01', '2026-07-31');

        $this->assertSame([], $rows);
    }

    public function test_expense_export_includes_expense_paid_in_range_even_if_expense_date_earlier(): void
    {
        FinanceExpense::factory()->create([
            'vendor_name' => 'Hetzner',
            'status' => 'paid',
            'expense_date' => '2026-06-20',
            'paid_at' => '2026-07-07 10:00:00',
            'total_denars' => 3000,
        ]);

        $rows = app(FinanceReportService::class)->expenseExportRows('2026-07-01', '2026-07-31');

        $this->assertCount(1, $rows);
        $this->assertSame('Hetzner', $rows[0]['party_name']);
    }

    public function test_expense_export_excludes_unpaid_expense_in_range(): void
    {
        FinanceExpense::factory()->create([
            'vendor_name' => 'Hetzner',
            'status' => 'unpaid',
            'expense_date' => '2026-07-07',
            'paid_at' => null,
            'total_denars' => 3000,
        ]);

        $rows = app(FinanceReportService::class)->expenseExportRows('2026-07-01', '2026-07-31');

        $this->assertSame([], $rows);
    }

    public function test_monthly_export_clips_first_and_last_month_to_requested_partial_range(): void
    {
        AdInvoice::factory()->create([
            'status' => 'paid',
            'paid_at' => '2026-07-10 10:00:00',
            'total_denars' => 10000,
        ]);
        AdInvoice::factory()->create([
            'status' => 'paid',
            'paid_at' => '2026-07-20 10:00:00',
            'total_denars' => 2000,
        ]);
        FinanceExpense::factory()->create([
            'status' => 'paid',
            'paid_at' => '2026-08-10 10:00:00',
            'total_denars' => 1000,
        ]);
        FinanceExpense::factory()->create([
            'status' => 'paid',
            'paid_at' => '2026-08-20 10:00:00',
            'total_denars' => 5000,
        ]);

        $rows = app(FinanceReportService::class)->monthlyExportRows('2026-07-15', '2026-08-15');

        $this->assertSame([
            [
                'month' => '2026-07',
                'paid_income_denars' => 2000,
                'paid_expenses_denars' => 0,
                'net_result_denars' => 2000,
            ],
            [
                'month' => '2026-08',
                'paid_income_denars' => 0,
                'paid_expenses_denars' => 1000,
                'net_result_denars' => -1000,
            ],
        ], $rows);
    }

    public function test_export_row_has_expected_keys(): void
    {
        FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-000003',
            'status' => 'paid',
            'paid_at' => '2026-07-10 10:00:00',
            'customer_name' => 'Клиент',
            'total_denars' => 9000,
        ]);

        $rows = app(FinanceReportService::class)->incomeExportRows('2026-07-01', '2026-07-31');

        $this->assertArrayHasKey('source', $rows[0]);
        $this->assertArrayHasKey('number', $rows[0]);
        $this->assertArrayHasKey('party_name', $rows[0]);
        $this->assertArrayHasKey('category', $rows[0]);
        $this->assertArrayHasKey('status', $rows[0]);
        $this->assertArrayHasKey('issued_or_expense_date', $rows[0]);
        $this->assertArrayHasKey('due_date', $rows[0]);
        $this->assertArrayHasKey('paid_date', $rows[0]);
        $this->assertArrayHasKey('subtotal_denars', $rows[0]);
        $this->assertArrayHasKey('tax_denars', $rows[0]);
        $this->assertArrayHasKey('total_denars', $rows[0]);
        $this->assertArrayHasKey('notes', $rows[0]);
    }

    public function test_csv_export_headers_match_report_row_keys(): void
    {
        $reports = app(FinanceReportService::class);

        FinanceInvoice::factory()->create([
            'status' => 'paid',
            'paid_at' => '2026-07-10 10:00:00',
        ]);
        FinanceExpense::factory()->create([
            'status' => 'paid',
            'paid_at' => '2026-07-10 10:00:00',
        ]);

        $this->assertSame(array_keys($reports->incomeExportRows('2026-07-01', '2026-07-31')[0]), $reports->incomeExportHeaders());
        $this->assertSame(array_keys($reports->expenseExportRows('2026-07-01', '2026-07-31')[0]), $reports->expenseExportHeaders());
        $this->assertSame(array_keys($reports->monthlyExportRows('2026-07-01', '2026-07-31')[0]), $reports->monthlyExportHeaders());
    }
}
