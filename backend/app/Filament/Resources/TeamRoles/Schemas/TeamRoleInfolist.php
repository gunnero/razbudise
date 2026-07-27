<?php

namespace App\Filament\Resources\TeamRoles\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TeamRoleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('group_key')
                    ->placeholder('-'),
                TextEntry::make('members_count')
                    ->label('Team members')
                    ->counts('members')
                    ->numeric(),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('sort_order')
                    ->numeric(),
                TextEntry::make('description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
