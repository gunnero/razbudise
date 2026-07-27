<?php

namespace App\Filament\Resources\WordpressImportRecords\Pages;

use App\Filament\Resources\WordpressImportRecords\WordpressImportRecordResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWordpressImportRecord extends ViewRecord
{
    protected static string $resource = WordpressImportRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
