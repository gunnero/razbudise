<?php

namespace App\Filament\Resources\FinanceExpenses\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class FinanceExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Expense')
                    ->schema([
                        Select::make('finance_category_id')
                            ->label('Category')
                            ->relationship('category', 'name', fn (Builder $query): Builder => $query->expense())
                            ->searchable()
                            ->preload(),
                        Select::make('status')
                            ->required()
                            ->options([
                                'draft' => 'Draft',
                                'unpaid' => 'Unpaid',
                                'paid' => 'Paid',
                                'overdue' => 'Overdue',
                                'void' => 'Void',
                            ])
                            ->default('unpaid'),
                        DatePicker::make('expense_date')->required()->default(now()),
                        DateTimePicker::make('due_at')->seconds(false),
                        DateTimePicker::make('paid_at')->seconds(false),
                    ])
                    ->columns(3),
                Section::make('Vendor')
                    ->schema([
                        TextInput::make('vendor_name')->required()->maxLength(255),
                        TextInput::make('vendor_tax_number')->maxLength(255),
                        TextInput::make('vendor_registration_number')->maxLength(255),
                    ])
                    ->columns(3),
                Section::make('Amounts')
                    ->schema([
                        TextInput::make('subtotal_denars')->required()->integer()->minValue(0)->default(0),
                        TextInput::make('tax_rate')->required()->numeric()->minValue(0)->default(0),
                        TextInput::make('tax_denars')->required()->integer()->minValue(0)->default(0),
                        TextInput::make('total_denars')->required()->integer()->minValue(0)->default(0),
                    ])
                    ->columns(4),
                Section::make('Payment and document')
                    ->schema([
                        TextInput::make('payment_method')->maxLength(255),
                        TextInput::make('payment_reference')->maxLength(255),
                        FileUpload::make('document_path')
                            ->disk('local')
                            ->directory('finance-expenses')
                            ->preventFilePathTampering(),
                    ])
                    ->columns(2),
                Section::make('Relationships')
                    ->schema([
                        Select::make('related_ad_campaign_id')
                            ->label('Ad campaign')
                            ->relationship('adCampaign', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('related_ad_invoice_id')
                            ->label('Ad invoice')
                            ->relationship('adInvoice', 'number')
                            ->searchable()
                            ->preload(),
                        Select::make('related_finance_invoice_id')
                            ->label('Finance invoice')
                            ->relationship('financeInvoice', 'number')
                            ->searchable()
                            ->preload(),
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
