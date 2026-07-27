<?php

namespace App\Filament\Tables;

use App\Models\MediaAsset;
use App\Services\Media\MediaAssetUsageService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MediaAssetTableFilters
{
    /**
     * @return array<int, Filter|SelectFilter|TernaryFilter>
     */
    public static function make(): array
    {
        return [
            Filter::make('uploaded_at')
                ->label(__('media.filters.upload_date'))
                ->schema([
                    DatePicker::make('from')
                        ->label(__('media.filters.from')),
                    DatePicker::make('until')
                        ->label(__('media.filters.until')),
                ])
                ->columns(2)
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            SelectFilter::make('uploaded_by')
                ->label(__('media.filters.uploader'))
                ->relationship('uploader', 'name')
                ->searchable()
                ->preload(),
            SelectFilter::make('file_type')
                ->label(__('media.filters.file_type'))
                ->options([
                    'image/jpeg' => 'JPEG',
                    'image/png' => 'PNG',
                    'image/webp' => 'WebP',
                    'image/gif' => 'GIF',
                ])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['value'] ?? null, fn (Builder $query, string $mime): Builder => $query->where('mime_type', $mime))),
            SelectFilter::make('orientation')
                ->label(__('media.filters.orientation'))
                ->options([
                    'landscape' => __('media.orientations.landscape'),
                    'portrait' => __('media.orientations.portrait'),
                    'square' => __('media.orientations.square'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'landscape' => $query->whereColumn('width', '>', 'height'),
                        'portrait' => $query->whereColumn('height', '>', 'width'),
                        'square' => $query->whereColumn('width', 'height'),
                        default => $query,
                    };
                }),
            SelectFilter::make('usage')
                ->label(__('media.filters.usage'))
                ->options([
                    'used' => __('media.usage.used'),
                    'unused' => __('media.usage.unused'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'used' => self::whereUsed($query),
                        'unused' => self::whereUnused($query),
                        default => $query,
                    };
                }),
            TernaryFilter::make('missing_alt_text')
                ->label(__('media.filters.missing_alt'))
                ->placeholder(__('media.filters.any'))
                ->trueLabel(__('media.filters.only_missing'))
                ->falseLabel(__('media.filters.only_complete'))
                ->queries(
                    true: fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereNull('alt_text')->orWhere('alt_text', '')),
                    false: fn (Builder $query): Builder => $query->whereNotNull('alt_text')->where('alt_text', '!=', ''),
                ),
            TernaryFilter::make('missing_title')
                ->label(__('media.filters.missing_title'))
                ->placeholder(__('media.filters.any'))
                ->trueLabel(__('media.filters.only_missing'))
                ->falseLabel(__('media.filters.only_complete'))
                ->queries(
                    true: fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereNull('title')->orWhere('title', '')),
                    false: fn (Builder $query): Builder => $query->whereNotNull('title')->where('title', '!=', ''),
                ),
            TernaryFilter::make('missing_credit')
                ->label(__('media.filters.missing_credit'))
                ->placeholder(__('media.filters.any'))
                ->trueLabel(__('media.filters.only_missing'))
                ->falseLabel(__('media.filters.only_complete'))
                ->queries(
                    true: fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereNull('metadata->credit')->orWhere('metadata->credit', '')),
                    false: fn (Builder $query): Builder => $query->whereNotNull('metadata->credit')->where('metadata->credit', '!=', ''),
                ),
            TernaryFilter::make('missing_caption')
                ->label(__('media.filters.missing_caption'))
                ->placeholder(__('media.filters.any'))
                ->trueLabel(__('media.filters.only_missing'))
                ->falseLabel(__('media.filters.only_complete'))
                ->queries(
                    true: fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereNull('caption')->orWhere('caption', '')),
                    false: fn (Builder $query): Builder => $query->whereNotNull('caption')->where('caption', '!=', ''),
                ),
            Filter::make('sort')
                ->label(__('media.filters.sort'))
                ->schema([
                    Select::make('value')
                        ->label(__('media.filters.sort'))
                        ->options([
                            'newest' => __('media.sort.newest'),
                            'oldest' => __('media.sort.oldest'),
                            'filename' => __('media.sort.filename'),
                            'largest' => __('media.sort.largest'),
                            'smallest' => __('media.sort.smallest'),
                            'most_used' => __('media.sort.most_used'),
                            'unused' => __('media.sort.unused'),
                        ]),
                ])
                ->baseQuery(fn (Builder $query, array $data): Builder => self::applySort($query, $data['value'] ?? null)),
        ];
    }

    public static function prepareQuery(Builder $query, bool $includeArchived = true): Builder
    {
        $query
            ->with('uploader')
            ->when(! $includeArchived, fn (Builder $query): Builder => $query->whereNull('archived_at'))
            ->where(function (Builder $query): void {
                $query
                    ->where('mime_type', 'like', 'image/%')
                    ->orWhereNull('mime_type');
            });

        return $query;
    }

    public static function hydrateVisibleUsageCounts(HasTable $livewire): void
    {
        $records = $livewire->getTableRecords();
        $page = $records instanceof Collection
            ? $records
            : collect($records->items());

        app(MediaAssetUsageService::class)->hydrateUsageCounts($page);
    }

    private static function whereUsed(Builder $query): Builder
    {
        if (method_exists($query->getModel(), 'scopeWhereUsed')) {
            return $query->whereUsed();
        }

        return $query->where(fn (Builder $query): Builder => $query
            ->whereHas('featuredArticles')
            ->orWhereHas('avatarAuthors')
            ->orWhereHas('teamMemberProfiles')
            ->orWhereHas('adBookingLineItems'));
    }

    private static function whereUnused(Builder $query): Builder
    {
        if (method_exists($query->getModel(), 'scopeWhereUnused')) {
            return $query->whereUnused();
        }

        return $query
            ->whereDoesntHave('featuredArticles')
            ->whereDoesntHave('avatarAuthors')
            ->whereDoesntHave('teamMemberProfiles')
            ->whereDoesntHave('adBookingLineItems');
    }

    private static function applySort(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'oldest' => $query->reorder()->orderBy('created_at')->orderBy('id'),
            'filename' => $query->reorder()->orderBy('path')->orderBy('id'),
            'largest' => $query->reorder()->orderByDesc('size_bytes')->orderByDesc('id'),
            'smallest' => $query->reorder()->orderBy('size_bytes')->orderBy('id'),
            'most_used' => self::sortByUsage($query, 'desc'),
            'unused' => self::sortByUsage($query, 'asc'),
            'newest' => $query->reorder()->orderByDesc('created_at')->orderByDesc('id'),
            default => $query,
        };
    }

    private static function sortByUsage(Builder $query, string $direction): Builder
    {
        if (method_exists(MediaAsset::class, 'scopeWithUsageCount')) {
            return $query
                ->withUsageCount()
                ->reorder()
                ->orderBy('usage_count', $direction)
                ->orderByDesc('created_at');
        }

        return $query->reorder()->orderByDesc('created_at');
    }
}
