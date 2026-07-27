<?php

namespace App\Filament\Resources\Advertisers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AdvertiserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Advertiser')
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
                        TextInput::make('contact_name')->maxLength(255),
                        TextInput::make('contact_email')->email()->maxLength(255),
                        TextInput::make('website_url')->url()->maxLength(255),
                        Toggle::make('is_active')->default(true),
                        Textarea::make('notes')->rows(4)->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
