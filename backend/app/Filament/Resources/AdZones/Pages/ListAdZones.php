<?php

namespace App\Filament\Resources\AdZones\Pages;

use App\Filament\Resources\AdZones\AdZoneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdZones extends ListRecords
{
    protected static string $resource = AdZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
