<?php

namespace App\Filament\Resources\Roles\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Role')->schema([
                TextEntry::make('label'),
                TextEntry::make('name')->label('System name'),
                TextEntry::make('permissions.label')->label('Permissions')->badge()->placeholder('No permissions'),
                TextEntry::make('users_count')->label('Users')->counts('users')->numeric(),
            ])->columns(2),
        ]);
    }
}
