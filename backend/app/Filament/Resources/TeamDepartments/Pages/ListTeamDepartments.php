<?php

namespace App\Filament\Resources\TeamDepartments\Pages;

use App\Filament\Resources\TeamDepartments\TeamDepartmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeamDepartments extends ListRecords
{
    protected static string $resource = TeamDepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
