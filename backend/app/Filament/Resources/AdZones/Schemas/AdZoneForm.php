<?php

namespace App\Filament\Resources\AdZones\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ad zone')
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->disabledOn('edit')
                            ->helperText('Immutable key used by the frontend ad placement API.'),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('page_context')
                            ->required()
                            ->options([
                                'home' => 'Home',
                                'category' => 'Category',
                                'article' => 'Article',
                                'search' => 'Search',
                                'author' => 'Author',
                                'event' => 'Event',
                                'newsletter' => 'Newsletter',
                                'global' => 'Global',
                            ]),
                        Toggle::make('is_active')
                            ->default(true),
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Repeater::make('allowed_sizes')
                            ->afterStateHydrated(function (Repeater $component): void {
                                $component->state(self::normalizeAllowedSizes($component->getState()));
                                $component->hydrateItems();
                            })
                            ->mutateDehydratedStateUsing(function (Repeater $component, ?array $state): array {
                                return self::dehydrateAllowedSizes($component->dehydrateItems($state));
                            })
                            ->schema([
                                TextInput::make('width')->integer()->required(),
                                TextInput::make('height')->integer()->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @param  array<int|string, mixed>|null  $sizes
     * @return array<int, array{width: int|null, height: int|null}>
     */
    private static function normalizeAllowedSizes(?array $sizes): array
    {
        return collect($sizes ?? [])
            ->map(function (array $size): array {
                if (array_key_exists('width', $size) || array_key_exists('height', $size)) {
                    return [
                        'width' => isset($size['width']) ? (int) $size['width'] : null,
                        'height' => isset($size['height']) ? (int) $size['height'] : null,
                    ];
                }

                return [
                    'width' => isset($size[0]) ? (int) $size[0] : null,
                    'height' => isset($size[1]) ? (int) $size[1] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $sizes
     * @return array<int, array{0: int, 1: int}>
     */
    private static function dehydrateAllowedSizes(array $sizes): array
    {
        return collect($sizes)
            ->map(fn (array $size): array => [
                (int) $size['width'],
                (int) $size['height'],
            ])
            ->values()
            ->all();
    }
}
