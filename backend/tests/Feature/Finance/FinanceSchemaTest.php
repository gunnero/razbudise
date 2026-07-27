<?php

namespace Tests\Feature\Finance;

use App\Models\AdCampaign;
use App\Models\AdInvoice;
use App\Models\FinanceCategory;
use App\Models\FinanceExpense;
use App\Models\FinanceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_invoice_and_expense_relationships_are_available(): void
    {
        $user = User::factory()->create();
        $category = FinanceCategory::factory()->create([
            'name' => 'Услуги',
            'slug' => 'uslugi',
            'type' => 'both',
        ]);

        $invoice = FinanceInvoice::factory()
            ->for($category, 'category')
            ->for($user, 'createdBy')
            ->for($user, 'updatedBy')
            ->create([
                'number' => 'FIN-2026-000001',
                'status' => 'issued',
                'customer_name' => 'Тест Клиент',
                'subtotal_denars' => 12000,
                'discount_denars' => 1000,
                'tax_denars' => 0,
                'total_denars' => 12000,
            ]);

        $expense = FinanceExpense::factory()
            ->for($category, 'category')
            ->for($invoice, 'financeInvoice')
            ->for($user, 'createdBy')
            ->for($user, 'updatedBy')
            ->create([
                'vendor_name' => 'Тест Добавувач',
                'status' => 'unpaid',
                'subtotal_denars' => 3000,
                'tax_denars' => 0,
                'total_denars' => 3000,
            ]);

        $this->assertSame('Услуги', $invoice->category->name);
        $this->assertSame('Тест Клиент', $category->invoices()->first()->customer_name);
        $this->assertSame('Тест Добавувач', $invoice->expenses()->first()->vendor_name);
        $this->assertSame($user->id, $invoice->createdBy->id);
        $this->assertSame($user->id, $invoice->updatedBy->id);
        $this->assertSame($category->id, $expense->category->id);
        $this->assertSame($invoice->id, $expense->financeInvoice->id);
        $this->assertSame($user->id, $expense->createdBy->id);
        $this->assertSame($user->id, $expense->updatedBy->id);
        $this->assertSame($invoice->id, $user->createdFinanceInvoices()->first()->id);
        $this->assertSame($invoice->id, $user->updatedFinanceInvoices()->first()->id);
        $this->assertSame($expense->id, $user->createdFinanceExpenses()->first()->id);
        $this->assertSame($expense->id, $user->updatedFinanceExpenses()->first()->id);
        $this->assertSame(12000, $invoice->subtotal_denars);
        $this->assertSame(1000, $invoice->discount_denars);
        $this->assertSame(0, $invoice->tax_denars);
        $this->assertSame(12000, $invoice->total_denars);
        $this->assertSame(3000, $expense->subtotal_denars);
        $this->assertSame(0, $expense->tax_denars);
        $this->assertSame(3000, $expense->total_denars);
    }

    public function test_expense_can_link_to_ad_campaign_and_ad_invoice(): void
    {
        $campaign = AdCampaign::factory()->create();
        $adInvoice = AdInvoice::factory()->create();

        $expense = FinanceExpense::factory()->create([
            'related_ad_campaign_id' => $campaign->id,
            'related_ad_invoice_id' => $adInvoice->id,
        ]);

        $this->assertSame($campaign->id, $expense->adCampaign->id);
        $this->assertSame($adInvoice->id, $expense->adInvoice->id);
        $this->assertSame(1, $campaign->financeExpenses()->count());
        $this->assertSame(1, $adInvoice->financeExpenses()->count());
    }

    public function test_finance_category_scopes_filter_by_active_and_type(): void
    {
        $activeIncome = FinanceCategory::factory()->create([
            'slug' => 'active-income',
            'type' => 'income',
            'is_active' => true,
        ]);
        $both = FinanceCategory::factory()->create([
            'slug' => 'both',
            'type' => 'both',
            'is_active' => true,
        ]);
        $inactiveExpense = FinanceCategory::factory()->create([
            'slug' => 'inactive-expense',
            'type' => 'expense',
            'is_active' => false,
        ]);

        $this->assertEqualsCanonicalizing(
            [$activeIncome->id, $both->id],
            FinanceCategory::active()->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$activeIncome->id, $both->id],
            FinanceCategory::income()->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$both->id, $inactiveExpense->id],
            FinanceCategory::expense()->pluck('id')->all(),
        );
    }

    public function test_finance_invoice_scopes_filter_status_and_dates(): void
    {
        $paidInside = FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-PAID-IN',
            'status' => 'paid',
            'paid_at' => now()->subDays(2),
            'due_at' => now()->subDays(5),
        ]);
        $paidOutside = FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-PAID-OUT',
            'status' => 'paid',
            'paid_at' => now()->subDays(10),
        ]);
        $issuedReceivable = FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-ISSUED',
            'status' => 'issued',
            'paid_at' => null,
            'due_at' => now()->addDays(3),
        ]);
        $overdue = FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-OVERDUE',
            'status' => 'overdue',
            'paid_at' => null,
            'due_at' => now()->subDay(),
        ]);
        FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-VOID',
            'status' => 'void',
        ]);

        $this->assertEqualsCanonicalizing(
            [$paidInside->id, $paidOutside->id, $issuedReceivable->id, $overdue->id],
            FinanceInvoice::notVoid()->pluck('id')->all(),
        );
        $this->assertSame(
            [$paidInside->id],
            FinanceInvoice::paidBetween(now()->subDays(3), now())->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$issuedReceivable->id, $overdue->id],
            FinanceInvoice::receivable()->pluck('id')->all(),
        );
        $this->assertSame(
            [$overdue->id],
            FinanceInvoice::overdue()->pluck('id')->all(),
        );
    }

    public function test_finance_expense_scopes_filter_status_and_dates(): void
    {
        $paidInside = FinanceExpense::factory()->create([
            'status' => 'paid',
            'paid_at' => now()->subDays(2),
            'due_at' => now()->subDays(5),
        ]);
        $paidOutside = FinanceExpense::factory()->create([
            'status' => 'paid',
            'paid_at' => now()->subDays(10),
        ]);
        $unpaidPayable = FinanceExpense::factory()->create([
            'status' => 'unpaid',
            'paid_at' => null,
            'due_at' => now()->addDays(3),
        ]);
        $overdue = FinanceExpense::factory()->create([
            'status' => 'overdue',
            'paid_at' => null,
            'due_at' => now()->subDay(),
        ]);
        FinanceExpense::factory()->create([
            'status' => 'void',
        ]);

        $this->assertEqualsCanonicalizing(
            [$paidInside->id, $paidOutside->id, $unpaidPayable->id, $overdue->id],
            FinanceExpense::notVoid()->pluck('id')->all(),
        );
        $this->assertSame(
            [$paidInside->id],
            FinanceExpense::paidBetween(now()->subDays(3), now())->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$unpaidPayable->id, $overdue->id],
            FinanceExpense::payable()->pluck('id')->all(),
        );
        $this->assertSame(
            [$overdue->id],
            FinanceExpense::overdue()->pluck('id')->all(),
        );
    }
}
