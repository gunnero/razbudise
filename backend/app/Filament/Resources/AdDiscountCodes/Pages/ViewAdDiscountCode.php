<?php

namespace App\Filament\Resources\AdDiscountCodes\Pages;

use App\Filament\Resources\AdDiscountCodes\AdDiscountCodeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdDiscountCode extends ViewRecord
{
    protected static string $resource = AdDiscountCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
