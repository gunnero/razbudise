<?php

namespace App\Filament\Resources\WordpressImportMappings\Schemas;

use App\Models\Author;
use App\Models\Category;
use App\Models\Tag;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class WordpressImportMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('WordPress source')
                    ->schema([
                        Select::make('source_type')
                            ->required()
                            ->options([
                                'author' => 'Author',
                                'category' => 'Category',
                                'tag' => 'Tag',
                            ]),
                        TextInput::make('wordpress_id')
                            ->numeric()
                            ->helperText('Preferred when known because WordPress IDs are stable.'),
                        TextInput::make('source_slug')
                            ->maxLength(255)
                            ->helperText('Used as fallback if the WordPress ID is not available.'),
                    ])
                    ->columns(3),
                Section::make('Local target')
                    ->schema([
                        Select::make('target_type')
                            ->required()
                            ->live()
                            ->options([
                                Author::class => 'Author',
                                Category::class => 'Category',
                                Tag::class => 'Tag',
                            ]),
                        Select::make('target_id')
                            ->required()
                            ->searchable()
                            ->options(fn (Get $get): array => self::targetOptions((string) $get('target_type')))
                            ->helperText('Search and choose the existing local Author, Category, or Tag record.'),
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function targetOptions(string $targetType): array
    {
        if (! in_array($targetType, [Author::class, Category::class, Tag::class], true)) {
            return [];
        }

        return $targetType::query()
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn ($record): array => [$record->id => "{$record->name} ({$record->slug})"])
            ->all();
    }
}
