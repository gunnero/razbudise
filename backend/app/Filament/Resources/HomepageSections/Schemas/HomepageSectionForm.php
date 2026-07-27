<?php

namespace App\Filament\Resources\HomepageSections\Schemas;

use App\Models\AdZone;
use App\Models\Category;
use App\Models\HomepageSection;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class HomepageSectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Homepage section')
                    ->schema([
                        TextInput::make('key')->required()->maxLength(255)->unique(ignoreRecord: true),
                        TextInput::make('name')->required()->maxLength(255),
                        Select::make('type')
                            ->required()
                            ->options(HomepageSection::typeOptions())
                            ->live()
                            ->default('latest'),
                        TextInput::make('sort_order')->integer()->default(0),
                        Toggle::make('is_active')->default(true),
                        TextInput::make('configuration.title')
                            ->label('Public title')
                            ->maxLength(255),
                        Textarea::make('configuration.description')
                            ->label('Public description')
                            ->rows(3)
                            ->columnSpanFull(),
                        TextInput::make('configuration.limit')
                            ->label('Article limit')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(9)
                            ->default(6)
                            ->visible(fn (Get $get): bool => $get('type') !== 'promo_cards'),
                        TextInput::make('configuration.freshness_days')
                            ->label('Freshness window (days)')
                            ->helperText('Use 0 for no age limit. Manual sections always preserve explicit editorial choices.')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(3650)
                            ->default(0)
                            ->visible(fn (Get $get): bool => in_array($get('type'), ['latest', 'category'], true)),
                        Select::make('configuration.fallback_strategy')
                            ->label('When fresh stories run out')
                            ->options([
                                'evergreen' => 'Fill with older evergreen stories',
                                'hide' => 'Show only fresh stories',
                            ])
                            ->default('evergreen')
                            ->visible(fn (Get $get): bool => in_array($get('type'), ['latest', 'category'], true)),
                        Toggle::make('configuration.exclude_default_category')
                            ->label('Exclude default and legacy home categories')
                            ->helperText('Keeps Uncategorized and Почетна out of automatic homepage sections.')
                            ->default(true)
                            ->visible(fn (Get $get): bool => in_array($get('type'), ['latest', 'category'], true)),
                        Select::make('configuration.layout')
                            ->label('Layout')
                            ->options([
                                'hero' => 'Hero lead',
                                'grid' => 'Article grid',
                                'compact' => 'Compact grid',
                                'list' => 'List',
                            ])
                            ->default('grid')
                            ->required()
                            ->visible(fn (Get $get): bool => $get('type') !== 'promo_cards'),
                        Select::make('configuration.ad_zone_after')
                            ->label('Ad zone after this section')
                            ->options(fn (): array => AdZone::query()
                                ->where('is_active', true)
                                ->orderBy('page_context')
                                ->orderBy('name')
                                ->pluck('name', 'key')
                                ->all())
                            ->searchable()
                            ->preload(),
                        Select::make('configuration.category_slug')
                            ->label('Category')
                            ->options(fn (): array => Category::query()
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'slug')
                                ->all())
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('type') === 'category'),
                        TagsInput::make('configuration.article_slugs')
                            ->label('Manual article slugs')
                            ->placeholder('article-slug')
                            ->helperText('Use public article slugs in the exact order they should appear.')
                            ->visible(fn (Get $get): bool => $get('type') === 'manual')
                            ->columnSpanFull(),
                        Repeater::make('configuration.cards')
                            ->label('Promo cards')
                            ->schema([
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('href')
                                    ->label('New-site link')
                                    ->helperText('Use a relative frontend URL, for example /category/mimi-yugo.')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('image_url')
                                    ->label('Image URL')
                                    ->helperText('Use /homepage/promos/... or a public media URL.')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('alt')
                                    ->label('Alt text')
                                    ->maxLength(255),
                                TextInput::make('sort_order')
                                    ->integer()
                                    ->default(0),
                            ])
                            ->columns(2)
                            ->defaultItems(3)
                            ->visible(fn (Get $get): bool => $get('type') === 'promo_cards')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
