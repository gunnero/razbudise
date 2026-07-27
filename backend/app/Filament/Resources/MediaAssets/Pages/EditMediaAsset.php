<?php

namespace App\Filament\Resources\MediaAssets\Pages;

use App\Filament\Resources\MediaAssets\Actions\MediaAssetDetailActions;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Models\MediaAsset;
use App\Services\Media\MediaAssetLifecycleService;
use App\Services\Media\MediaAssetReplacementService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMediaAsset extends EditRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
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
                    $this->refreshFormData([
                        'path',
                        'filename',
                        'url',
                        'mime_type',
                        'width',
                        'height',
                        'size_bytes',
                        'metadata',
                    ]);

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
                ->requiresConfirmation()
                ->visible(fn (MediaAsset $record): bool => $record->archived_at === null)
                ->action(function (MediaAsset $record): void {
                    app(MediaAssetLifecycleService::class)->archive($record, auth()->user());
                    $this->refreshFormData(['archived_at', 'archived_by']);

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
                ->requiresConfirmation()
                ->visible(fn (MediaAsset $record): bool => $record->archived_at !== null)
                ->action(function (MediaAsset $record): void {
                    app(MediaAssetLifecycleService::class)->restore($record, auth()->user());
                    $this->refreshFormData(['archived_at', 'archived_by']);

                    Notification::make()
                        ->success()
                        ->title(__('media.notifications.restored'))
                        ->send();
                }),
            DeleteAction::make()
                ->authorize('delete')
                ->action(function (MediaAsset $record): void {
                    app(MediaAssetLifecycleService::class)->delete($record, auth()->user());
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MediaAsset $record */
        return MediaAssetDetailActions::updateMetadata($record, $data);
    }
}
