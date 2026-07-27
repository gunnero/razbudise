<?php

namespace App\Filament\Resources\WordpressImportMappings\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class WordpressImportMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('wordpress_id')
                    ->sortable(),
                TextColumn::make('source_slug')
                    ->searchable(),
                TextColumn::make('target_type')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? Str::afterLast($state, '\\') : null)
                    ->sortable(),
                TextColumn::make('target_id')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source_type')
                    ->options([
                        'author' => 'Author',
                        'category' => 'Category',
                        'tag' => 'Tag',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
