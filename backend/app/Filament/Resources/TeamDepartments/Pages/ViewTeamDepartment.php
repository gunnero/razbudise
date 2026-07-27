<?php

namespace App\Filament\Resources\TeamDepartments\Pages;

use App\Filament\Resources\TeamDepartments\TeamDepartmentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTeamDepartment extends ViewRecord
{
    protected static string $resource = TeamDepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
