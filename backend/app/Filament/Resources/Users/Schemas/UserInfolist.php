<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Login account')->schema([
                TextEntry::make('name'),
                TextEntry::make('email')->label('Email address'),
                TextEntry::make('locale')
                    ->label(__('admin.profile.language'))
                    ->formatStateUsing(fn (string $state): string => __('admin.languages.'.$state)),
                TextEntry::make('status')->badge(),
                TextEntry::make('roles.label')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('No access'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('updated_at')->dateTime(),
            ])->columns(2),
        ]);
    }
}
