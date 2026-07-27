<?php

namespace App\Filament\Resources\MediaAssets\Actions;

use App\Models\MediaAsset;
use App\Services\Media\MediaAssetLifecycleService;
use App\Services\Media\MediaAssetMetadataService;
use App\Services\Media\MediaAssetReplacementService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;

class MediaAssetDetailActions
{
    /**
     * @return array<int, Action>
     */
    public static function make(): array
    {
        return [
            EditAction::make('edit_metadata')
                ->label(__('media.actions.edit'))
                ->icon('heroicon-o-pencil-square')
                ->authorize('update')
                ->slideOver()
                ->modalWidth('5xl')
                ->using(fn (array $data, MediaAsset $record): MediaAsset => self::updateMetadata($record, $data)),
            Action::make('replace')
                ->label(__('media.actions.replace'))
                ->icon('heroicon-o-arrow-up-tray')
                ->authorize('replace')
                ->schema([
                    FileUpload::make('replacement_file')
                        ->label(__('media.fields.replacement_file'))
                        ->helperText(__('media.help.replacement_file'))
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                        ->maxSize(10240)
                        ->storeFiles(false)
                        ->required(),
                ])
                ->modalWidth('lg')
                ->action(function (array $data, MediaAsset $record): void {
                    app(MediaAssetReplacementService::class)->replace(
                        $record,
                        $data['replacement_file'],
                        auth()->user(),
                    );
                    $record->refresh();

                    Notification::make()
                        ->success()
                        ->title(__('media.notifications.replaced'))
                        ->send();
                }),
            Action::make('archive')
                ->label(__('media.actions.archive'))
                ->icon('heroicon-o-archive-box')
                ->color('warning')
                ->authorize('archive')
                ->visible(fn (MediaAsset $record): bool => $record->archived_at === null)
                ->requiresConfirmation()
                ->action(function (MediaAsset $record): void {
                    app(MediaAssetLifecycleService::class)->archive($record, auth()->user());
                    $record->refresh();

                    Notification::make()
                        ->success()
                        ->title(__('media.notifications.archived'))
                        ->send();
                }),
            Action::make('restore')
                ->label(__('media.actions.restore'))
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->authorize('restore')
                ->visible(fn (MediaAsset $record): bool => $record->archived_at !== null)
                ->requiresConfirmation()
                ->action(function (MediaAsset $record): void {
                    app(MediaAssetLifecycleService::class)->restore($record, auth()->user());
                    $record->refresh();

                    Notification::make()
                        ->success()
                        ->title(__('media.notifications.restored'))
                        ->send();
                }),
            DeleteAction::make('safe_delete')
                ->label(__('media.actions.delete'))
                ->authorize('delete')
                ->cancelParentActions()
                ->using(function (MediaAsset $record): bool {
                    app(MediaAssetLifecycleService::class)->delete($record, auth()->user());

                    return true;
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateMetadata(MediaAsset $record, array $data): MediaAsset
    {
        $metadata = $data['metadata'] ?? [];
        $attributes = Arr::only($data, [
            'filename',
            'display_name',
            'title',
            'alt_text',
            'caption',
            'description',
            'photographer',
            'source_name',
            'license',
            'copyright_notice',
            'seo_filename_suggestion',
            'usage_notes',
        ]);
        $attributes['credit'] = data_get($metadata, 'credit');
        $attributes['source_url'] = data_get($metadata, 'source_url');
        $attributes['focal_point_x'] = data_get($metadata, 'focal_point.x');
        $attributes['focal_point_y'] = data_get($metadata, 'focal_point.y');

        return app(MediaAssetMetadataService::class)->update($record, $attributes, auth()->user());
    }
}
