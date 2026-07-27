<?php

namespace App\Filament\Resources\AdCampaigns\RelationManagers;

use App\Filament\Resources\Concerns\ConfiguresMediaSelects;
use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CreativesRelationManager extends RelationManager
{
    use ConfiguresMediaSelects;

    protected static string $relationship = 'creatives';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('zone_id')
                    ->relationship('zone', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->required()
                    ->options([
                        'image' => 'Image',
                        'html' => 'HTML',
                        'script' => 'Script',
                        'native' => 'Native',
                    ])
                    ->default('image'),
                self::mediaAssetSelect('image_id', 'image', 'Creative image'),
                Textarea::make('html')
                    ->label('HTML / script content')
                    ->rows(8)
                    ->columnSpanFull(),
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
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('zone.name')
                    ->label('Zone')
                    ->placeholder('-'),
                TextEntry::make('name'),
                TextEntry::make('type'),
                TextEntry::make('image.display_name')
                    ->label('Image')
                    ->placeholder('-'),
                TextEntry::make('html')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('click_url')
                    ->placeholder('-'),
                TextEntry::make('width')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('height')
                    ->numeric()
                    ->placeholder('-'),
                IconEntry::make('is_fallback')
                    ->boolean(),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('zone.name')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('type')
                    ->searchable(),
                TextColumn::make('image.display_name')
                    ->searchable(),
                TextColumn::make('click_url')
                    ->searchable(),
                TextColumn::make('width')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('height')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_fallback')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
                AssociateAction::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DissociateAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DissociateBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
