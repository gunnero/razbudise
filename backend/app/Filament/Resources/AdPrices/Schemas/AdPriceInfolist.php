<?php

namespace App\Filament\Resources\AdPrices\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AdPriceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('zone.name')->label('Zone'),
                TextEntry::make('duration_weeks')->numeric(),
                TextEntry::make('creative_type')->badge(),
                TextEntry::make('price_denars')->numeric(),
                TextEntry::make('political_multiplier')->numeric(),
                IconEntry::make('is_active')->boolean(),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('updated_at')->dateTime(),
            ]);
    }
}
