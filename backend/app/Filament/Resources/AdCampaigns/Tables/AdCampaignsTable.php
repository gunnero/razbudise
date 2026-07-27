<?php

namespace App\Filament\Resources\AdCampaigns\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdCampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('advertiser.name')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('start_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('end_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('priority')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('impressions')
                    ->label('Impressions')
                    ->state(fn ($record): string => $record->impressionCapProgress()),
                TextColumn::make('clicks')
                    ->label('Clicks')
                    ->state(fn ($record): string => $record->clickCapProgress()),
                TextColumn::make('ctr')
                    ->label('CTR')
                    ->state(fn ($record): string => $record->ctrPercent().'%'),
                TextColumn::make('daily_impression_cap')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('report_impressions')
                    ->label('30d impressions')
                    ->state(fn ($record): int => $record->impressions()->where('occurred_at', '>=', now()->subDays(30))->count())
                    ->sortable(query: fn ($query) => $query),
                TextColumn::make('report_clicks')
                    ->label('30d clicks')
                    ->state(fn ($record): int => $record->clicks()->where('occurred_at', '>=', now()->subDays(30))->count())
                    ->sortable(query: fn ($query) => $query),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'active' => 'Active',
                        'paused' => 'Paused',
                        'completed' => 'Completed',
                        'archived' => 'Archived',
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
