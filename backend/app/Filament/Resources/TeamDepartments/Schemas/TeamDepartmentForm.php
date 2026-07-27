<?php

namespace App\Filament\Resources\TeamDepartments\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TeamDepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Department')
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
                        Select::make('parent_department_id')
                            ->relationship('parentDepartment', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('manager_team_member_id')
                            ->relationship('manager', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('group_key')
                            ->options(self::groupOptions())
                            ->searchable(),
                        Select::make('status')
                            ->required()
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                                'hidden' => 'Hidden',
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

                Section::make('Metadata')
                    ->schema([
                        KeyValue::make('metadata')
                            ->columnSpanFull(),
                    ]),
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
