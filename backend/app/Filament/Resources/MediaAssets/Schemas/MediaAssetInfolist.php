<?php

namespace App\Filament\Resources\MediaAssets\Schemas;

use App\Models\MediaAsset;
use App\Services\Media\MediaAssetUsageService;
use App\Services\Media\MediaSeoFilenameSuggester;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MediaAssetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('media.details'))
                    ->schema([
                        ImageEntry::make('admin_preview_url')
                            ->hiddenLabel()
                            ->checkFileExistence(false)
                            ->imageWidth('100%')
                            ->imageHeight('28rem')
                            ->extraImgAttributes([
                                'class' => 'razbudise-media-detail-preview',
                                'loading' => 'eager',
                            ])
                            ->columnSpanFull(),
                        TextEntry::make('title')
                            ->label(__('media.fields.title'))
                            ->placeholder('—'),
                        TextEntry::make('display_name')
                            ->label(__('media.fields.display_name'))
                            ->placeholder('—'),
                        TextEntry::make('filename')
                            ->label(__('media.fields.filename'))
                            ->state(fn (MediaAsset $record): string => basename((string) $record->path))
                            ->copyable(),
                        TextEntry::make('url')
                            ->label(__('media.fields.url'))
                            ->placeholder('—')
                            ->copyable()
                            ->url(fn (?string $state): ?string => $state),
                        TextEntry::make('seo_filename_suggestion')
                            ->label(__('media.fields.seo_filename'))
                            ->state(fn (MediaAsset $record): ?string => $record->seo_filename_suggestion
                                ?: app(MediaSeoFilenameSuggester::class)->suggest($record))
                            ->placeholder('—')
                            ->copyable(),
                    ])
                    ->columns(2),
                Section::make(__('media.metadata'))
                    ->schema([
                        TextEntry::make('alt_text')
                            ->label(__('media.fields.alt_text'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('caption')
                            ->label(__('media.fields.caption'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->label(__('media.fields.description'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('metadata.credit')
                            ->label(__('media.fields.credit'))
                            ->placeholder('—'),
                        TextEntry::make('photographer')
                            ->label(__('media.fields.photographer'))
                            ->placeholder('—'),
                        TextEntry::make('source_name')
                            ->label(__('media.fields.source'))
                            ->placeholder('—'),
                        TextEntry::make('metadata.source_url')
                            ->label(__('media.fields.source_url'))
                            ->placeholder('—')
                            ->url(fn (?string $state): ?string => $state),
                        TextEntry::make('license')
                            ->label(__('media.fields.license'))
                            ->placeholder('—'),
                        TextEntry::make('copyright_notice')
                            ->label(__('media.fields.copyright'))
                            ->placeholder('—'),
                        TextEntry::make('usage_notes')
                            ->label(__('media.fields.usage_notes'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('media.technical'))
                    ->schema([
                        TextEntry::make('mime_type')
                            ->label(__('media.fields.mime_type'))
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('dimensions')
                            ->label(__('media.fields.dimensions'))
                            ->state(fn (MediaAsset $record): string => $record->width && $record->height ? $record->width.'×'.$record->height : '—'),
                        TextEntry::make('size_bytes')
                            ->label(__('media.fields.size'))
                            ->formatStateUsing(fn (?int $state): string => self::formatBytes($state)),
                        TextEntry::make('uploader.name')
                            ->label(__('media.fields.uploader'))
                            ->placeholder(__('media.unknown_uploader')),
                        TextEntry::make('created_at')
                            ->label(__('media.fields.upload_date'))
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('metadata.focal_point')
                            ->label(__('media.fields.focal_x').' / '.__('media.fields.focal_y'))
                            ->formatStateUsing(fn ($state): string => is_array($state)
                                ? ($state['x'] ?? 50).'% / '.($state['y'] ?? 50).'%'
                                : '50% / 50%'),
                        TextEntry::make('quality_details')
                            ->label(__('media.warnings_heading'))
                            ->state(fn (MediaAsset $record): array => self::qualityDetails($record))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull(),
                        TextEntry::make('metadata.responsive_variants')
                            ->label(__('media.fields.variants'))
                            ->formatStateUsing(fn ($state): string => is_array($state) && $state !== []
                                ? implode(', ', array_keys($state))
                                : __('media.original_only'))
                            ->badge()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('media.usage_section'))
                    ->schema([
                        TextEntry::make('usage_count')
                            ->label(__('media.fields.usage_count'))
                            ->state(fn (MediaAsset $record): int => (int) $record->usage_count)
                            ->badge(),
                        TextEntry::make('used_articles')
                            ->label(__('media.fields.used_articles'))
                            ->state(fn (MediaAsset $record): array => app(MediaAssetUsageService::class)
                                ->usedInArticles($record)
                                ->pluck('title')
                                ->all())
                            ->placeholder(__('media.no_articles'))
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function qualityDetails(MediaAsset $record): array
    {
        $filename = pathinfo((string) $record->path, PATHINFO_FILENAME);
        $variants = data_get($record->metadata, 'responsive_variants', []);
        $details = [];

        if (blank($record->title)) {
            $details[] = __('media.warnings.title');
        }

        if (blank($record->alt_text)) {
            $details[] = __('media.warnings.alt');
        }

        if (blank(data_get($record->metadata, 'credit'))) {
            $details[] = __('media.warnings.credit');
        }

        if (preg_match('/^(img|image|dsc|photo|untitled|screenshot|[a-f0-9]{12,})[-_\d]*$/i', $filename) === 1) {
            $details[] = __('media.warnings.filename');
        }

        if (($record->width ?? 0) < 1200 || ($record->height ?? 0) < 630) {
            $details[] = __('media.warnings.too_small');
        }

        if (($record->width ?? 0) > 6000 || ($record->height ?? 0) > 6000 || ($record->size_bytes ?? 0) > 5242880) {
            $details[] = __('media.warnings.too_large');
        }

        $details[] = __('media.recommended_ratio');
        $details[] = ($record->size_bytes ?? 0) > 2097152
            ? __('media.compression.recommended')
            : __('media.compression.good');
        $details[] = __('media.derivatives', [
            'webp' => self::hasDerivative($variants, 'webp') ? __('media.available') : __('media.not_available'),
            'avif' => self::hasDerivative($variants, 'avif') ? __('media.available') : __('media.not_available'),
        ]);

        return $details;
    }

    private static function hasDerivative(array $variants, string $format): bool
    {
        foreach ($variants as $variant) {
            $path = is_array($variant) ? ($variant['path'] ?? '') : '';

            if (str_ends_with(strtolower((string) $path), '.'.$format)) {
                return true;
            }
        }

        return false;
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
