<?php

namespace App\Filament\Resources\Redirects\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RedirectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Redirect')
                    ->schema([
                        TextInput::make('source_url')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('target_url')
                            ->required()
                            ->maxLength(255),
                        Select::make('status_code')
                            ->required()
                            ->options([
                                301 => '301 Permanent',
                                302 => '302 Temporary',
                                307 => '307 Temporary',
                                308 => '308 Permanent',
                            ])
                            ->default(301),
                        Toggle::make('is_active')->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
