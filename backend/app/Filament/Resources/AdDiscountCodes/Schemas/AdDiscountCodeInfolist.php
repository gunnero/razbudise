<?php

namespace App\Filament\Resources\AdDiscountCodes\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AdDiscountCodeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('code'),
                TextEntry::make('type')->badge(),
                TextEntry::make('value')->numeric(),
                TextEntry::make('usage_limit')->numeric()->placeholder('-'),
                TextEntry::make('used_count')->numeric(),
                TextEntry::make('starts_at')->dateTime()->placeholder('-'),
                TextEntry::make('ends_at')->dateTime()->placeholder('-'),
                TextEntry::make('ad_zone_keys')->badge()->placeholder('-'),
                IconEntry::make('is_active')->boolean(),
                TextEntry::make('description')->placeholder('-')->columnSpanFull(),
            ]);
    }
}
