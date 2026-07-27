<?php

namespace App\Filament\Resources\WordpressImportRecords\Tables;

use App\Models\WordpressImportRecord;
use App\Services\Wordpress\WordpressImportRetryService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class WordpressImportRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('wordpress_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('wordpress_type')
                    ->searchable(),
                TextColumn::make('importable_type')
                    ->searchable(),
                TextColumn::make('importable_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('old_url')
                    ->searchable(),
                TextColumn::make('new_url')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'imported' => 'success',
                        'failed' => 'danger',
                        'skipped' => 'warning',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('error_message')
                    ->limit(60)
                    ->toggleable(),
                TextColumn::make('warnings')
                    ->badge()
                    ->separator(',')
                    ->toggleable(),
                TextColumn::make('imported_at')
                    ->dateTime()
                    ->sortable(),
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
                        'pending' => 'Pending',
                        'imported' => 'Imported',
                        'failed' => 'Failed',
                        'skipped' => 'Skipped',
                    ]),
                SelectFilter::make('wordpress_type')
                    ->label('WordPress type')
                    ->options([
                        'post' => 'Post',
                        'page' => 'Page',
                        'media' => 'Media',
                        'category' => 'Category',
                        'tag' => 'Tag',
                    ]),
                TernaryFilter::make('has_warnings')
                    ->label('Has warnings')
                    ->queries(
                        true: fn (Builder $query): Builder => $query
                            ->whereNotNull('warnings')
                            ->where('warnings', '!=', '[]'),
                        false: fn (Builder $query): Builder => $query
                            ->where(fn (Builder $query): Builder => $query
                                ->whereNull('warnings')
                                ->orWhere('warnings', '[]')),
                    ),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (WordpressImportRecord $record): bool => $record->wordpress_type === 'post' && $record->status === 'failed')
                    ->action(function (WordpressImportRecord $record): void {
                        try {
                            app(WordpressImportRetryService::class)->retry($record);

                            Notification::make()
                                ->title('WordPress import retried')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('WordPress import retry failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
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
