<?php

namespace App\Filament\Resources\AdZones\Pages;

use App\Filament\Resources\AdZones\AdZoneResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdZone extends EditRecord
{
    protected static string $resource = AdZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
