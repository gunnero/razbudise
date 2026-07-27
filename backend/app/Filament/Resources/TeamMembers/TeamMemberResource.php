<?php

namespace App\Filament\Resources\TeamMembers;

use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Filament\Resources\TeamMembers\Pages\CreateTeamMember;
use App\Filament\Resources\TeamMembers\Pages\EditTeamMember;
use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Filament\Resources\TeamMembers\Pages\ViewTeamMember;
use App\Filament\Resources\TeamMembers\Schemas\TeamMemberForm;
use App\Filament\Resources\TeamMembers\Schemas\TeamMemberInfolist;
use App\Filament\Resources\TeamMembers\Tables\TeamMembersTable;
use App\Models\TeamMember;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TeamMemberResource extends Resource
{
    use HasResourcePermissions;

    protected static ?string $model = TeamMember::class;

    protected const string VIEW_PERMISSION = 'authors.manage';

    protected const string MANAGE_PERMISSION = 'authors.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Тим / Crew';

    protected static ?string $navigationLabel = 'Луѓе / People';

    protected static ?string $modelLabel = 'член на тимот';

    protected static ?string $pluralModelLabel = 'Тим / Crew';

    protected static ?int $navigationSort = 22;

    public static function form(Schema $schema): Schema
    {
        return TeamMemberForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TeamMemberInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamMembersTable::configure($table);
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->isAdmin() ?? false)
            || (($user?->hasRole('editor_in_chief') ?? false) && ($user?->hasPermission('authors.manage') ?? false));
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamMembers::route('/'),
            'create' => CreateTeamMember::route('/create'),
            'view' => ViewTeamMember::route('/{record}'),
            'edit' => EditTeamMember::route('/{record}/edit'),
        ];
    }
}
