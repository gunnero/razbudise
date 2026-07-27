<?php

namespace App\Filament\Resources\WordpressImportRecords\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class WordpressImportRecordInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('wordpress_id')
                    ->numeric(),
                TextEntry::make('wordpress_type'),
                TextEntry::make('importable_type')
                    ->placeholder('-'),
                TextEntry::make('importable_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('old_url')
                    ->placeholder('-'),
                TextEntry::make('new_url')
                    ->placeholder('-'),
                TextEntry::make('status'),
                TextEntry::make('source_payload')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('warnings')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('error_message')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('imported_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
