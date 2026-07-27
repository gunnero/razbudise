<?php

namespace App\Filament\Resources\AdCreatives\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AdCreativeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('campaign.name')
                    ->label('Campaign'),
                TextEntry::make('zone.name')
                    ->label('Zone')
                    ->placeholder('-'),
                TextEntry::make('name'),
                TextEntry::make('type'),
                TextEntry::make('image.display_name')
                    ->label('Image')
                    ->placeholder('-'),
                TextEntry::make('html')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('click_url')
                    ->placeholder('-'),
                TextEntry::make('width')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('height')
                    ->numeric()
                    ->placeholder('-'),
                IconEntry::make('is_fallback')
                    ->boolean(),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
