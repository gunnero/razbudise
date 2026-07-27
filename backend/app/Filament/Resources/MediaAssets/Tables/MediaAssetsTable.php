<?php

namespace App\Filament\Resources\MediaAssets\Tables;

use App\Filament\Resources\MediaAssets\Actions\MediaAssetDetailActions;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Tables\MediaAssetTableFilters;
use App\Models\MediaAsset;
use App\Services\Media\MediaAssetLifecycleService;
use App\Services\Media\MediaCleanupService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MediaAssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ViewColumn::make('gallery_card')
                    ->label(__('media.asset'))
                    ->view('filament.tables.columns.media-asset-card')
                    ->searchable([
                        'path',
                        'display_name',
                        'title',
                        'alt_text',
                        'caption',
                        'description',
                        'photographer',
                        'source_name',
                    ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => MediaAssetTableFilters::prepareQuery($query))
            ->filters(MediaAssetTableFilters::make())
            ->contentGrid([
                'sm' => 2,
                'md' => 3,
                'xl' => 4,
                '2xl' => 5,
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(30)
            ->paginationPageOptions([30, 60, 120])
            ->searchPlaceholder(__('media.search_placeholder'))
            ->emptyStateHeading(__('media.empty_heading'))
            ->emptyStateDescription(__('media.empty_description'))
            ->deferLoading()
            ->recordClasses(function (MediaAsset $record, HasTable $livewire): string {
                MediaAssetTableFilters::hydrateVisibleUsageCounts($livewire);

                return 'razbudise-media-gallery-card'.($record->archived_at ? ' razbudise-media-gallery-card--archived' : '');
            })
            ->recordAction('view')
            ->recordUrl(null)
            ->recordActions([
                ViewAction::make()
                    ->label(__('media.actions.details'))
                    ->modal()
                    ->slideOver()
                    ->modalWidth('5xl')
                    ->extraModalFooterActions(MediaAssetDetailActions::make()),
                EditAction::make()
                    ->label(__('media.actions.edit'))
                    ->authorize('update')
                    ->url(fn (MediaAsset $record): string => MediaAssetResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('cleanup_quality')
                        ->label('Cleanup quality')
                        ->authorizeIndividualRecords('update')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records): array => app(MediaCleanupService::class)->repairAssets($records, apply: true)),
                    BulkAction::make('archive')
                        ->label(__('media.actions.archive'))
                        ->icon('heroicon-o-archive-box')
                        ->color('warning')
                        ->authorizeIndividualRecords('archive')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records): mixed => DB::transaction(
                            fn (): array => $records
                                ->map(fn (MediaAsset $record): MediaAsset => app(MediaAssetLifecycleService::class)->archive($record, auth()->user()))
                                ->all(),
                        )),
                    BulkAction::make('safe_delete')
                        ->label(__('media.actions.delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->authorizeIndividualRecords('delete')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records): mixed => DB::transaction(function () use ($records): void {
                            foreach ($records as $record) {
                                app(MediaAssetLifecycleService::class)->delete($record, auth()->user());
                            }
                        })),
                ]),
            ]);
    }
}
