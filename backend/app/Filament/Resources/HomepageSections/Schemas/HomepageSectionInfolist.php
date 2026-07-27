<?php

namespace App\Filament\Resources\HomepageSections\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class HomepageSectionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('key'),
                TextEntry::make('name'),
                TextEntry::make('type'),
                TextEntry::make('configuration.layout')
                    ->label('Layout')
                    ->placeholder('grid'),
                TextEntry::make('configuration.ad_zone_after')
                    ->label('Ad zone after')
                    ->placeholder('-'),
                TextEntry::make('configuration')
                    ->placeholder('-')
                    ->columnSpanFull(),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('sort_order')
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
