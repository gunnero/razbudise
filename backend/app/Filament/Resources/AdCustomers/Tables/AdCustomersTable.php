<?php

namespace App\Filament\Resources\AdCustomers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdCustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company_name')->searchable()->sortable(),
                TextColumn::make('tax_number')->searchable(),
                TextColumn::make('contact_name')->searchable(),
                TextColumn::make('contact_email')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('advertiser.name')->label('Advertiser')->searchable()->placeholder('-'),
                TextColumn::make('user.email')->label('User')->searchable()->placeholder('-'),
                TextColumn::make('last_active_at')->dateTime()->sortable(),
                TextColumn::make('former_customer_at')->dateTime()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'former' => 'Former customer',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
