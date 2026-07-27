<?php

namespace App\Filament\Resources\AdCreatives\Pages;

use App\Filament\Resources\AdCreatives\AdCreativeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdCreative extends ViewRecord
{
    protected static string $resource = AdCreativeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
