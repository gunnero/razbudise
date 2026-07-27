<?php

namespace App\Filament\Resources\FinanceExpenses\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class FinanceExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('category.name')->label('Category')->placeholder('-'),
                TextEntry::make('status')->badge(),
                TextEntry::make('vendor_name'),
                TextEntry::make('vendor_tax_number')->placeholder('-'),
                TextEntry::make('vendor_registration_number')->placeholder('-'),
                TextEntry::make('description')->columnSpanFull(),
                TextEntry::make('expense_date')->date(),
                TextEntry::make('due_at')->dateTime()->placeholder('-'),
                TextEntry::make('paid_at')->dateTime()->placeholder('-'),
                TextEntry::make('subtotal_denars')->numeric(),
                TextEntry::make('tax_rate')->numeric(),
                TextEntry::make('tax_denars')->numeric(),
                TextEntry::make('total_denars')->numeric(),
                TextEntry::make('payment_method')->placeholder('-'),
                TextEntry::make('payment_reference')->placeholder('-'),
                TextEntry::make('adCampaign.name')->label('Ad campaign')->placeholder('-'),
                TextEntry::make('adInvoice.number')->label('Ad invoice')->placeholder('-'),
                TextEntry::make('financeInvoice.number')->label('Finance invoice')->placeholder('-'),
                TextEntry::make('document_path')->placeholder('-')->columnSpanFull(),
                TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
            ]);
    }
}
