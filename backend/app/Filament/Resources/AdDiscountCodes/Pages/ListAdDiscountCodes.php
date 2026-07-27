<?php

namespace App\Filament\Resources\AdDiscountCodes\Pages;

use App\Filament\Resources\AdDiscountCodes\AdDiscountCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdDiscountCodes extends ListRecords
{
    protected static string $resource = AdDiscountCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
