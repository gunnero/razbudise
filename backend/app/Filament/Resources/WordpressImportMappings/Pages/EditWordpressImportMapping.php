<?php

namespace App\Filament\Resources\WordpressImportMappings\Pages;

use App\Filament\Resources\WordpressImportMappings\WordpressImportMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWordpressImportMapping extends EditRecord
{
    protected static string $resource = WordpressImportMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
