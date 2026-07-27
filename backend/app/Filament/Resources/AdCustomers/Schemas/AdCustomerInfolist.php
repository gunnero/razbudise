<?php

namespace App\Filament\Resources\AdCustomers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AdCustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('company_name'),
                TextEntry::make('tax_number'),
                TextEntry::make('registration_number')->placeholder('-'),
                TextEntry::make('billing_address'),
                TextEntry::make('billing_city'),
                TextEntry::make('billing_country'),
                TextEntry::make('contact_name'),
                TextEntry::make('contact_email'),
                TextEntry::make('contact_phone')->placeholder('-'),
                TextEntry::make('status')->badge(),
                TextEntry::make('advertiser.name')->label('Advertiser')->placeholder('-'),
                TextEntry::make('user.email')->label('User')->placeholder('-'),
                TextEntry::make('last_active_at')->dateTime()->placeholder('-'),
                TextEntry::make('former_customer_at')->dateTime()->placeholder('-'),
                TextEntry::make('admin_notes')->placeholder('-')->columnSpanFull(),
            ]);
    }
}
