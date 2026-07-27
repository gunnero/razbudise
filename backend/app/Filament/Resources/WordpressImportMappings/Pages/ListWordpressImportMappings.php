<?php

namespace App\Filament\Resources\WordpressImportMappings\Pages;

use App\Filament\Resources\WordpressImportMappings\WordpressImportMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWordpressImportMappings extends ListRecords
{
    protected static string $resource = WordpressImportMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
