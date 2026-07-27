<?php

namespace App\Filament\Resources\TeamDepartments\Pages;

use App\Filament\Resources\TeamDepartments\TeamDepartmentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditTeamDepartment extends EditRecord
{
    protected static string $resource = TeamDepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
