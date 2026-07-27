<?php

namespace App\Filament\Resources\AdCreatives\Schemas;

use App\Filament\Resources\Concerns\ConfiguresMediaSelects;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AdCreativeForm
{
    use ConfiguresMediaSelects;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Creative')
                    ->schema([
                        Select::make('campaign_id')
                            ->relationship('campaign', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('zone_id')
                            ->relationship('zone', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->required()
                            ->live()
                            ->options([
                                'image' => 'Image',
                                'html' => 'HTML',
                                'script' => 'Script',
                                'native' => 'Native',
                            ])
                            ->default('image'),
                        self::mediaAssetSelect('image_id', 'image', 'Creative image')
                            ->required(fn (Get $get): bool => in_array($get('type'), ['image', 'native'], true))
                            ->visible(fn (Get $get): bool => in_array($get('type'), ['image', 'native'], true)),
                        TextInput::make('click_url')
                            ->url()
                            ->maxLength(255),
                        TextInput::make('width')
                            ->integer()
                            ->minValue(1),
                        TextInput::make('height')
                            ->integer()
                            ->minValue(1),
                        Toggle::make('is_fallback')
                            ->default(false),
                        Toggle::make('is_active')
                            ->default(true),
                        Textarea::make('html')
                            ->label('HTML / script content')
                            ->rows(8)
                            ->required(fn (Get $get): bool => in_array($get('type'), ['html', 'script', 'native'], true))
                            ->visible(fn (Get $get): bool => in_array($get('type'), ['html', 'script', 'native'], true))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
