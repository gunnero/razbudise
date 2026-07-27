<?php

namespace App\Filament\Resources\AdZones\Pages;

use App\Filament\Resources\AdZones\AdZoneResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdZone extends ViewRecord
{
    protected static string $resource = AdZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
