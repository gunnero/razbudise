<?php

namespace App\Filament\Resources\Authors\RelationManagers;

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
use Filament\Forms\Components\DateTimePicker;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ArticlesRelationManager extends RelationManager
{
    use ConfiguresMediaSelects;

    protected static string $relationship = 'articles';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                Textarea::make('excerpt')
                    ->columnSpanFull(),
                Textarea::make('body_html')
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->required()
                    ->default('draft'),
                TextInput::make('type')
                    ->required()
                    ->default('editorial'),
                Select::make('category_id')
                    ->relationship('category', 'name'),
                self::mediaAssetSelect('featured_image_id', 'featuredImage', 'Featured image'),
                DateTimePicker::make('published_at'),
                DateTimePicker::make('scheduled_at'),
                Toggle::make('is_sponsored')
                    ->required(),
                Toggle::make('is_ai_generated')
                    ->required(),
                Toggle::make('is_ai_assisted')
                    ->required(),
                TextInput::make('old_wordpress_id')
                    ->numeric(),
                TextInput::make('old_url')
                    ->url(),
                TextInput::make('created_by')
                    ->numeric(),
                TextInput::make('updated_by')
                    ->numeric(),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('title'),
                TextEntry::make('slug'),
                TextEntry::make('excerpt')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('body_html')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('status'),
                TextEntry::make('type'),
                TextEntry::make('category.name')
                    ->label('Category')
                    ->placeholder('-'),
                TextEntry::make('featuredImage.display_name')
                    ->label('Featured image')
                    ->placeholder('-'),
                TextEntry::make('published_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('scheduled_at')
                    ->dateTime()
                    ->placeholder('-'),
                IconEntry::make('is_sponsored')
                    ->boolean(),
                IconEntry::make('is_ai_generated')
                    ->boolean(),
                IconEntry::make('is_ai_assisted')
                    ->boolean(),
                TextEntry::make('old_wordpress_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('old_url')
                    ->placeholder('-'),
                TextEntry::make('created_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('updated_by')
                    ->numeric()
                    ->placeholder('-'),
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
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('status')
                    ->searchable(),
                TextColumn::make('type')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->searchable(),
                TextColumn::make('featuredImage.display_name')
                    ->searchable(),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('scheduled_at')
                    ->dateTime()
                    ->sortable(),
                IconColumn::make('is_sponsored')
                    ->boolean(),
                IconColumn::make('is_ai_generated')
                    ->boolean(),
                IconColumn::make('is_ai_assisted')
                    ->boolean(),
                TextColumn::make('old_wordpress_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('old_url')
                    ->searchable(),
                TextColumn::make('created_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_by')
                    ->numeric()
                    ->sortable(),
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
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Работна верзија',
                        'review' => 'Review',
                        'scheduled' => 'Scheduled',
                        'published' => 'Published',
                        'archived' => 'Archived',
                    ]),
                SelectFilter::make('type')
                    ->options([
                        'editorial' => 'Editorial',
                        'news' => 'News',
                        'interview' => 'Interview',
                        'column' => 'Column',
                        'event' => 'Event',
                        'sponsored' => 'Sponsored',
                        'pr' => 'PR',
                        'guide' => 'Guide',
                    ]),
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
