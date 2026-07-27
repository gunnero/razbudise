<?php

namespace App\Filament\Resources\MediaAssets\Schemas;

use App\Models\MediaAsset;
use App\Services\Media\MediaSeoFilenameSuggester;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class MediaAssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('media.details'))
                    ->description(__('media.upload_help'))
                    ->schema([
                        Hidden::make('disk')
                            ->default('public'),
                        FileUpload::make('path')
                            ->label(__('media.fields.file'))
                            ->disk('public')
                            ->directory('media')
                            ->visibility('public')
                            ->image()
                            ->imagePreviewHeight('320')
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                            ->downloadable()
                            ->openable()
                            ->previewable()
                            ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::refreshSeoFilenameSuggestion($get, $set))
                            ->disabled(fn (string $operation): bool => $operation !== 'create')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->columnSpanFull(),
                        TextEntry::make('quality_summary')
                            ->label(__('media.metadata'))
                            ->state(fn (?MediaAsset $record): string => self::qualitySummary($record))
                            ->color(fn (?MediaAsset $record): string => self::missingMetadataCount($record) > 0 ? 'warning' : 'success')
                            ->badge()
                            ->columnSpanFull(),
                    ]),
                Section::make(__('media.metadata'))
                    ->schema([
                        TextInput::make('display_name')
                            ->label(__('media.fields.display_name'))
                            ->helperText(__('media.help.display_name'))
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::refreshSeoFilenameSuggestion($get, $set))
                            ->maxLength(255),
                        TextInput::make('title')
                            ->label(__('media.fields.title'))
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::refreshSeoFilenameSuggestion($get, $set))
                            ->maxLength(255),
                        TextInput::make('filename')
                            ->label(__('media.fields.filename'))
                            ->helperText(__('media.help.filename'))
                            ->afterStateHydrated(fn (TextInput $component, ?MediaAsset $record): TextInput => $component->state($record ? basename((string) $record->path) : null))
                            ->dehydrated(fn (string $operation): bool => $operation !== 'create')
                            ->maxLength(255),
                        Hidden::make('_seo_filename_suggestion_generated')
                            ->default(fn (?MediaAsset $record): bool => $record === null
                                || blank($record->seo_filename_suggestion)
                                || data_get($record->metadata, 'seo_filename_suggestion_generated') === true)
                            ->dehydrated(false),
                        TextInput::make('seo_filename_suggestion')
                            ->label(__('media.fields.seo_filename'))
                            ->helperText(__('media.help.seo_filename'))
                            ->afterStateHydrated(function (TextInput $component, ?MediaAsset $record): void {
                                if ($record && blank($component->getState())) {
                                    $component->state(app(MediaSeoFilenameSuggester::class)->suggest($record));
                                }
                            })
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set): mixed => $set('_seo_filename_suggestion_generated', false))
                            ->maxLength(255),
                        Textarea::make('alt_text')
                            ->label(__('media.fields.alt_text'))
                            ->helperText(__('media.help.alt_text'))
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::refreshSeoFilenameSuggestion($get, $set))
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                        Textarea::make('caption')
                            ->label(__('media.fields.caption'))
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::refreshSeoFilenameSuggestion($get, $set))
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label(__('media.fields.description'))
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::refreshSeoFilenameSuggestion($get, $set))
                            ->rows(4)
                            ->columnSpanFull(),
                        TextInput::make('metadata.credit')
                            ->label(__('media.fields.credit'))
                            ->maxLength(255),
                        TextInput::make('photographer')
                            ->label(__('media.fields.photographer'))
                            ->maxLength(255),
                        TextInput::make('source_name')
                            ->label(__('media.fields.source'))
                            ->maxLength(255),
                        TextInput::make('metadata.source_url')
                            ->label(__('media.fields.source_url'))
                            ->url()
                            ->maxLength(2048),
                        TextInput::make('license')
                            ->label(__('media.fields.license'))
                            ->maxLength(255),
                        TextInput::make('copyright_notice')
                            ->label(__('media.fields.copyright'))
                            ->maxLength(500),
                        Textarea::make('usage_notes')
                            ->label(__('media.fields.usage_notes'))
                            ->helperText(__('media.help.usage_notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('media.fields.focal_x').' / '.__('media.fields.focal_y'))
                    ->description(__('media.help.focal_point'))
                    ->schema([
                        TextInput::make('metadata.focal_point.x')
                            ->label(__('media.fields.focal_x'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                        TextInput::make('metadata.focal_point.y')
                            ->label(__('media.fields.focal_y'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                    ])
                    ->columns(2)
                    ->collapsible(),
                Section::make(__('media.technical'))
                    ->schema([
                        TextInput::make('url')
                            ->label(__('media.fields.url'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('mime_type')
                            ->label(__('media.fields.mime_type'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('width')
                            ->label(__('media.fields.dimensions'))
                            ->formatStateUsing(fn (?MediaAsset $record): string => $record?->width && $record?->height ? $record->width.'×'.$record->height : '—')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('size_bytes')
                            ->label(__('media.fields.size'))
                            ->formatStateUsing(fn (?int $state): string => self::formatBytes($state))
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('uploaded_by')
                            ->label(__('media.fields.uploader'))
                            ->relationship('uploader', 'name')
                            ->searchable()
                            ->preload()
                            ->disabled(fn (string $operation): bool => $operation !== 'create'),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    private static function refreshSeoFilenameSuggestion(Get $get, Set $set): mixed
    {
        if (! (bool) $get('_seo_filename_suggestion_generated')) {
            return null;
        }

        $path = $get('path');

        if (is_array($path)) {
            $path = collect($path)->first();
        }

        return $set('seo_filename_suggestion', app(MediaSeoFilenameSuggester::class)->suggestFrom([
            'title' => $get('title'),
            'alt_text' => $get('alt_text'),
            'display_name' => $get('display_name'),
            'caption' => $get('caption'),
            'description' => $get('description'),
        ], is_string($path) ? $path : null));
    }

    private static function qualitySummary(?MediaAsset $record): string
    {
        if (! $record) {
            return __('media.upload_help');
        }

        $count = self::missingMetadataCount($record);

        return $count > 0
            ? trans_choice('media.warning_count', $count, ['count' => $count])
            : __('media.metadata_complete');
    }

    private static function missingMetadataCount(?MediaAsset $record): int
    {
        if (! $record) {
            return 0;
        }

        return collect([
            $record->title,
            $record->alt_text,
            $record->caption,
            data_get($record->metadata, 'credit'),
        ])->filter(fn ($value): bool => blank($value))->count();
    }

    private static function formatBytes(?int $bytes): string
    {
        return match (true) {
            blank($bytes) => '—',
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 0).' KB',
            default => $bytes.' B',
        };
    }
}
