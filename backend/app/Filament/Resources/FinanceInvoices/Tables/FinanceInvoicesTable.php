<?php

namespace App\Filament\Resources\FinanceInvoices\Tables;

use App\Models\FinanceInvoice;
use App\Services\Finance\FinanceInvoiceDocumentService;
use App\Services\Finance\FinanceReportService;
use App\Support\CsvExport;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('category.name')->label('Category')->searchable()->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('customer_name')->searchable()->sortable(),
                TextColumn::make('total_denars')->numeric()->sortable(),
                TextColumn::make('issued_at')->dateTime()->sortable(),
                TextColumn::make('due_at')->dateTime()->sortable(),
                TextColumn::make('paid_at')->dateTime()->sortable(),
                TextColumn::make('document_path')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'proforma' => 'Proforma',
                    'invoice' => 'Invoice',
                ]),
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'issued' => 'Issued',
                    'paid' => 'Paid',
                    'overdue' => 'Overdue',
                    'void' => 'Void',
                ]),
            ])
            ->headerActions([
                Action::make('exportIncome')
                    ->label('Export income CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->authorize(fn (): bool => self::canExportFinance())
                    ->visible(fn (): bool => self::canExportFinance())
                    ->action(fn (): StreamedResponse => self::downloadIncomeCsv()),
            ])
            ->recordActions(self::financeRecordActions())
            ->toolbarActions([]);
    }

    public static function financeRecordActions(): array
    {
        return [
            ViewAction::make(),
            EditAction::make(),
            Action::make('downloadDocument')
                ->label('Download document')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->authorize(fn (): bool => self::canManageFinance())
                ->visible(fn (): bool => self::canManageFinance())
                ->action(fn (FinanceInvoice $record) => app(FinanceInvoiceDocumentService::class)->downloadResponse($record)),
            Action::make('generateDocument')
                ->label('Generate document')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->authorize(fn (): bool => self::canManageFinance())
                ->visible(fn (): bool => self::canManageFinance())
                ->action(fn (FinanceInvoice $record): FinanceInvoice => app(FinanceInvoiceDocumentService::class)->storeDocument($record)),
            Action::make('markPaid')
                ->label('Mark paid')
                ->icon(Heroicon::OutlinedBanknotes)
                ->authorize(fn (): bool => self::canManageFinance())
                ->visible(fn (FinanceInvoice $record): bool => ! in_array($record->status, ['paid', 'void'], true) && self::canManageFinance())
                ->action(fn (FinanceInvoice $record): bool => $record->forceFill([
                    'status' => 'paid',
                    'paid_at' => $record->paid_at ?? now(),
                ])->save()),
            Action::make('void')
                ->label('Void')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->requiresConfirmation()
                ->authorize(fn (): bool => self::canManageFinance())
                ->visible(fn (FinanceInvoice $record): bool => $record->status !== 'void' && self::canManageFinance())
                ->action(fn (FinanceInvoice $record): bool => $record->forceFill(['status' => 'void'])->save()),
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

    private static function downloadIncomeCsv(): StreamedResponse
    {
        $reports = app(FinanceReportService::class);

        return response()->streamDownload(function () use ($reports): void {
            foreach (CsvExport::lines($reports->incomeExportHeaders(), $reports->incomeExportRows()) as $line) {
                echo $line."\n";
            }
        }, 'finance-income-'.now()->format('Y-m').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
