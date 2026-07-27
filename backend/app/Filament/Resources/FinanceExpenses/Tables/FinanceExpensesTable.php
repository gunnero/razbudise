<?php

namespace App\Filament\Resources\FinanceExpenses\Tables;

use App\Models\FinanceExpense;
use App\Services\Finance\FinanceReportService;
use App\Support\CsvExport;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vendor_name')->searchable()->sortable(),
                TextColumn::make('category.name')->label('Category')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('expense_date')->date()->sortable(),
                TextColumn::make('due_at')->dateTime()->sortable(),
                TextColumn::make('paid_at')->dateTime()->sortable(),
                TextColumn::make('total_denars')->numeric()->sortable(),
                TextColumn::make('adCampaign.name')->label('Ad campaign')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('adInvoice.number')->label('Ad invoice')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('financeInvoice.number')->label('Finance invoice')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('document_path')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'unpaid' => 'Unpaid',
                    'paid' => 'Paid',
                    'overdue' => 'Overdue',
                    'void' => 'Void',
                ]),
            ])
            ->headerActions([
                Action::make('exportExpenses')
                    ->label('Export expenses CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->authorize(fn (): bool => self::canExportFinance())
                    ->visible(fn (): bool => self::canExportFinance())
                    ->action(fn (): StreamedResponse => self::downloadExpensesCsv()),
            ])
            ->recordActions(self::financeRecordActions())
            ->toolbarActions([]);
    }

    public static function financeRecordActions(): array
    {
        return [
            ViewAction::make(),
            EditAction::make(),
            Action::make('markPaid')
                ->label('Mark paid')
                ->icon(Heroicon::OutlinedBanknotes)
                ->authorize(fn (): bool => self::canManageFinance())
                ->visible(fn (FinanceExpense $record): bool => ! in_array($record->status, ['paid', 'void'], true) && self::canManageFinance())
                ->action(fn (FinanceExpense $record): bool => $record->forceFill([
                    'status' => 'paid',
                    'paid_at' => $record->paid_at ?? now(),
                ])->save()),
            Action::make('markUnpaid')
                ->label('Mark unpaid')
                ->icon(Heroicon::OutlinedClock)
                ->authorize(fn (): bool => self::canManageFinance())
                ->visible(fn (FinanceExpense $record): bool => ! in_array($record->status, ['unpaid', 'void'], true) && self::canManageFinance())
                ->action(fn (FinanceExpense $record): bool => $record->forceFill([
                    'status' => 'unpaid',
                    'paid_at' => null,
                ])->save()),
            Action::make('void')
                ->label('Void')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->requiresConfirmation()
                ->authorize(fn (): bool => self::canManageFinance())
                ->visible(fn (FinanceExpense $record): bool => $record->status !== 'void' && self::canManageFinance())
                ->action(fn (FinanceExpense $record): bool => $record->forceFill(['status' => 'void'])->save()),
            Action::make('downloadDocument')
                ->label('Download document')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->authorize(fn (): bool => self::canManageFinance())
                ->visible(fn (FinanceExpense $record): bool => self::canManageFinance() && filled($record->document_path) && Storage::disk('local')->exists($record->document_path))
                ->action(fn (FinanceExpense $record) => Storage::disk('local')->download($record->document_path)),
        ];
    }

    private static function canManageFinance(): bool
    {
        return auth()->user()?->hasPermission('finance.manage') ?? false;
    }

    private static function canExportFinance(): bool
    {
        return auth()->user()?->hasPermission('finance.export') ?? false;
    }

    private static function downloadExpensesCsv(): StreamedResponse
    {
        $reports = app(FinanceReportService::class);

        return response()->streamDownload(function () use ($reports): void {
            foreach (CsvExport::lines($reports->expenseExportHeaders(), $reports->expenseExportRows()) as $line) {
                echo $line."\n";
            }
        }, 'finance-expenses-'.now()->format('Y-m').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
