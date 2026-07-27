<?php

namespace App\Filament\Tables;

use App\Models\MediaAsset;
use App\Services\Ads\AdCreativeMediaScope;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MediaAssetGalleryTable
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
            ->modifyQueryUsing(function (Builder $query): Builder {
                $user = auth()->user();

                if (! $user) {
                    return $query->whereRaw('1 = 0');
                }

                return MediaAssetTableFilters::prepareQuery(
                    app(AdCreativeMediaScope::class)->visibleTo($query, $user),
                    includeArchived: false,
                );
            })
            ->filters(MediaAssetTableFilters::make())
            ->contentGrid([
                'sm' => 2,
                'md' => 3,
                'lg' => 5,
                'xl' => 6,
                '2xl' => 8,
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(24)
            ->paginationPageOptions([24, 48, 96])
            ->searchPlaceholder(__('media.search_placeholder'))
            ->emptyStateHeading(__('media.empty_heading'))
            ->emptyStateDescription(__('media.empty_description'))
            ->deferLoading()
            ->recordClasses(function (MediaAsset $record, HasTable $livewire): string {
                MediaAssetTableFilters::hydrateVisibleUsageCounts($livewire);

                return 'razbudise-media-gallery-card'.($record->archived_at ? ' razbudise-media-gallery-card--archived' : '');
            });
    }
}
