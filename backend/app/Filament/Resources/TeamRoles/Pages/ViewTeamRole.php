<?php

namespace App\Filament\Resources\TeamRoles\Pages;

use App\Filament\Resources\TeamRoles\TeamRoleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTeamRole extends ViewRecord
{
    protected static string $resource = TeamRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
