<?php

namespace App\Filament\Resources\AdInvoices\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('bookingRequest.reference')->label('Booking reference')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('subtotal_denars')->numeric()->sortable(),
                TextColumn::make('discount_denars')->numeric()->sortable(),
                TextColumn::make('tax_denars')->numeric()->sortable(),
                TextColumn::make('total_denars')->numeric()->sortable(),
                TextColumn::make('issued_at')->dateTime()->sortable(),
                TextColumn::make('due_at')->dateTime()->sortable(),
                TextColumn::make('paid_at')->dateTime()->sortable(),
                TextColumn::make('document_path')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'issued' => 'Issued',
                        'paid' => 'Paid',
                        'void' => 'Void',
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
