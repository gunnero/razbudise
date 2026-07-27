<?php

namespace App\Filament\Resources\Articles\RelationManagers;

use App\Models\ArticleRevision;
use App\Services\Articles\ArticleRevisionService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Историја / Revisions';

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('version')->label('Version'),
            TextEntry::make('creator.name')->label('Saved by')->placeholder('System'),
            TextEntry::make('reason'),
            TextEntry::make('snapshot.article.title')->label('Title'),
            TextEntry::make('snapshot.article.status')->label('Status'),
            TextEntry::make('snapshot.article.excerpt')->label('Excerpt')->columnSpanFull(),
            TextEntry::make('snapshot.article.body_html')->label('Content')->html()->columnSpanFull(),
            TextEntry::make('created_at')->dateTime(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')->label('Version')->sortable(),
                TextColumn::make('snapshot.article.title')->label('Title')->limit(55),
                TextColumn::make('snapshot.article.status')->label('Status')->badge(),
                TextColumn::make('creator.name')->label('Saved by')->placeholder('System'),
                TextColumn::make('reason')->badge(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => auth()->user()?->can('publish', $this->getOwnerRecord()) ?? false)
                    ->action(fn (ArticleRevision $record) => app(ArticleRevisionService::class)->restore($record, auth()->user())),
            ]);
    }
}
