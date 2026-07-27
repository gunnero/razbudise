<?php

namespace App\Filament\Resources\AdPrices\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AdPricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('zone.name')->label('Zone')->searchable()->sortable(),
                TextColumn::make('duration_weeks')->numeric()->sortable(),
                TextColumn::make('creative_type')->badge()->sortable(),
                TextColumn::make('price_denars')->numeric()->sortable(),
                TextColumn::make('political_multiplier')->numeric()->sortable(),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('ad_zone_id')->label('Zone')->relationship('zone', 'name')->searchable()->preload(),
                SelectFilter::make('creative_type')->options([
                    'image' => 'Image',
                    'html' => 'HTML',
                    'script' => 'Script',
                    'native' => 'Native',
                ]),
                TernaryFilter::make('is_active'),
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
