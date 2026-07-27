<?php

namespace App\Filament\Pages;

use App\Models\Article;
use App\Models\User;
use App\Services\Articles\ArticleEditorialWorkflow;
use App\Services\Articles\ArticleRevisionService;
use App\Services\Newsroom\NewsroomWorkspaceService;
use App\Services\Operations\AdminAuditLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class NewsroomCalendar extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Редакција';

    protected static ?string $navigationLabel = 'Редакциски календар';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Редакциски календар';

    protected static ?string $slug = 'newsroom/calendar';

    protected string $view = 'filament.pages.newsroom-calendar';

    #[Url(as: 'view', except: 'week', history: true)]
    public string $mode = 'week';

    #[Url(as: 'date', except: '', history: true)]
    public string $focusDate = '';

    public function mount(): void
    {
        $this->focusDate = $this->focusDate ?: now(config('newsroom.timezone', 'Europe/Skopje'))->toDateString();
        $this->mode = in_array($this->mode, ['today', 'week', 'month'], true) ? $this->mode : 'week';
    }

    public function setMode(string $mode): void
    {
        if (in_array($mode, ['today', 'week', 'month'], true)) {
            $this->mode = $mode;
        }
    }

    public function previousPeriod(): void
    {
        $date = $this->focus();
        $this->focusDate = match ($this->mode) {
            'today' => $date->subDay()->toDateString(),
            'month' => $date->subMonthNoOverflow()->toDateString(),
            default => $date->subWeek()->toDateString(),
        };
    }

    public function nextPeriod(): void
    {
        $date = $this->focus();
        $this->focusDate = match ($this->mode) {
            'today' => $date->addDay()->toDateString(),
            'month' => $date->addMonthNoOverflow()->toDateString(),
            default => $date->addWeek()->toDateString(),
        };
    }

    public function goToday(): void
    {
        $this->focusDate = now(config('newsroom.timezone', 'Europe/Skopje'))->toDateString();
    }

    public function rescheduleAction(): Action
    {
        return Action::make('reschedule')
            ->label('Закажи термин')
            ->modalHeading(fn (array $arguments): string => $this->articleForAction($arguments, 'schedule')->scheduled_at
                ? 'Презакажи ја објавата'
                : 'Закажи ја објавата')
            ->modalSubmitActionLabel('Зачувај термин')
            ->fillForm(function (array $arguments): array {
                $article = $this->articleForAction($arguments, 'schedule');

                return ['scheduled_at' => $article->scheduled_at];
            })
            ->schema([
                DateTimePicker::make('scheduled_at')
                    ->label('Датум и време на објавување')
                    ->timezone(config('newsroom.timezone', 'Europe/Skopje'))
                    ->native(false)
                    ->seconds(false)
                    ->after(now())
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $article = $this->articleForAction($arguments, 'schedule');

                app(ArticleEditorialWorkflow::class)->schedule($article, $data['scheduled_at'], auth()->user());

                Notification::make()
                    ->success()
                    ->title('Терминот за објавување е зачуван.')
                    ->send();
            });
    }

    public function publishNowAction(): Action
    {
        return Action::make('publishNow')
            ->label('Објави сега')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Објави ја објавата сега?')
            ->modalDescription('Закажаниот термин ќе се отстрани и објавата веднаш ќе стане јавна.')
            ->modalSubmitActionLabel('Објави сега')
            ->action(function (array $arguments): void {
                $article = $this->articleForAction($arguments, 'publish');

                app(ArticleEditorialWorkflow::class)->publish($article, auth()->user());

                Notification::make()
                    ->success()
                    ->title('Објавата е објавена.')
                    ->send();
            });
    }

    public function assignEditorAction(): Action
    {
        return Action::make('assignEditor')
            ->label('Додели уредник')
            ->modalHeading('Додели одговорен уредник')
            ->modalSubmitActionLabel('Зачувај уредник')
            ->fillForm(function (array $arguments): array {
                $article = $this->articleForEditorAssignment($arguments);

                return ['assigned_editor_id' => $article->assigned_editor_id];
            })
            ->schema([
                Select::make('assigned_editor_id')
                    ->label('Уредник')
                    ->options(fn (): array => User::query()
                        ->where('status', 'active')
                        ->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['editor', 'editor_in_chief']))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                /** @var User $actor */
                $actor = auth()->user();
                $article = $this->articleForEditorAssignment($arguments);
                $editor = User::query()
                    ->whereKey((int) $data['assigned_editor_id'])
                    ->where('status', 'active')
                    ->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['editor', 'editor_in_chief']))
                    ->firstOrFail();

                DB::transaction(function () use ($actor, $article, $editor): void {
                    $locked = Article::query()->lockForUpdate()->findOrFail($article->id);
                    abort_unless($actor->can('update', $locked), 403);

                    $before = ['assigned_editor_id' => $locked->assigned_editor_id];
                    $locked->forceFill([
                        'assigned_editor_id' => $editor->id,
                        'updated_by' => $actor->id,
                        'last_editorial_activity_at' => now(),
                    ])->save();

                    app(AdminAuditLogger::class)->record(
                        action: 'article.editor_assigned',
                        auditable: $locked,
                        actor: $actor,
                        before: $before,
                        after: ['assigned_editor_id' => $editor->id],
                        metadata: ['surface' => 'newsroom_calendar'],
                    );
                    app(ArticleRevisionService::class)->capture($locked, $actor, 'article.editor_assigned');
                });

                Notification::make()
                    ->success()
                    ->title('Уредникот е доделен.')
                    ->send();
            });
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $focus = $this->focus();
        [$from, $to] = match ($this->mode) {
            'today' => [$focus->copy()->startOfDay(), $focus->copy()->endOfDay()],
            'month' => [$focus->copy()->startOfMonth()->startOfWeek(), $focus->copy()->endOfMonth()->endOfWeek()],
            default => [$focus->copy()->startOfWeek(), $focus->copy()->endOfWeek()],
        };
        $events = app(NewsroomWorkspaceService::class)->calendar($user, $from, $to);

        return [
            'from' => $from,
            'to' => $to,
            'events' => $events,
            'eventsByDate' => $events->groupBy(function (Article $article): string {
                $eventAt = $article->status === 'failed'
                    ? ($article->publication_failed_at ?? $article->scheduled_at ?? $article->updated_at)
                    : ($article->scheduled_at ?? $article->approved_at ?? $article->updated_at);

                return $eventAt->timezone(config('newsroom.timezone', 'Europe/Skopje'))->toDateString();
            }),
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isAdmin()
            || (($user?->hasRole('editor_in_chief') || $user?->hasRole('editor'))
                && $user?->hasPermission('articles.view'))
            || false;
    }

    private function focus(): Carbon
    {
        return Carbon::parse($this->focusDate, config('newsroom.timezone', 'Europe/Skopje'));
    }

    /** @param array<string, mixed> $arguments */
    private function articleForAction(array $arguments, string $ability): Article
    {
        /** @var User $actor */
        $actor = auth()->user();
        $articleId = filter_var($arguments['article'] ?? null, FILTER_VALIDATE_INT);
        abort_unless($articleId !== false, 404);

        $article = Article::query()
            ->visibleToEditorialUser($actor)
            ->findOrFail((int) $articleId);
        abort_unless($actor->can($ability, $article), 403);

        return $article;
    }

    /** @param array<string, mixed> $arguments */
    private function articleForEditorAssignment(array $arguments): Article
    {
        /** @var User $actor */
        $actor = auth()->user();
        abort_unless(
            $actor->isAdmin()
                || ($actor->hasRole('editor_in_chief') && $actor->hasPermission('editorial.users.manage')),
            403,
        );

        return $this->articleForAction($arguments, 'update');
    }
}
