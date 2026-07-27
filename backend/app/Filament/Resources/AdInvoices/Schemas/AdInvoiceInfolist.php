<?php

namespace App\Filament\Resources\AdInvoices\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AdInvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('number'),
                TextEntry::make('bookingRequest.reference')->label('Booking reference'),
                TextEntry::make('status')->badge(),
                TextEntry::make('type')->badge(),
                TextEntry::make('subtotal_denars')->numeric(),
                TextEntry::make('discount_denars')->numeric(),
                TextEntry::make('tax_denars')->numeric(),
                TextEntry::make('total_denars')->numeric(),
                TextEntry::make('issued_at')->dateTime()->placeholder('-'),
                TextEntry::make('due_at')->dateTime()->placeholder('-'),
                TextEntry::make('paid_at')->dateTime()->placeholder('-'),
                TextEntry::make('document_path')->placeholder('-')->columnSpanFull(),
            ]);
    }
}
