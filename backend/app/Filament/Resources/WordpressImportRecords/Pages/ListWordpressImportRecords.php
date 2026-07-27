<?php

namespace App\Filament\Resources\WordpressImportRecords\Pages;

use App\Filament\Resources\WordpressImportRecords\WordpressImportRecordResource;
use App\Jobs\ImportWordpressPostsJob;
use App\Services\Wordpress\WordpressRestImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;

class ListWordpressImportRecords extends ListRecords
{
    protected static string $resource = WordpressImportRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewImportReport')
                ->label('Import report')
                ->modalHeading('WordPress import report')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->schema(function (): array {
                    $report = app(WordpressRestImporter::class)->report();
                    $recordLines = fn (array $records): array => collect($records)
                        ->map(function (array $record): string {
                            $warnings = collect($record['warnings'] ?? [])
                                ->filter()
                                ->implode(', ');
                            $parts = [
                                "#{$record['wordpress_id']} {$record['status']}",
                                $record['old_url'] ?: 'no old URL',
                                $record['new_url'] ?: 'no new URL',
                            ];

                            if ($warnings !== '') {
                                $parts[] = "warnings: {$warnings}";
                            }

                            if (! empty($record['error_message'])) {
                                $parts[] = "error: {$record['error_message']}";
                            }

                            return implode(' | ', $parts);
                        })
                        ->all();

                    return [
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('total_post_records')
                                    ->label('Total post records')
                                    ->state($report['total_post_records']),
                                TextEntry::make('imported_count')
                                    ->label('Imported posts')
                                    ->state($report['imported_count']),
                                TextEntry::make('failed_count')
                                    ->label('Failed imports')
                                    ->state($report['failed_count']),
                                TextEntry::make('pending_count')
                                    ->label('Pending imports')
                                    ->state($report['pending_count']),
                                TextEntry::make('skipped_count')
                                    ->label('Skipped imports')
                                    ->state($report['skipped_count']),
                                TextEntry::make('missing_featured_image_count')
                                    ->label('Missing featured images')
                                    ->state($report['missing_featured_image_count']),
                            ]),
                        TextEntry::make('latest_records')
                            ->label('Latest records')
                            ->state($recordLines($report['latest_records']))
                            ->bulleted()
                            ->listWithLineBreaks()
                            ->placeholder('No imported records yet.')
                            ->columnSpanFull(),
                        TextEntry::make('failed_records')
                            ->label('Recent failures')
                            ->state($recordLines($report['failed']))
                            ->bulleted()
                            ->listWithLineBreaks()
                            ->placeholder('No failed imports.')
                            ->columnSpanFull(),
                        TextEntry::make('missing_image_records')
                            ->label('Missing featured image records')
                            ->state($recordLines($report['missing_featured_images']))
                            ->bulleted()
                            ->listWithLineBreaks()
                            ->placeholder('No missing featured images reported.')
                            ->columnSpanFull(),
                    ];
                }),
            Action::make('importWordpressPosts')
                ->label('Import WordPress posts')
                ->schema([
                    TextInput::make('base_url')
                        ->label('WordPress base URL')
                        ->default(config('services.wordpress.base_url'))
                        ->url()
                        ->required(),
                    TextInput::make('per_page')
                        ->numeric()
                        ->default(20)
                        ->minValue(1)
                        ->maxValue(100)
                        ->required(),
                    Toggle::make('queue')
                        ->label('Queue one job per REST page')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    $baseUrl = (string) $data['base_url'];
                    $perPage = (int) $data['per_page'];
                    $importer = app(WordpressRestImporter::class);

                    if ($data['queue'] ?? false) {
                        $totalPages = $importer->countPostPages($baseUrl, $perPage);

                        for ($page = 1; $page <= $totalPages; $page++) {
                            ImportWordpressPostsJob::dispatch($baseUrl, $perPage, $page);
                        }

                        Notification::make()
                            ->title("Queued {$totalPages} WordPress import page jobs.")
                            ->success()
                            ->send();

                        return;
                    }

                    $summary = $importer->importPosts($baseUrl, $perPage);

                    Notification::make()
                        ->title("Imported {$summary['imported']} posts. Failed {$summary['failed']}.")
                        ->success()
                        ->send();
                }),
            Action::make('finalizeImport')
                ->label('Finalize import cleanup')
                ->requiresConfirmation()
                ->modalDescription('Rewrite late internal links and create 301 redirects for imported WordPress URLs.')
                ->action(function (): void {
                    $summary = app(WordpressRestImporter::class)->finalizeImportedContent();

                    Notification::make()
                        ->title("Author URLs recovered {$summary['author_legacy_urls_backfilled']}; unresolved {$summary['author_legacy_urls_unresolved']}. Rewritten {$summary['rewritten_articles']} articles. Redirects updated {$summary['redirects_created_or_updated']}.")
                        ->status($summary['author_legacy_urls_unresolved'] === 0 ? 'success' : 'warning')
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
