@php
    $cards = [
        ['label' => 'Paid income this month', 'value' => $summary['paid_income_denars'] ?? 0],
        ['label' => 'Paid expenses this month', 'value' => $summary['paid_expenses_denars'] ?? 0],
        ['label' => 'Net result this month', 'value' => $summary['net_result_denars'] ?? 0],
        ['label' => 'Unpaid invoices', 'value' => $summary['unpaid_invoices_denars'] ?? 0],
        ['label' => 'Overdue invoices', 'value' => $summary['overdue_invoices_denars'] ?? 0],
        ['label' => 'Unpaid expenses', 'value' => $summary['unpaid_expenses_denars'] ?? 0],
        ['label' => 'Overdue expenses', 'value' => $summary['overdue_expenses_denars'] ?? 0],
    ];

    $tables = [
        ['title' => 'Recent income', 'rows' => $recentIncome, 'empty' => 'No income records yet.'],
        ['title' => 'Recent expenses', 'rows' => $recentExpenses, 'empty' => 'No expense records yet.'],
        ['title' => 'Overdue invoices', 'rows' => $overdueInvoices, 'empty' => 'No overdue invoices.'],
        ['title' => 'Upcoming expenses', 'rows' => $upcomingExpenses, 'empty' => 'No upcoming expenses.'],
    ];
@endphp

<x-filament-panels::page>
    <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <label class="space-y-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Date from</span>
                <input
                    type="date"
                    wire:model="dateFrom"
                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Date to</span>
                <input
                    type="date"
                    wire:model="dateTo"
                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Source</span>
                <select
                    wire:model="source"
                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >
                    <option value="all">All sources</option>
                    <option value="ad_invoice">Ad invoices</option>
                    <option value="finance_invoice">Finance invoices</option>
                    <option value="finance_expense">Expenses</option>
                </select>
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Status</span>
                <select
                    wire:model="status"
                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >
                    <option value="all">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="issued">Issued</option>
                    <option value="unpaid">Unpaid</option>
                    <option value="paid">Paid</option>
                    <option value="overdue">Overdue</option>
                    <option value="void">Void</option>
                </select>
            </label>
            <label class="space-y-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Category</span>
                <select
                    wire:model="financeCategoryId"
                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >
                    <option value="all">All categories</option>
                    @foreach ($categoryOptions as $categoryId => $categoryName)
                        <option value="{{ $categoryId }}">{{ $categoryName }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="mt-4">
            <button
                type="button"
                wire:click="applyFilters"
                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500"
            >
                Apply filters
            </button>
        </div>
    </section>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as $card)
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                <p class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format((int) $card['value']) }} ден.</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        @foreach ($tables as $table)
            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $table['title'] }}</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-4">Number</th>
                                <th class="py-2 pr-4">Party</th>
                                <th class="py-2 pr-4">Category</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Due</th>
                                <th class="py-2">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse (($table['rows'] ?? []) as $row)
                                <tr>
                                    <td class="py-3 pr-4">{{ $row['number'] ?? '-' }}</td>
                                    <td class="py-3 pr-4">{{ $row['party_name'] ?? '-' }}</td>
                                    <td class="py-3 pr-4">{{ $row['category'] ?? '-' }}</td>
                                    <td class="py-3 pr-4">{{ $row['status'] ?? '-' }}</td>
                                    <td class="py-3 pr-4">{{ $row['due_date'] ?? '-' }}</td>
                                    <td class="py-3">{{ number_format((int) ($row['total_denars'] ?? 0)) }} ден.</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="py-3 text-gray-500 dark:text-gray-400" colspan="6">{{ $table['empty'] }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
