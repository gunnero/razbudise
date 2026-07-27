<?php

namespace App\Filament\Resources\FinanceCategories\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FinanceCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
                        Select::make('type')
                            ->required()
                            ->options([
                                'income' => 'Income',
                                'expense' => 'Expense',
                                'both' => 'Both',
                            ])
                            ->default('expense'),
                        TextInput::make('sort_order')->required()->integer()->minValue(0)->default(0),
                        Toggle::make('is_active')->default(true),
                        Textarea::make('description')->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
