<?php

namespace App\Filament\Resources\FinanceInvoices\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class FinanceInvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('number'),
                TextEntry::make('category.name')->label('Category')->placeholder('-'),
                TextEntry::make('type')->badge(),
                TextEntry::make('status')->badge(),
                TextEntry::make('customer_name'),
                TextEntry::make('customer_tax_number')->placeholder('-'),
                TextEntry::make('customer_registration_number')->placeholder('-'),
                TextEntry::make('customer_email')->placeholder('-'),
                TextEntry::make('customer_address')->placeholder('-')->columnSpanFull(),
                TextEntry::make('description')->columnSpanFull(),
                TextEntry::make('subtotal_denars')->numeric(),
                TextEntry::make('discount_denars')->numeric(),
                TextEntry::make('tax_rate')->numeric(),
                TextEntry::make('tax_denars')->numeric(),
                TextEntry::make('total_denars')->numeric(),
                TextEntry::make('issued_at')->dateTime()->placeholder('-'),
                TextEntry::make('due_at')->dateTime()->placeholder('-'),
                TextEntry::make('paid_at')->dateTime()->placeholder('-'),
                TextEntry::make('payment_method')->placeholder('-'),
                TextEntry::make('payment_reference')->placeholder('-'),
                TextEntry::make('document_path')->placeholder('-')->columnSpanFull(),
                TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
            ]);
    }
}
