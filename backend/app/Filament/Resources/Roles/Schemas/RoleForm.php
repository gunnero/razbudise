<?php

namespace App\Filament\Resources\Roles\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Role')->schema([
                TextInput::make('name')->disabled()->dehydrated(false),
                TextInput::make('label')->required()->maxLength(255),
                CheckboxList::make('permissions')
                    ->relationship('permissions', 'label')
                    ->columns(2)
                    ->searchable()
                    ->bulkToggleable(),
            ]),
        ]);
    }
}
