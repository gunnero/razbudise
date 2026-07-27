<?php

namespace App\Filament\Resources\FinanceInvoices\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class FinanceInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice')
                    ->schema([
                        Select::make('finance_category_id')
                            ->label('Category')
                            ->relationship('category', 'name', fn (Builder $query): Builder => $query->income())
                            ->searchable()
                            ->preload(),
                        TextInput::make('number')->required()->maxLength(255)->unique(ignoreRecord: true),
                        Select::make('type')
                            ->required()
                            ->options([
                                'proforma' => 'Proforma',
                                'invoice' => 'Invoice',
                            ])
                            ->default('invoice'),
                        Select::make('status')
                            ->required()
                            ->options([
                                'draft' => 'Draft',
                                'issued' => 'Issued',
                                'paid' => 'Paid',
                                'overdue' => 'Overdue',
                                'void' => 'Void',
                            ])
                            ->default('draft'),
                        TextInput::make('document_path')
                            ->maxLength(255)
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Generated in private local storage.'),
                    ])
                    ->columns(2),
                Section::make('Customer')
                    ->schema([
                        TextInput::make('customer_name')->required()->maxLength(255),
                        TextInput::make('customer_tax_number')->maxLength(255),
                        TextInput::make('customer_registration_number')->maxLength(255),
                        TextInput::make('customer_email')->email()->maxLength(255),
                        Textarea::make('customer_address')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Amounts')
                    ->schema([
                        TextInput::make('subtotal_denars')->required()->integer()->minValue(0)->default(0),
                        TextInput::make('discount_denars')->required()->integer()->minValue(0)->default(0),
                        TextInput::make('tax_rate')->required()->numeric()->minValue(0)->default(0),
                        TextInput::make('tax_denars')->required()->integer()->minValue(0)->default(0),
                        TextInput::make('total_denars')->required()->integer()->minValue(0)->default(0),
                    ])
                    ->columns(5),
                Section::make('Dates and payment')
                    ->schema([
                        DateTimePicker::make('issued_at')->seconds(false),
                        DateTimePicker::make('due_at')->seconds(false),
                        DateTimePicker::make('paid_at')->seconds(false),
                        TextInput::make('payment_method')->maxLength(255),
                        TextInput::make('payment_reference')->maxLength(255),
                    ])
                    ->columns(3),
                Section::make('Details')
                    ->schema([
                        Textarea::make('description')->required()->columnSpanFull(),
                        Textarea::make('notes')->columnSpanFull(),
                    ]),
            ]);
    }
}
