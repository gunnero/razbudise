<?php

namespace App\Filament\Resources\AdBookingRequests\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdBookingRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Booking')
                    ->schema([
                        TextInput::make('reference')->required()->maxLength(255)->unique(ignoreRecord: true),
                        Select::make('ad_customer_id')
                            ->label('Customer')
                            ->relationship('customer', 'company_name')
                            ->searchable()
                            ->preload(),
                        Select::make('discount_code_id')
                            ->label('Discount code')
                            ->relationship('discountCode', 'code')
                            ->searchable()
                            ->preload(),
                        Select::make('status')
                            ->required()
                            ->options([
                                'pending_review' => 'Pending review',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                                'published' => 'Published',
                                'cancelled' => 'Cancelled',
                            ])
                            ->default('pending_review')
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('payment_status')
                            ->required()
                            ->options([
                                'unpaid' => 'Unpaid',
                                'pending' => 'Pending',
                                'paid' => 'Paid',
                                'refunded' => 'Refunded',
                            ])
                            ->default('unpaid')
                            ->disabled()
                            ->dehydrated(false),
                        DateTimePicker::make('hold_expires_at')->seconds(false),
                    ])
                    ->columns(2),
                Section::make('Billing and contact')
                    ->schema([
                        TextInput::make('billing_company_name')->required()->maxLength(255),
                        TextInput::make('billing_tax_number')->required()->maxLength(255),
                        TextInput::make('billing_registration_number')->maxLength(255),
                        TextInput::make('billing_address')->required()->maxLength(255),
                        TextInput::make('billing_city')->required()->maxLength(255),
                        TextInput::make('billing_country')->required()->maxLength(2)->default('MK'),
                        TextInput::make('contact_name')->required()->maxLength(255),
                        TextInput::make('contact_email')->required()->email()->maxLength(255),
                        TextInput::make('contact_phone')->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Totals')
                    ->schema([
                        TextInput::make('subtotal_denars')->required()->integer()->minValue(0)->default(0)->disabled()->dehydrated(false),
                        TextInput::make('discount_denars')->required()->integer()->minValue(0)->default(0)->disabled()->dehydrated(false),
                        TextInput::make('tax_rate')->required()->numeric()->minValue(0)->default(0)->disabled()->dehydrated(false),
                        TextInput::make('tax_denars')->required()->integer()->minValue(0)->default(0)->disabled()->dehydrated(false),
                        TextInput::make('total_denars')->required()->integer()->minValue(0)->default(0)->disabled()->dehydrated(false),
                    ])
                    ->columns(5),
                Section::make('Payment')
                    ->schema([
                        TextInput::make('payment_method')->maxLength(255)->disabled()->dehydrated(false),
                        TextInput::make('payment_reference')->maxLength(255)->disabled()->dehydrated(false),
                        DateTimePicker::make('paid_at')->seconds(false)->disabled()->dehydrated(false),
                        TextInput::make('external_payment_provider')->maxLength(255)->disabled()->dehydrated(false),
                        TextInput::make('external_payment_id')->maxLength(255)->disabled()->dehydrated(false),
                    ])
                    ->columns(2),
                Section::make('Notes')
                    ->schema([
                        Textarea::make('customer_notes')->rows(4),
                        Textarea::make('admin_notes')->rows(4),
                    ])
                    ->columns(2),
            ]);
    }
}
