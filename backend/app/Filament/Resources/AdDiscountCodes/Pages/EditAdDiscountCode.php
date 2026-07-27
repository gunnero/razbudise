<?php

namespace App\Filament\Resources\AdDiscountCodes\Pages;

use App\Filament\Resources\AdDiscountCodes\AdDiscountCodeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdDiscountCode extends EditRecord
{
    protected static string $resource = AdDiscountCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
