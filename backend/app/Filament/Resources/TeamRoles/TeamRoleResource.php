<?php

namespace App\Filament\Resources\TeamRoles;

use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Filament\Resources\TeamRoles\Pages\CreateTeamRole;
use App\Filament\Resources\TeamRoles\Pages\EditTeamRole;
use App\Filament\Resources\TeamRoles\Pages\ListTeamRoles;
use App\Filament\Resources\TeamRoles\Pages\ViewTeamRole;
use App\Filament\Resources\TeamRoles\Schemas\TeamRoleForm;
use App\Filament\Resources\TeamRoles\Schemas\TeamRoleInfolist;
use App\Filament\Resources\TeamRoles\Tables\TeamRolesTable;
use App\Models\TeamRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TeamRoleResource extends Resource
{
    use HasResourcePermissions;

    protected static ?string $model = TeamRole::class;

    protected const string VIEW_PERMISSION = 'authors.manage';

    protected const string MANAGE_PERMISSION = 'authors.manage';

    protected const bool ADMIN_ONLY = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Тим / Crew';

    protected static ?string $navigationLabel = 'Позиции / Positions';

    protected static ?string $modelLabel = 'Позиција / Position';

    protected static ?string $pluralModelLabel = 'Позиции / Positions';

    protected static ?int $navigationSort = 23;

    public static function form(Schema $schema): Schema
    {
        return TeamRoleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TeamRoleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamRolesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamRoles::route('/'),
            'create' => CreateTeamRole::route('/create'),
            'view' => ViewTeamRole::route('/{record}'),
            'edit' => EditTeamRole::route('/{record}/edit'),
        ];
    }
}
