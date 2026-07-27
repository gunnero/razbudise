<?php

namespace App\Filament\Resources\TeamRoles\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TeamRoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Position')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('group_key')
                            ->options(self::groupOptions())
                            ->searchable(),
                        Select::make('status')
                            ->required()
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                            ])
                            ->default('active'),
                        TextInput::make('sort_order')
                            ->integer()
                            ->default(0),
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function groupOptions(): array
    {
        return [
            'editorial' => 'Редакција',
            'news' => 'Вести',
            'bitola-region' => 'Битола и регион',
            'culture-events' => 'Култура и настани',
            'lifestyle' => 'Живот и стил',
            'business' => 'Бизнис и економија',
            'media-production' => 'Фото и видео продукција',
            'audience-social' => 'Социјални медиуми и публика',
            'sales-advertising' => 'Продажба и рекламирање',
            'technology' => 'Технологија и развој',
        ];
    }
}
