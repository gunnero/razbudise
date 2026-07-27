<?php

namespace App\Filament\Resources\Articles\Tables;

use App\Filament\Actions\RequestArticleChangesAction;
use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\User;
use App\Services\Articles\ArticleChangeRequestService;
use App\Services\Articles\ArticleEditorialWorkflow;
use App\Support\AdminTranslations;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

class ArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->defaultSortOptionLabel('Последно ажурирани')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->stackedOnMobile()
            ->striped()
            ->recordUrl(fn (Article $record): string => ArticleResource::getUrl('edit', ['record' => $record]))
            ->recordClasses(fn (Article $record): string => match (true) {
                filled($record->publication_failed_at) || $record->status === 'failed' => 'newsroom-article-row newsroom-article-row--failed',
                in_array($record->status, ['submitted', 'review', 'in_review'], true) && ! $record->approved_at => 'newsroom-article-row newsroom-article-row--approval',
                default => 'newsroom-article-row',
            })
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'author:id,name,is_active',
                'assignedEditor:id,name',
                'category:id,name',
                'supervisingEditor:id,name',
            ]))
            ->extraAttributes([
                'class' => 'newsroom-posts-table',
                'data-newsroom-table' => 'articles',
                'x-init' => <<<'JS'
                    $nextTick(() => {
                        const scroller = $el.querySelector('.fi-ta-content-ctn')

                        if (! scroller) return

                        scroller.setAttribute('tabindex', '0')
                        scroller.setAttribute('role', 'region')
                        scroller.setAttribute('aria-label', 'Објави, скролувачка табела')
                    })
                    JS,
            ])
            ->emptyStateHeading('Нема објави во овој приказ')
            ->emptyStateDescription('Променете го прегледот или филтрите, или креирајте нова објава.')
            ->columns([
                TextColumn::make('title')
                    ->label('Објава')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->lineClamp(2)
                    ->width('18rem')
                    ->grow(false)
                    ->description(fn (Article $record): ?string => self::articleMetadata($record))
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->extraAttributes(['class' => 'newsroom-posts-table__title'])
                    ->extraCellAttributes(['class' => 'newsroom-posts-table__title-cell']),
                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->formatStateUsing(fn (?string $state): string => self::compactArticleStatus($state))
                    ->tooltip(fn (?string $state): string => AdminTranslations::articleStatus($state))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'published' => 'success',
                        'scheduled', 'approved' => 'info',
                        'submitted', 'review', 'in_review' => 'warning',
                        'changes_requested', 'failed' => 'danger',
                        default => 'gray',
                    })
                    ->width('7.5rem')
                    ->sortable(),
                TextColumn::make('editorial_editor')
                    ->label('Уредник')
                    ->state(fn (Article $record): ?string => $record->supervisingEditor?->name ?? $record->assignedEditor?->name)
                    ->description(fn (Article $record): ?string => self::assignedEditorDescription($record))
                    ->placeholder('—')
                    ->visibleFrom('xl'),
                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->formatStateUsing(fn (?string $state): string => AdminTranslations::articleType($state))
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('author.name')
                    ->label(__('admin.fields.author'))
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('supervisingEditor.name')
                    ->label(__('admin.fields.supervising_editor'))
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('assignedEditor.name')
                    ->label(__('admin.fields.assigned_editor'))
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category.name')
                    ->label(__('admin.fields.category'))
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tags_count')
                    ->label(__('admin.fields.tags'))
                    ->counts('tags')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('unresolved_comments_count')
                    ->label('Коментари')
                    ->counts([
                        'editorialComments as unresolved_comments_count' => fn (Builder $query): Builder => $query
                            ->where('is_resolved', false)
                            ->when(
                                auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor'),
                                fn (Builder $query): Builder => $query->where('visibility', ArticleEditorialComment::VISIBILITY_AUTHOR),
                            ),
                    ])
                    ->state(fn (Article $record): ?int => ((int) $record->unresolved_comments_count > 0)
                        ? (int) $record->unresolved_comments_count
                        : null)
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->placeholder('—')
                    ->color('warning')
                    ->tooltip(fn (int|string|null $state): ?string => ((int) $state > 0)
                        ? (((int) $state === 1) ? '1 нерешен коментар' : "{$state} нерешени коментари")
                        : null)
                    ->width('7.5rem')
                    ->toggleable(),
                TextColumn::make('views_count')
                    ->label('Прегледи')
                    ->counts('views')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('revisions_count')
                    ->label('Ревизии')
                    ->counts('revisions')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('admin.fields.updated_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable()
                    ->visibleFrom('xl'),
                TextColumn::make('submitted_at')
                    ->label(__('admin.fields.submitted_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approved_at')
                    ->label(__('admin.fields.approved_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')
                    ->label(__('admin.fields.published_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('scheduled_at')
                    ->label(__('admin.fields.scheduled_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_sponsored')
                    ->label(__('admin.fields.is_sponsored'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('old_wordpress_id')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('admin.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(AdminTranslations::articleStatuses()),
                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(AdminTranslations::articleTypes()),
                SelectFilter::make('author_id')
                    ->label(__('admin.fields.author'))
                    ->relationship('author', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('category_id')
                    ->label(__('admin.fields.category'))
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('featured_image')
                    ->label(__('admin.fields.featured_image'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('featured_image_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('featured_image_id'),
                    ),
                SelectFilter::make('editorial_queue')
                    ->label('Зачуван преглед')
                    ->options([
                        'latest_updated' => 'Последно ажурирани',
                        'newest' => 'Најново креирани',
                        'recent_submissions' => 'Последно испратени',
                        'my_posts' => 'Мои објави',
                        'managed' => 'Објави што ги управувам',
                        'needs_approval' => 'Чекаат мое одобрување',
                        'in_review' => 'Во преглед',
                        'changes_requested' => 'Потребна доработка',
                        'approved' => 'Одобрени',
                        'scheduled' => 'Сите закажани',
                        'scheduled_today' => 'Закажани денес',
                        'scheduled_this_week' => 'Закажани оваа недела',
                        'published_today' => 'Објавени денес',
                        'missing_featured_image' => 'Без главна слика',
                        'missing_category' => 'Без категорија',
                        'missing_seo' => 'Нецелосно SEO',
                        'failed' => 'Неуспешни објавувања',
                        'without_supervisor' => 'Без надлежен уредник',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::queueQuery($query, $data['value'] ?? null, auth()->user())),
                SelectFilter::make('supervising_editor_id')
                    ->label(__('admin.fields.supervising_editor'))
                    ->relationship('supervisingEditor', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('assigned_editor_id')
                    ->label(__('admin.fields.assigned_editor'))
                    ->relationship('assignedEditor', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('sponsorship')
                    ->label('Спонзорирана содржина')
                    ->options([
                        'sponsored' => 'Спонзорирана или ПР',
                        'needs_details' => 'Недостигаат податоци за спонзорот',
                        'organic' => 'Органска редакциска содржина',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'sponsored' => $query->where(fn (Builder $query): Builder => $query
                                ->where('is_sponsored', true)
                                ->orWhereIn('type', ['sponsored', 'pr'])),
                            'needs_details' => $query
                                ->where(fn (Builder $query): Builder => $query
                                    ->where('is_sponsored', true)
                                    ->orWhereIn('type', ['sponsored', 'pr']))
                                ->whereDoesntHave('sponsoredContentDetail'),
                            'organic' => $query
                                ->where('is_sponsored', false)
                                ->whereNotIn('type', ['sponsored', 'pr']),
                            default => $query,
                        };
                    }),
                SelectFilter::make('import_status')
                    ->label('Import status')
                    ->options([
                        'not_imported' => 'Not imported',
                        'pending' => 'Pending',
                        'imported' => 'Imported',
                        'failed' => 'Failed',
                        'skipped' => 'Skipped',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;

                        return match ($status) {
                            'not_imported' => $query->whereDoesntHave('wordpressImportRecord'),
                            'pending', 'imported', 'failed', 'skipped' => $query->whereHas(
                                'wordpressImportRecord',
                                fn (Builder $query): Builder => $query->where('status', $status),
                            ),
                            default => $query,
                        };
                    }),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action
                ->button()
                ->label('Филтри')
                ->tooltip('Отвори филтри'))
            ->columnManagerTriggerAction(fn (Action $action): Action => $action
                ->button()
                ->label('Колони')
                ->tooltip('Избери колони'))
            ->recordActions([
                ActionGroup::make([
                    Action::make('preview')
                        ->label(__('admin.actions.preview'))
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (Article $record): string => $record->previewUrl(), shouldOpenInNewTab: true),
                    Action::make('send_to_review')
                        ->label(fn (Article $record): string => $record->status === 'changes_requested'
                            ? 'Испрати повторно на проверка'
                            : __('admin.actions.review'))
                        ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                        ->visible(fn (Article $record): bool => self::isSubmissionOnlyUser() && self::canRunTransition($record, 'submit', 'submit'))
                        ->action(fn (Article $record): Article => app(ArticleChangeRequestService::class)->submitForReview($record, auth()->user())),
                    Action::make('start_review')
                        ->label(__('admin.actions.start_review'))
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->visible(fn (Article $record): bool => self::canRunTransition($record, 'review', 'startReview'))
                        ->action(fn (Article $record): Article => app(ArticleEditorialWorkflow::class)->startReview($record, auth()->user())),
                    RequestArticleChangesAction::make(),
                    Action::make('approve')
                        ->label(__('admin.actions.approve'))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->visible(fn (Article $record): bool => self::canRunTransition($record, 'approve', 'approve'))
                        ->action(fn (Article $record): Article => app(ArticleEditorialWorkflow::class)->approve($record, auth()->user())),
                    Action::make('schedule')
                        ->label(__('admin.actions.schedule'))
                        ->icon(Heroicon::OutlinedCalendar)
                        ->visible(fn (Article $record): bool => self::canRunTransition($record, 'schedule', 'schedule'))
                        ->schema([
                            DateTimePicker::make('scheduled_at')
                                ->required()
                                ->after(now())
                                ->seconds(false),
                        ])
                        ->action(fn (array $data, Article $record): Article => app(ArticleEditorialWorkflow::class)->schedule($record, $data['scheduled_at'], auth()->user())),
                    Action::make('publish')
                        ->label(__('admin.actions.publish'))
                        ->icon(Heroicon::OutlinedRocketLaunch)
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (Article $record): bool => self::canPublish($record))
                        ->action(fn (Article $record): Article => app(ArticleEditorialWorkflow::class)->publish($record, auth()->user())),
                    Action::make('archive')
                        ->label(__('admin.actions.archive'))
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (Article $record): bool => self::canRunTransition($record, 'publish', 'archive'))
                        ->action(fn (Article $record): Article => app(ArticleEditorialWorkflow::class)->archive($record, auth()->user())),
                    ViewAction::make(),
                    EditAction::make(),
                ])
                    ->label('Акции')
                    ->tooltip('Акции')
                    ->iconButton()
                    ->dropdownTeleport(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('submit_for_review')
                        ->label('Испрати на преглед')
                        ->icon(Heroicon::OutlinedPaperAirplane)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => self::isSubmissionOnlyUser())
                        ->authorizeIndividualRecords('submit')
                        ->action(function (BulkAction $action): void {
                            $action->getIndividuallyAuthorizedSelectedRecords()
                                ->each(function (Article $record) use ($action): void {
                                    try {
                                        app(ArticleChangeRequestService::class)->submitForReview($record, auth()->user());
                                    } catch (Throwable $exception) {
                                        report($exception);
                                        $action->reportBulkProcessingFailure();
                                    }
                                });
                        })
                        ->deselectRecordsAfterCompletion()
                        ->successNotificationTitle('Избраните објави се испратени на преглед'),
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => ! self::isSubmissionOnlyUser()),
                ]),
            ]);
    }

    private static function articleMetadata(Article $record): ?string
    {
        $metadata = collect([
            $record->author?->name,
            $record->category?->name,
            AdminTranslations::articleType($record->type),
        ])->filter()->join(' · ');

        return filled($metadata) ? $metadata : null;
    }

    private static function compactArticleStatus(?string $status): string
    {
        return match ($status) {
            'published' => 'Објавена',
            'scheduled' => 'Закажана',
            'approved' => 'Одобрена',
            'submitted' => 'Испратена',
            'review', 'in_review' => 'На преглед',
            'changes_requested' => 'Потребна доработка',
            'failed' => 'Грешка',
            'archived' => 'Архивирана',
            'draft' => 'Работна верзија',
            default => AdminTranslations::articleStatus($status),
        };
    }

    private static function assignedEditorDescription(Article $record): ?string
    {
        if (! $record->supervisingEditor || ! $record->assignedEditor) {
            return null;
        }

        if ($record->supervisingEditor->is($record->assignedEditor)) {
            return null;
        }

        return "Назначен: {$record->assignedEditor->name}";
    }

    private static function queueQuery(Builder $query, ?string $queue, ?User $user): Builder
    {
        if (! $queue || ! $user) {
            return $query;
        }

        return match ($queue) {
            'my_posts' => $query->where(fn (Builder $query): Builder => $query
                ->where('created_by', $user->id)
                ->orWhere('author_id', $user->teamMember?->author_id ?? 0)),
            'managed' => self::managedEditorQuery($query, $user),
            'needs_approval' => self::managedEditorQuery($query, $user)
                ->whereIn('status', ['submitted', 'review', 'in_review'])
                ->whereNull('approved_at'),
            'newest' => $query->latest('created_at'),
            'recent_submissions' => $query->whereNotNull('submitted_at')->latest('submitted_at'),
            'in_review' => self::managedEditorQuery($query, $user)->whereIn('status', ['review', 'in_review']),
            'changes_requested' => self::managedEditorQuery($query, $user)->where('status', 'changes_requested'),
            'approved' => self::managedEditorQuery($query, $user)->where('status', 'approved'),
            'scheduled' => self::managedEditorQuery($query, $user)->where('status', 'scheduled'),
            'scheduled_today' => self::withinLocalDay(
                self::managedEditorQuery($query, $user)->where('status', 'scheduled'),
                'scheduled_at',
            ),
            'scheduled_this_week' => self::managedEditorQuery($query, $user)
                ->where('status', 'scheduled')
                ->whereBetween('scheduled_at', self::localWeekBounds()),
            'published_today' => self::withinLocalDay(
                self::managedEditorQuery($query, $user)->where('status', 'published'),
                'published_at',
            ),
            'latest_updated' => $query->latest('updated_at'),
            'missing_featured_image' => $query->whereNull('featured_image_id'),
            'missing_category' => $query->whereNull('category_id'),
            'missing_seo' => $query->where(fn (Builder $query): Builder => $query
                ->whereDoesntHave('seoMetadata')
                ->orWhereHas('seoMetadata', fn (Builder $seoQuery): Builder => $seoQuery
                    ->where(fn (Builder $seoQuery): Builder => $seoQuery
                        ->whereNull('seo_title')
                        ->orWhere('seo_title', ''))
                    ->orWhere(fn (Builder $seoQuery): Builder => $seoQuery
                        ->whereNull('meta_description')
                        ->orWhere('meta_description', '')))),
            'failed' => self::managedEditorQuery($query, $user)->where(fn (Builder $query): Builder => $query
                ->where('status', 'failed')
                ->orWhereNotNull('publication_failed_at')),
            'without_supervisor' => $query->whereNull('supervising_editor_id'),
            default => $query,
        };
    }

    private static function managedEditorQuery(Builder $query, User $user): Builder
    {
        if (! $user->hasRole('editor') || $user->isAdmin() || $user->hasRole('editor_in_chief')) {
            return $query;
        }

        return $query->where(fn (Builder $query): Builder => $query
            ->where('supervising_editor_id', $user->id)
            ->orWhere('assigned_editor_id', $user->id)
            ->orWhereIn(
                'author_id',
                $user->editorialSupervisions()->active()->select('author_id'),
            ));
    }

    private static function withinLocalDay(Builder $query, string $column): Builder
    {
        [$start, $end] = self::localDayBounds();

        return $query
            ->where($column, '>=', $start)
            ->where($column, '<', $end);
    }

    private static function canRunTransition(Article $record, string $ability, string $transition): bool
    {
        return (auth()->user()?->can($ability, $record) ?? false)
            && app(ArticleEditorialWorkflow::class)->canTransition($record, $transition);
    }

    private static function canPublish(Article $record): bool
    {
        $user = auth()->user();

        return ($user?->can('publish', $record) ?? false)
            && app(ArticleEditorialWorkflow::class)->canPublish($record, $user);
    }

    private static function isSubmissionOnlyUser(): bool
    {
        $user = auth()->user();

        return ! ($user !== null
            && ($user->isAdmin() || $user->hasRole('editor_in_chief') || $user->hasRole('editor'))
            && $user->hasPermission('articles.publish'))
            && (bool) ($user?->hasRole('author') || $user?->hasRole('contributor'));
    }

    /** @return array{Carbon, Carbon} */
    private static function localDayBounds(): array
    {
        $start = now(config('newsroom.timezone', 'Europe/Skopje'))->startOfDay();

        return [$start->copy()->utc(), $start->copy()->addDay()->utc()];
    }

    /** @return array{Carbon, Carbon} */
    private static function localWeekBounds(): array
    {
        $start = now(config('newsroom.timezone', 'Europe/Skopje'))->startOfWeek()->startOfDay();

        return [$start->copy()->utc(), $start->copy()->addWeek()->utc()];
    }
}
