<?php

namespace App\Filament\Pages;

use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\User;
use App\Services\Articles\ArticleEditorialWorkflow;
use App\Services\Newsroom\NewsroomWorkspaceService;
use App\Services\Operations\AdminAuditLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;
use Throwable;

class NewsroomApprovalQueue extends Page
{
    use WithPagination;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Редакција';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Чекаат одобрување';

    protected static ?string $slug = 'newsroom/approval';

    protected string $view = 'filament.pages.newsroom-approval-queue';

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->hasRole('editor')
            ? 'Чекаат мое одобрување'
            : 'Чекаат одобрување';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return [
            'queue' => app(NewsroomWorkspaceService::class)->paginatedApprovalQueue($user, 20),
        ];
    }

    public function startReview(int $articleId): void
    {
        $this->transitionArticle($articleId, 'review', 'startReview', 'Прегледот е започнат.');
    }

    public function approve(int $articleId): void
    {
        $this->transitionArticle($articleId, 'approve', 'approve', 'Објавата е одобрена.');
    }

    public function requestChanges(int $articleId): void
    {
        $this->transitionArticle($articleId, 'requestChanges', 'requestChanges', 'Објавата е вратена за измени.');
    }

    public function assignEditorAction(): Action
    {
        return Action::make('assignEditor')
            ->label('Додели уредник')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->outlined()
            ->extraAttributes(['class' => 'min-h-11 w-full justify-center'])
            ->modalHeading('Додели уредник')
            ->modalDescription('Изберете активен уредник кој ќе го преземе прегледот на оваа објава.')
            ->modalSubmitActionLabel('Додели')
            ->modalCancelActionLabel('Откажи')
            ->fillForm(fn (array $arguments): array => [
                'editor_id' => $this->articleForAction((int) ($arguments['articleId'] ?? 0))->assigned_editor_id,
            ])
            ->schema([
                Select::make('editor_id')
                    ->label('Уредник')
                    ->options(fn (): array => $this->reviewerOptions())
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();
                $article = $this->articleForAction((int) ($arguments['articleId'] ?? 0));

                abort_unless($actor->hasPermission('articles.review') && $actor->can('update', $article), 403);

                $editor = $this->reviewerQuery()
                    ->findOrFail((int) $data['editor_id']);

                if ((int) $article->assigned_editor_id === (int) $editor->id) {
                    Notification::make()
                        ->info()
                        ->title('Уредникот е веќе доделен на оваа објава.')
                        ->send();

                    return;
                }

                DB::transaction(function () use ($actor, $article, $editor): void {
                    $before = ['assigned_editor_id' => $article->assigned_editor_id];

                    $article->forceFill([
                        'assigned_editor_id' => $editor->id,
                        'last_editorial_activity_at' => now(),
                    ])->save();

                    app(AdminAuditLogger::class)->record(
                        action: 'article.editor_assigned',
                        auditable: $article,
                        actor: $actor,
                        before: $before,
                        after: ['assigned_editor_id' => $editor->id],
                        metadata: [
                            'surface' => 'newsroom_approval_queue',
                            'editor_name' => $editor->name,
                        ],
                    );
                });

                Notification::make()
                    ->success()
                    ->title('Уредникот е доделен.')
                    ->body($editor->name)
                    ->send();
            });
    }

    public function addInternalNoteAction(): Action
    {
        return Action::make('addInternalNote')
            ->label('Додај интерна белешка')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('gray')
            ->outlined()
            ->extraAttributes(['class' => 'min-h-11 w-full justify-center'])
            ->modalHeading('Додај интерна белешка')
            ->modalDescription('Белешката ќе биде видлива само за редакцијата, а не и за авторот.')
            ->modalSubmitActionLabel('Зачувај белешка')
            ->modalCancelActionLabel('Откажи')
            ->schema([
                Textarea::make('note')
                    ->label('Белешка')
                    ->rows(5)
                    ->maxLength(5000)
                    ->required()
                    ->autofocus(),
                Toggle::make('is_blocking')
                    ->label('Блокира одобрување')
                    ->helperText('Вклучете само кога белешката мора да се реши пред одобрување.')
                    ->default(false),
            ])
            ->action(function (array $arguments, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();
                $article = $this->articleForAction((int) ($arguments['articleId'] ?? 0));

                abort_unless($actor->hasPermission('editorial.feedback') && $actor->can('review', $article), 403);

                DB::transaction(function () use ($actor, $article, $data): void {
                    $comment = $article->editorialComments()->create([
                        'author_id' => $actor->id,
                        'body' => trim((string) $data['note']),
                        'visibility' => ArticleEditorialComment::VISIBILITY_INTERNAL,
                        'is_blocking' => (bool) ($data['is_blocking'] ?? false),
                        'is_resolved' => false,
                    ]);

                    app(AdminAuditLogger::class)->record(
                        action: 'article.comment_added',
                        auditable: $article,
                        actor: $actor,
                        before: [],
                        after: [
                            'comment_id' => $comment->id,
                            'visibility' => $comment->visibility,
                            'is_blocking' => $comment->is_blocking,
                        ],
                        metadata: ['surface' => 'newsroom_approval_queue'],
                    );
                });

                Notification::make()
                    ->success()
                    ->title('Интерната белешка е зачувана.')
                    ->send();
            });
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->hasRole('editor_in_chief') || $user?->hasRole('editor') || $user?->isAdmin())
            && ($user?->hasPermission('articles.review') ?? false);
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $count = data_get(app(NewsroomWorkspaceService::class)->metrics($user), 'needs_approval.count', 0);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    /** @return array<int, string> */
    private function reviewerOptions(): array
    {
        return $this->reviewerQuery()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function reviewerQuery(): Builder
    {
        return User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query
                ->whereIn('name', ['editor', 'editor_in_chief'])
                ->whereHas('permissions', fn ($permissionQuery) => $permissionQuery->where('name', 'articles.review')));
    }

    private function articleForAction(int $articleId): Article
    {
        /** @var User $user */
        $user = auth()->user();

        return Article::query()
            ->visibleToEditorialUser($user)
            ->findOrFail($articleId);
    }

    private function transitionArticle(int $articleId, string $ability, string $method, string $successMessage): void
    {
        /** @var User $user */
        $user = auth()->user();
        $article = Article::query()->visibleToEditorialUser($user)->findOrFail($articleId);

        abort_unless($user->can($ability, $article), 403);

        try {
            app(ArticleEditorialWorkflow::class)->{$method}($article, $user);

            Notification::make()
                ->success()
                ->title($successMessage)
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('Дејството не беше извршено')
                ->body($exception->getMessage())
                ->send();
        }
    }
}
