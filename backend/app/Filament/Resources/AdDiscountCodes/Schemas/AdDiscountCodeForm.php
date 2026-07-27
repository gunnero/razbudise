<?php

namespace App\Filament\Resources\AdDiscountCodes\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdDiscountCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Discount code')
                    ->schema([
                        TextInput::make('code')->required()->maxLength(255)->unique(ignoreRecord: true),
                        Select::make('type')
                            ->required()
                            ->options([
                                'percent' => 'Percent',
                                'fixed' => 'Fixed amount',
                            ])
                            ->default('percent'),
                        TextInput::make('value')->required()->integer()->minValue(0),
                        TextInput::make('usage_limit')->integer()->minValue(1),
                        TextInput::make('used_count')->integer()->minValue(0)->default(0),
                        Toggle::make('is_active')->default(true),
                        DateTimePicker::make('starts_at')->seconds(false),
                        DateTimePicker::make('ends_at')->seconds(false),
                        TagsInput::make('ad_zone_keys')
                            ->label('Zone keys')
                            ->placeholder('home_top_billboard')
                            ->columnSpanFull(),
                        Textarea::make('description')->rows(3)->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
