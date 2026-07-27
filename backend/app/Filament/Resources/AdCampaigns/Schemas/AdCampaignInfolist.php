<?php

namespace App\Filament\Resources\AdCampaigns\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AdCampaignInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('advertiser.name')
                    ->label('Advertiser'),
                TextEntry::make('name'),
                TextEntry::make('status'),
                TextEntry::make('start_at')
                    ->dateTime(),
                TextEntry::make('end_at')
                    ->dateTime(),
                TextEntry::make('priority')
                    ->numeric(),
                TextEntry::make('device_targeting')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('category_targeting')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('article_targeting')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('max_impressions')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('max_clicks')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('report_impressions')
                    ->label('Impressions')
                    ->state(fn ($record): string => $record->impressionCapProgress()),
                TextEntry::make('report_clicks')
                    ->label('Clicks')
                    ->state(fn ($record): string => $record->clickCapProgress()),
                TextEntry::make('report_ctr')
                    ->label('CTR')
                    ->state(fn ($record): string => $record->ctrPercent().'%'),
                TextEntry::make('daily_impression_cap')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('notes')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
