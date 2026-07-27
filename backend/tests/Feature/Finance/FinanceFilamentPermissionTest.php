<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\FinanceCategories\FinanceCategoryResource;
use App\Filament\Resources\FinanceExpenses\FinanceExpenseResource;
use App\Filament\Resources\FinanceExpenses\Pages\EditFinanceExpense;
use App\Filament\Resources\FinanceExpenses\Pages\ListFinanceExpenses;
use App\Filament\Resources\FinanceExpenses\Tables\FinanceExpensesTable;
use App\Filament\Resources\FinanceInvoices\FinanceInvoiceResource;
use App\Filament\Resources\FinanceInvoices\Pages\EditFinanceInvoice;
use App\Filament\Resources\FinanceInvoices\Pages\ListFinanceInvoices;
use App\Filament\Resources\FinanceInvoices\Tables\FinanceInvoicesTable;
use App\Models\FinanceExpense;
use App\Models\FinanceInvoice;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceFilamentPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_resources_are_visible_to_admins_only_by_default(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $editor = User::factory()->create();
        $editorRole = Role::query()->where('name', 'editor')->firstOrFail();
        $editor->roles()->attach($editorRole);

        $this->actingAs($admin);
        $this->assertTrue(FinanceCategoryResource::canAccess());
        $this->assertTrue(FinanceInvoiceResource::canAccess());
        $this->assertTrue(FinanceExpenseResource::canAccess());

        $this->actingAs($editor);
        $this->assertFalse(FinanceCategoryResource::canAccess());
        $this->assertFalse(FinanceInvoiceResource::canAccess());
        $this->assertFalse(FinanceExpenseResource::canAccess());
    }

    public function test_custom_finance_invoice_actions_require_finance_manage_permission(): void
    {
        [$admin, $editor] = $this->financeUsers();

        $this->actingAs($admin);

        foreach (['downloadDocument', 'generateDocument', 'markPaid', 'void'] as $actionName) {
            $this->assertTrue($this->invoiceAction($actionName)->isAuthorized(), $actionName);
        }

        $this->actingAs($editor);

        foreach (['downloadDocument', 'generateDocument', 'markPaid', 'void'] as $actionName) {
            $this->assertFalse($this->invoiceAction($actionName)->isAuthorized(), $actionName);
        }
    }

    public function test_custom_finance_expense_mutating_actions_require_finance_manage_permission(): void
    {
        [$admin, $editor] = $this->financeUsers();

        $this->actingAs($admin);

        foreach (['markPaid', 'markUnpaid', 'void'] as $actionName) {
            $this->assertTrue($this->expenseAction($actionName)->isAuthorized(), $actionName);
        }

        $this->actingAs($editor);

        foreach (['markPaid', 'markUnpaid', 'void'] as $actionName) {
            $this->assertFalse($this->expenseAction($actionName)->isAuthorized(), $actionName);
        }
    }

    public function test_view_only_finance_user_cannot_download_private_expense_documents(): void
    {
        [$admin, $editor] = $this->financeUsers();
        Storage::fake('local');
        Storage::disk('local')->put('finance-expenses/vendor-receipt.pdf', 'private receipt');
        $expense = FinanceExpense::factory()->create([
            'document_path' => 'finance-expenses/vendor-receipt.pdf',
        ]);

        $this->actingAs($admin);
        $this->assertTrue($this->expenseAction('downloadDocument')->record($expense)->isVisible());
        $this->assertTrue($this->expenseAction('downloadDocument')->record($expense)->isAuthorized());

        $this->actingAs($editor);
        $this->assertFalse($this->expenseAction('downloadDocument')->record($expense)->isVisible());
        $this->assertFalse($this->expenseAction('downloadDocument')->record($expense)->isAuthorized());
    }

    public function test_voided_finance_records_cannot_be_marked_paid_or_unpaid(): void
    {
        [$admin] = $this->financeUsers();
        $this->actingAs($admin);

        $invoice = FinanceInvoice::factory()->create(['status' => 'void']);
        $expense = FinanceExpense::factory()->create(['status' => 'void']);

        $this->assertFalse($this->invoiceAction('markPaid')->record($invoice)->isVisible());
        $this->assertFalse($this->expenseAction('markPaid')->record($expense)->isVisible());
        $this->assertFalse($this->expenseAction('markUnpaid')->record($expense)->isVisible());
    }

    public function test_finance_resources_do_not_expose_hard_delete_bulk_actions(): void
    {
        [$admin] = $this->financeUsers();
        $this->actingAs($admin);

        Livewire::test(ListFinanceInvoices::class)
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(ListFinanceExpenses::class)
            ->assertTableBulkActionDoesNotExist('delete');
    }

    public function test_finance_edit_pages_do_not_expose_hard_delete_actions(): void
    {
        [$admin] = $this->financeUsers();
        $this->actingAs($admin);

        $invoice = FinanceInvoice::factory()->create();
        $expense = FinanceExpense::factory()->create();

        Livewire::test(EditFinanceInvoice::class, ['record' => $invoice->id])
            ->assertActionDoesNotExist('delete');

        Livewire::test(EditFinanceExpense::class, ['record' => $expense->id])
            ->assertActionDoesNotExist('delete');
    }

    /**
     * @return array{User, User}
     */
    private function financeUsers(): array
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $editor = User::factory()->create();
        $editorRole = Role::query()->where('name', 'editor')->firstOrFail();
        $editor->roles()->attach($editorRole);

        return [$admin, $editor];
    }

    private function invoiceAction(string $name): Action
    {
        return collect(FinanceInvoicesTable::financeRecordActions())
            ->firstOrFail(fn (Action $action): bool => $action->getName() === $name);
    }

    private function expenseAction(string $name): Action
    {
        return collect(FinanceExpensesTable::financeRecordActions())
            ->firstOrFail(fn (Action $action): bool => $action->getName() === $name);
    }
}
