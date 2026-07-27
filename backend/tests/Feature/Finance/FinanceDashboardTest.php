<?php

namespace Tests\Feature\Finance;

use App\Filament\Pages\FinanceDashboard;
use App\Models\AdInvoice;
use App\Models\FinanceCategory;
use App\Models\FinanceExpense;
use App\Models\FinanceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_applies_date_source_status_and_category_filters_to_summary(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $targetCategory = FinanceCategory::factory()->create(['name' => 'Продажба услуги', 'type' => 'income']);
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

        $this->actingAs($admin);

        Livewire::test(FinanceDashboard::class)
            ->set('dateFrom', '2026-07-01')
            ->set('dateTo', '2026-07-31')
            ->set('source', 'finance_invoice')
            ->set('status', 'paid')
            ->set('financeCategoryId', (string) $targetCategory->id)
            ->call('applyFilters')
            ->assertSet('summary.paid_income_denars', 7000);
    }

    public function test_dashboard_applies_date_range_to_visible_row_tables(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

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

        $this->actingAs($admin);

        Livewire::test(FinanceDashboard::class)
            ->set('dateFrom', '2026-07-01')
            ->set('dateTo', '2026-07-31')
            ->call('applyFilters')
            ->assertSet('recentIncome.0.number', 'PROF-2026-JULY')
            ->assertSet('recentExpenses.0.party_name', 'July vendor')
            ->assertSet('upcomingExpenses.0.party_name', 'July vendor')
            ->assertSet('recentIncome.1', null)
            ->assertSet('recentExpenses.1', null)
            ->assertSet('upcomingExpenses.1', null);
    }
}
