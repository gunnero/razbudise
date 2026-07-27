<?php

namespace App\Filament\Resources\Articles\RelationManagers;

use App\Models\ArticleEditorialComment;
use App\Services\Articles\ArticleChangeRequestService;
use App\Services\Operations\AdminAuditLogger;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EditorialCommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'editorialComments';

    protected static ?string $title = 'Уреднички коментари / Editorial comments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('author_id')
                ->default(fn (): ?int => auth()->id()),
            Textarea::make('body')
                ->label('Коментар')
                ->required()
                ->rows(5)
                ->columnSpanFull(),
            Select::make('visibility')
                ->label('Видливост')
                ->required()
                ->options(fn (): array => $this->visibilityOptions())
                ->default(ArticleEditorialComment::VISIBILITY_AUTHOR),
            Toggle::make('is_blocking')
                ->label('Блокира одобрување/објавување')
                ->visible(fn (): bool => $this->canUseInternalVisibility())
                ->default(false),
        ])->columns(2);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('author.name')->label('Автор на коментар')->placeholder('System'),
            TextEntry::make('visibility')->label('Видливост')->badge(),
            IconEntry::make('is_blocking')->label('Блокира')->boolean(),
            IconEntry::make('is_resolved')->label('Решено')->boolean(),
            TextEntry::make('resolver.name')->label('Решено од')->placeholder('-'),
            TextEntry::make('resolved_at')->label('Решено на')->dateTime()->placeholder('-'),
            TextEntry::make('body')->label('Коментар')->columnSpanFull(),
            TextEntry::make('created_at')->dateTime(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->extraAttributes(['id' => 'article-editorial-comments'])
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->visibleComments($query))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('author.name')->label('Од')->placeholder('System')->searchable(),
                TextColumn::make('body')
                    ->label('Коментар')
                    ->limit(120)
                    ->wrap()
                    ->description(fn (ArticleEditorialComment $record): ?string => $record->parent_id
                        ? 'Одговор на коментар #'.$record->parent_id
                        : null)
                    ->searchable(),
                TextColumn::make('visibility')->label('Видливост')->badge(),
                IconColumn::make('is_blocking')->label('Блокира')->boolean(),
                IconColumn::make('is_resolved')->label('Решено')->boolean(),
                TextColumn::make('addressed_at')
                    ->label('Адресирано од авторот')
                    ->state(fn (ArticleEditorialComment $record): ?string => $record->addressed_at
                        ? 'Адресирано'
                        : null)
                    ->badge()
                    ->color('info')
                    ->placeholder('-'),
                TextColumn::make('created_at')->label('Додадено')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('resolution')
                    ->label('Состојба')
                    ->options([
                        'unresolved' => 'Нерешени',
                        'resolved' => 'Решени',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'unresolved' => $query->where('is_resolved', false),
                        'resolved' => $query->where('is_resolved', true),
                        default => $query,
                    }),
                SelectFilter::make('visibility')
                    ->label('Видливост')
                    ->visible(fn (): bool => $this->canUseInternalVisibility())
                    ->options([
                        ArticleEditorialComment::VISIBILITY_AUTHOR => 'Видливо за авторот',
                        ArticleEditorialComment::VISIBILITY_INTERNAL => 'Само за редакција',
                    ]),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Додај коментар')
                    ->mutateFormDataUsing(fn (array $data): array => $this->normalizeCommentData($data, isNew: true))
                    ->after(fn (ArticleEditorialComment $record): mixed => app(AdminAuditLogger::class)->record(
                        'article.comment_added',
                        $this->getOwnerRecord(),
                        auth()->user(),
                        [],
                        [
                            'comment_id' => $record->id,
                            'visibility' => $record->visibility,
                            'is_blocking' => $record->is_blocking,
                        ],
                    )),
            ])
            ->recordActions([
                Action::make('reply')
                    ->label('Одговори')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->schema([
                        Textarea::make('body')
                            ->label('Одговор')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                        Select::make('visibility')
                            ->label('Видливост')
                            ->required()
                            ->options(fn (): array => $this->visibilityOptions())
                            ->default(ArticleEditorialComment::VISIBILITY_AUTHOR),
                        Toggle::make('is_blocking')
                            ->label('Блокира одобрување/објавување')
                            ->visible(fn (): bool => $this->canUseInternalVisibility())
                            ->default(false),
                    ])
                    ->fillForm(fn (ArticleEditorialComment $record): array => [
                        'visibility' => $this->canUseInternalVisibility()
                            ? $record->visibility
                            : ArticleEditorialComment::VISIBILITY_AUTHOR,
                        'is_blocking' => false,
                    ])
                    ->action(function (array $data, ArticleEditorialComment $record): void {
                        $data = $this->normalizeCommentData($data, isNew: true);
                        $data['parent_id'] = $record->id;

                        $reply = $this->getOwnerRecord()->editorialComments()->create($data);

                        app(AdminAuditLogger::class)->record(
                            'article.comment_replied',
                            $this->getOwnerRecord(),
                            auth()->user(),
                            [],
                            [
                                'comment_id' => $reply->id,
                                'parent_id' => $record->id,
                                'visibility' => $reply->visibility,
                                'is_blocking' => $reply->is_blocking,
                            ],
                        );
                    }),
                EditAction::make()
                    ->visible(fn (ArticleEditorialComment $record): bool => $record->author_id === auth()->id() || $this->canUseInternalVisibility())
                    ->mutateFormDataUsing(fn (array $data): array => $this->normalizeCommentData($data)),
                Action::make('resolve')
                    ->label('Реши')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ArticleEditorialComment $record): bool => $this->canUseInternalVisibility() && ! $record->is_resolved)
                    ->action(function (ArticleEditorialComment $record): void {
                        $record->forceFill([
                            'is_resolved' => true,
                            'resolved_by' => auth()->id(),
                            'resolved_at' => now(),
                        ])->save();

                        app(AdminAuditLogger::class)->record(
                            'article.comment_resolved',
                            $this->getOwnerRecord(),
                            auth()->user(),
                            ['comment_id' => $record->id, 'is_resolved' => false],
                            ['comment_id' => $record->id, 'is_resolved' => true],
                        );
                    }),
                Action::make('mark_addressed')
                    ->label('Означи како адресирано')
                    ->icon('heroicon-o-check-badge')
                    ->color('info')
                    ->visible(fn (ArticleEditorialComment $record): bool => $this->isAuthorRole()
                        && $record->parent_id === null
                        && $record->visibility === ArticleEditorialComment::VISIBILITY_AUTHOR
                        && ! $record->is_resolved
                        && ! $record->addressed_at)
                    ->requiresConfirmation()
                    ->modalHeading('Означи ја забелешката како адресирана')
                    ->modalDescription('Ова му кажува на уредникот дека сте ја направиле измената. Уредникот ја затвора забелешката по проверка.')
                    ->action(fn (ArticleEditorialComment $record): ArticleEditorialComment => app(ArticleChangeRequestService::class)
                        ->markAddressed($record, auth()->user())),
                Action::make('reopen')
                    ->label('Повторно отвори')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (ArticleEditorialComment $record): bool => $this->canUseInternalVisibility() && $record->is_resolved)
                    ->action(function (ArticleEditorialComment $record): void {
                        $record->forceFill([
                            'is_resolved' => false,
                            'resolved_by' => null,
                            'resolved_at' => null,
                        ])->save();

                        app(AdminAuditLogger::class)->record(
                            'article.comment_reopened',
                            $this->getOwnerRecord(),
                            auth()->user(),
                            ['comment_id' => $record->id, 'is_resolved' => true],
                            ['comment_id' => $record->id, 'is_resolved' => false],
                        );
                    }),
            ]);
    }

    private function visibleComments(Builder $query): Builder
    {
        if ($this->canUseInternalVisibility()) {
            return $query;
        }

        return $query->where('visibility', ArticleEditorialComment::VISIBILITY_AUTHOR);
    }

    /** @param  array<string, mixed>  $data */
    private function normalizeCommentData(array $data, bool $isNew = false): array
    {
        if ($isNew) {
            $data['author_id'] = auth()->id();
        } else {
            unset($data['author_id']);
        }

        if (! $this->canUseInternalVisibility()) {
            $data['visibility'] = ArticleEditorialComment::VISIBILITY_AUTHOR;
            $data['is_blocking'] = false;
            unset($data['is_resolved'], $data['resolved_by'], $data['resolved_at']);
        }

        return $data;
    }

    /** @return array<string, string> */
    private function visibilityOptions(): array
    {
        if (! $this->canUseInternalVisibility()) {
            return [
                ArticleEditorialComment::VISIBILITY_AUTHOR => 'Видливо за авторот',
            ];
        }

        return [
            ArticleEditorialComment::VISIBILITY_AUTHOR => 'Видливо за авторот',
            ArticleEditorialComment::VISIBILITY_INTERNAL => 'Само за редакција',
        ];
    }

    private function canUseInternalVisibility(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isAdmin()
            || $user?->hasRole('editor_in_chief')
            || $user?->hasRole('editor'));
    }

    private function isAuthorRole(): bool
    {
        return (bool) (auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor'));
    }
}
