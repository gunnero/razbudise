<?php

namespace App\Filament\Resources\Menus\Schemas;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Tag;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MenuForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Menu')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('location')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->options([
                                'header' => 'Header',
                                'footer' => 'Footer',
                                'mobile' => 'Mobile',
                                'social' => 'Social',
                            ]),
                        Toggle::make('is_active')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Items')
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->schema([
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('parent_id')
                                    ->label('Parent item')
                                    ->relationship('parent', 'title')
                                    ->searchable()
                                    ->preload(),
                                Select::make('link_type')
                                    ->required()
                                    ->live()
                                    ->options([
                                        'custom' => 'Custom URL',
                                        'category' => 'Category',
                                        'tag' => 'Tag',
                                        'article' => 'Article',
                                        'author' => 'Author',
                                    ])
                                    ->default('custom'),
                                TextInput::make('url')
                                    ->maxLength(255)
                                    ->visible(fn (Get $get): bool => $get('link_type') === 'custom')
                                    ->required(fn (Get $get): bool => $get('link_type') === 'custom'),
                                Select::make('linkable_type')
                                    ->label('Target type')
                                    ->visible(fn (Get $get): bool => $get('link_type') !== 'custom')
                                    ->required(fn (Get $get): bool => $get('link_type') !== 'custom')
                                    ->options([
                                        Article::class => 'Article',
                                        Author::class => 'Author',
                                        Category::class => 'Category',
                                        Tag::class => 'Tag',
                                    ]),
                                TextInput::make('linkable_id')
                                    ->label('Target ID')
                                    ->numeric()
                                    ->visible(fn (Get $get): bool => $get('link_type') !== 'custom')
                                    ->required(fn (Get $get): bool => $get('link_type') !== 'custom'),
                                Toggle::make('opens_in_new_tab')
                                    ->default(false),
                                Toggle::make('is_active')
                                    ->default(true),
                                TextInput::make('sort_order')
                                    ->integer()
                                    ->default(0)
                                    ->required(),
                            ])
                            ->orderColumn('sort_order')
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
