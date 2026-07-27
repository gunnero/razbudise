<?php

namespace App\Filament\Resources\Articles\RelationManagers;

use App\Models\ArticleChangeRequest;
use App\Models\ArticleEditorialComment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChangeRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'changeRequests';

    protected static ?string $title = 'Историја на побарани доработки';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['requester', 'resubmitter'])
                ->withCount([
                    'comments' => fn (Builder $comments): Builder => $this->visibleComments($comments),
                    'comments as unresolved_comments_count' => fn (Builder $comments): Builder => $comments
                        ->when(
                            ! $this->canUseInternalVisibility(),
                            fn (Builder $visible): Builder => $visible->where(
                                'visibility',
                                ArticleEditorialComment::VISIBILITY_AUTHOR,
                            ),
                        )
                        ->where('is_resolved', false),
                ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('summary')
                    ->label('Резиме')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('requester.name')
                    ->label('Побарал')
                    ->placeholder('Систем'),
                TextColumn::make('deadline_at')
                    ->label('Рок')
                    ->date('d.m.Y')
                    ->placeholder('Без рок'),
                TextColumn::make('unresolved_comments_count')
                    ->label('Нерешени')
                    ->badge()
                    ->color(fn (ArticleChangeRequest $record): string => $record->unresolved_comments_count > 0 ? 'warning' : 'success'),
                TextColumn::make('created_at')
                    ->label('Побарано на')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('resubmitted_at')
                    ->label('Повторно испратено')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Не е испратено'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    private function visibleComments(Builder $query): Builder
    {
        if ($this->canUseInternalVisibility()) {
            return $query;
        }

        return $query->where('visibility', ArticleEditorialComment::VISIBILITY_AUTHOR);
    }

    private function canUseInternalVisibility(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isAdmin()
            || $user?->hasRole('editor_in_chief')
            || $user?->hasRole('editor'));
    }
}
