<?php

namespace App\Filament\Resources\TeamRoles\Pages;

use App\Filament\Resources\TeamRoles\TeamRoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeamRoles extends ListRecords
{
    protected static string $resource = TeamRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
