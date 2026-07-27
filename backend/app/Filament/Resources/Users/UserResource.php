<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    public const EDITORIAL_ACCESS_ROLE_NAMES = [
        'editor_in_chief',
        'editor',
        'author',
        'contributor',
    ];

    protected static ?string $model = User::class;

    protected const string VIEW_PERMISSION = 'users.manage';

    protected const string MANAGE_PERMISSION = 'users.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Users & Access';

    protected static ?string $navigationLabel = 'Users';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->hasPermission('users.manage')) {
            return $query;
        }

        if ($user?->hasPermission('editorial.users.manage')) {
            return $query->whereHas('roles', fn (Builder $query): Builder => $query
                ->whereIn('name', self::EDITORIAL_ACCESS_ROLE_NAMES));
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->hasPermission('users.manage') ?? false)
            || ($user?->hasPermission('editorial.users.manage') ?? false);
    }

    public static function canView(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        if ($user?->hasPermission('users.manage')) {
            return true;
        }

        return ($user?->hasPermission('editorial.users.manage') ?? false)
            && $record instanceof User
            && $record->roles()->whereIn('name', self::EDITORIAL_ACCESS_ROLE_NAMES)->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
