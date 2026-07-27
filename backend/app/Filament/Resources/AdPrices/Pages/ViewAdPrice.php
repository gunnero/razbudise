<?php

namespace App\Filament\Resources\AdPrices\Pages;

use App\Filament\Resources\AdPrices\AdPriceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdPrice extends ViewRecord
{
    protected static string $resource = AdPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
