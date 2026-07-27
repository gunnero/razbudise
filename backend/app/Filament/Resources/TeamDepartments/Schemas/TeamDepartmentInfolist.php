<?php

namespace App\Filament\Resources\TeamDepartments\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TeamDepartmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('parentDepartment.name')
                    ->label('Parent')
                    ->placeholder('-'),
                TextEntry::make('manager.name')
                    ->placeholder('-'),
                TextEntry::make('members_count')
                    ->label('Members')
                    ->counts('members')
                    ->numeric(),
                TextEntry::make('group_key')
                    ->placeholder('-'),
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
