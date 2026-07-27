<?php

namespace App\Filament\Resources\AdBookingRequests\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AdBookingRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('reference'),
                TextEntry::make('customer.company_name')->label('Customer')->placeholder('-'),
                TextEntry::make('billing_company_name'),
                TextEntry::make('contact_email'),
                TextEntry::make('status')->badge(),
                TextEntry::make('payment_status')->badge(),
                TextEntry::make('total_denars')->numeric(),
                TextEntry::make('hold_expires_at')->dateTime()->placeholder('-'),
                TextEntry::make('payment_reference')->placeholder('-'),
                TextEntry::make('paid_at')->dateTime()->placeholder('-'),
                TextEntry::make('rejection_reason')->placeholder('-')->columnSpanFull(),
                TextEntry::make('admin_notes')->placeholder('-')->columnSpanFull(),
                TextEntry::make('customer_notes')->placeholder('-')->columnSpanFull(),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('updated_at')->dateTime(),
            ]);
    }
}
