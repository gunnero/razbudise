<?php

namespace App\Filament\Resources\AdCustomers\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdCustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company and billing')
                    ->schema([
                        TextInput::make('company_name')->required()->maxLength(255),
                        TextInput::make('tax_number')->required()->maxLength(255),
                        TextInput::make('registration_number')->maxLength(255),
                        TextInput::make('billing_address')->required()->maxLength(255),
                        TextInput::make('billing_city')->required()->maxLength(255),
                        TextInput::make('billing_country')->required()->maxLength(2)->default('MK'),
                    ])
                    ->columns(2),
                Section::make('Contact')
                    ->schema([
                        TextInput::make('contact_name')->required()->maxLength(255),
                        TextInput::make('contact_email')->required()->email()->maxLength(255),
                        TextInput::make('contact_phone')->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Account links and status')
                    ->schema([
                        Select::make('advertiser_id')
                            ->label('Advertiser')
                            ->relationship('advertiser', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('user_id')
                            ->label('User')
                            ->relationship('user', 'email')
                            ->searchable()
                            ->preload(),
                        Select::make('status')
                            ->required()
                            ->options([
                                'pending' => 'Pending',
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                                'former' => 'Former customer',
                            ])
                            ->default('pending'),
                        DateTimePicker::make('last_active_at')->seconds(false),
                        DateTimePicker::make('dashboard_deactivated_at')->seconds(false),
                        DateTimePicker::make('former_customer_at')->seconds(false),
                        Textarea::make('admin_notes')->rows(4)->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
