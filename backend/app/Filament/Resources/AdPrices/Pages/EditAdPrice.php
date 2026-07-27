<?php

namespace App\Filament\Resources\AdPrices\Pages;

use App\Filament\Resources\AdPrices\AdPriceResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdPrice extends EditRecord
{
    protected static string $resource = AdPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
