<?php

namespace App\Filament\Resources\AdCreatives\Pages;

use App\Filament\Resources\AdCreatives\AdCreativeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdCreatives extends ListRecords
{
    protected static string $resource = AdCreativeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
