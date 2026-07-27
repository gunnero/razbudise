<?php

namespace App\Filament\Resources\AdCreatives\Pages;

use App\Filament\Resources\AdCreatives\AdCreativeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdCreative extends EditRecord
{
    protected static string $resource = AdCreativeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
