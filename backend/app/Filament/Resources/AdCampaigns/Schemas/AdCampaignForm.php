<?php

namespace App\Filament\Resources\AdCampaigns\Schemas;

use App\Models\Article;
use App\Models\Category;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdCampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Campaign')
                    ->schema([
                        Select::make('advertiser_id')
                            ->relationship('advertiser', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('status')
                            ->required()
                            ->options([
                                'draft' => 'Draft',
                                'active' => 'Active',
                                'paused' => 'Paused',
                                'completed' => 'Completed',
                                'archived' => 'Archived',
                            ])
                            ->default('draft'),
                        TextInput::make('priority')
                            ->integer()
                            ->default(0)
                            ->required(),
                        DateTimePicker::make('start_at')
                            ->required(),
                        DateTimePicker::make('end_at')
                            ->required()
                            ->after('start_at'),
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Targeting')
                    ->schema([
                        Repeater::make('device_targeting')
                            ->schema([
                                Select::make('device')
                                    ->options([
                                        'desktop' => 'Desktop',
                                        'tablet' => 'Tablet',
                                        'mobile' => 'Mobile',
                                    ])
                                    ->required(),
                            ])
                            ->defaultItems(0),
                        Select::make('category_targeting')
                            ->multiple()
                            ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->helperText('Optional category IDs. Leave empty for all categories.'),
                        Select::make('article_targeting')
                            ->multiple()
                            ->options(fn (): array => Article::query()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->helperText('Optional article IDs. Leave empty for all articles.'),
                    ])
                    ->columns(3),

                Section::make('Caps')
                    ->schema([
                        TextInput::make('max_impressions')->integer()->minValue(1),
                        TextInput::make('max_clicks')->integer()->minValue(1),
                        TextInput::make('daily_impression_cap')->integer()->minValue(1),
                    ])
                    ->columns(3),
            ]);
    }
}
