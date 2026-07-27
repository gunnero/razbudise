<?php

namespace App\Filament\Resources\AdPrices\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class AdPriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Price')
                    ->schema([
                        Select::make('ad_zone_id')
                            ->label('Zone')
                            ->relationship('zone', 'name')
                            ->required()
                            ->unique(
                                table: 'ad_prices',
                                column: 'ad_zone_id',
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                                    ->where('duration_weeks', $get('duration_weeks'))
                                    ->where('creative_type', $get('creative_type')),
                            )
                            ->searchable()
                            ->preload(),
                        TextInput::make('duration_weeks')->required()->integer()->minValue(1)->maxValue(52),
                        Select::make('creative_type')
                            ->required()
                            ->options([
                                'image' => 'Image',
                                'html' => 'HTML',
                                'script' => 'Script',
                                'native' => 'Native',
                            ])
                            ->default('image'),
                        TextInput::make('price_denars')->required()->integer()->minValue(0),
                        TextInput::make('political_multiplier')->required()->numeric()->minValue(1)->default(1),
                        Toggle::make('is_active')->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
