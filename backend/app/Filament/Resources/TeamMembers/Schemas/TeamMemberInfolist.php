<?php

namespace App\Filament\Resources\TeamMembers\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TeamMemberInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('display_title')
                    ->placeholder('-'),
                TextEntry::make('profileImage.display_name')
                    ->label('Profile image')
                    ->placeholder(__('admin.fields.default_avatar')),
                TextEntry::make('sex')
                    ->label(__('admin.fields.sex'))
                    ->formatStateUsing(fn (?string $state): string => $state === 'female' ? __('admin.sex.female') : __('admin.sex.male'))
                    ->badge(),
                TextEntry::make('roles.name')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('primaryDepartment.name')
                    ->label('Department')
                    ->placeholder('-'),
                TextEntry::make('manager.name')
                    ->placeholder('-'),
                TextEntry::make('author.name')
                    ->label('Linked author')
                    ->placeholder('-'),
                TextEntry::make('member_type')
                    ->badge(),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('sort_order')
                    ->numeric(),
                TextEntry::make('joined_at')
                    ->date()
                    ->placeholder('-'),
                TextEntry::make('email')
                    ->placeholder('-'),
                IconEntry::make('is_ai')
                    ->label('AI member')
                    ->boolean()
                    ->state(fn ($record): bool => $record->member_type === 'ai'),
                TextEntry::make('short_bio')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('full_bio')
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
