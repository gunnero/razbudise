<?php

namespace App\Filament\Resources\AdPrices\Pages;

use App\Filament\Resources\AdPrices\AdPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdPrices extends ListRecords
{
    protected static string $resource = AdPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
