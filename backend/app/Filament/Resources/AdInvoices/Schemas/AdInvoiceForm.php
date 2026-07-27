<?php

namespace App\Filament\Resources\AdInvoices\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice')
                    ->schema([
                        TextInput::make('number')->required()->maxLength(255)->unique(ignoreRecord: true),
                        Select::make('ad_booking_request_id')
                            ->label('Booking reference')
                            ->relationship('bookingRequest', 'reference')
                            ->required()
                            ->unique(table: 'ad_invoices', column: 'ad_booking_request_id', ignoreRecord: true)
                            ->searchable()
                            ->preload(),
                        Select::make('type')
                            ->required()
                            ->options([
                                'proforma' => 'Proforma',
                                'invoice' => 'Invoice',
                            ])
                            ->default('proforma'),
                        Select::make('status')
                            ->required()
                            ->options([
                                'draft' => 'Draft',
                                'issued' => 'Issued',
                                'paid' => 'Paid',
                                'void' => 'Void',
                            ])
                            ->default('issued'),
                        TextInput::make('document_path')
                            ->maxLength(255)
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Generated automatically in private storage when the proforma is issued.'),
                    ])
                    ->columns(2),
                Section::make('Totals')
                    ->schema([
                        TextInput::make('subtotal_denars')->required()->integer()->minValue(0)->default(0),
                        TextInput::make('discount_denars')->required()->integer()->minValue(0)->default(0),
                        TextInput::make('tax_rate')->required()->numeric()->minValue(0)->default(0),
                        TextInput::make('tax_denars')->required()->integer()->minValue(0)->default(0),
                        TextInput::make('total_denars')->required()->integer()->minValue(0)->default(0),
                    ])
                    ->columns(5),
                Section::make('Dates')
                    ->schema([
                        DateTimePicker::make('issued_at')->seconds(false),
                        DateTimePicker::make('due_at')->seconds(false),
                        DateTimePicker::make('paid_at')->seconds(false),
                    ])
                    ->columns(3),
            ]);
    }
}
