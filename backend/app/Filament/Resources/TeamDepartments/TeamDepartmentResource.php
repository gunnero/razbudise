<?php

namespace App\Filament\Resources\TeamDepartments;

use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Filament\Resources\TeamDepartments\Pages\CreateTeamDepartment;
use App\Filament\Resources\TeamDepartments\Pages\EditTeamDepartment;
use App\Filament\Resources\TeamDepartments\Pages\ListTeamDepartments;
use App\Filament\Resources\TeamDepartments\Pages\ViewTeamDepartment;
use App\Filament\Resources\TeamDepartments\Schemas\TeamDepartmentForm;
use App\Filament\Resources\TeamDepartments\Schemas\TeamDepartmentInfolist;
use App\Filament\Resources\TeamDepartments\Tables\TeamDepartmentsTable;
use App\Models\TeamDepartment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TeamDepartmentResource extends Resource
{
    use HasResourcePermissions;

    protected static ?string $model = TeamDepartment::class;

    protected const string VIEW_PERMISSION = 'authors.manage';

    protected const string MANAGE_PERMISSION = 'authors.manage';

    protected const bool ADMIN_ONLY = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|\UnitEnum|null $navigationGroup = 'Тим / Crew';

    protected static ?string $navigationLabel = 'Оддели / Departments';

    protected static ?int $navigationSort = 21;

    public static function form(Schema $schema): Schema
    {
        return TeamDepartmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TeamDepartmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamDepartmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamDepartments::route('/'),
            'create' => CreateTeamDepartment::route('/create'),
            'view' => ViewTeamDepartment::route('/{record}'),
            'edit' => EditTeamDepartment::route('/{record}/edit'),
        ];
    }
}
