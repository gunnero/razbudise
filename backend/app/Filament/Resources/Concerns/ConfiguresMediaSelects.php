<?php

namespace App\Filament\Resources\Concerns;

use App\Filament\Tables\MediaAssetGalleryTable;
use App\Models\MediaAsset;
use App\Services\Ads\AdCreativeMediaScope;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

trait ConfiguresMediaSelects
{
    protected static function mediaAssetSelect(string $field, string $relationship, string $label): ModalTableSelect
    {
        return ModalTableSelect::make($field)
            ->label($label)
            ->relationship(
                name: $relationship,
                titleAttribute: 'path',
                modifyQueryUsing: function (Builder $query): Builder {
                    $user = auth()->user();

                    if (! $user) {
                        return $query->whereRaw('1 = 0');
                    }

                    return app(AdCreativeMediaScope::class)
                        ->visibleTo($query, $user)
                        ->orderByDesc('created_at')
                        ->orderByDesc('id');
                },
            )
            ->scopedExists(
                model: MediaAsset::class,
                column: 'id',
                modifyQueryUsing: function (Builder $query): Builder {
                    $user = auth()->user();

                    return $user
                        ? app(AdCreativeMediaScope::class)->visibleTo($query, $user)
                        : $query->whereRaw('1 = 0');
                },
            )
            ->tableConfiguration(MediaAssetGalleryTable::class)
            ->getOptionLabelFromRecordUsing(fn (MediaAsset $record): HtmlString => self::mediaAssetPreview($record))
            ->selectAction(fn (Action $action): Action => $action
                ->label(__('media.actions.browse'))
                ->button()
                ->modalWidth('7xl'))
            ->placeholder(__('media.no_selection'))
            ->helperText(__('media.select_help'));
    }

    private static function mediaAssetPreview(MediaAsset $record): HtmlString
    {
        $url = e((string) $record->admin_preview_url);
        $alt = e((string) ($record->alt_text ?: __('media.selected')));
        $title = e((string) ($record->title ?: $record->display_name));
        $dimensions = ($record->width && $record->height) ? e($record->width.'×'.$record->height) : '—';

        return new HtmlString(
            '<figure class="razbudise-selected-media">'
                .'<img src="'.$url.'" alt="'.$alt.'" class="razbudise-selected-media-preview" loading="lazy">'
                .'<figcaption><strong>'.$title.'</strong><span>'.$dimensions.'</span></figcaption>'
                .'</figure>',
        );
    }
}
