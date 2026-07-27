<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Newsroom\NewsroomWorkspaceService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class NewsroomActivity extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|\UnitEnum|null $navigationGroup = 'Редакција';

    protected static ?string $navigationLabel = 'Редакциска активност';

    protected static ?int $navigationSort = 50;

    protected static ?string $title = 'Редакциска активност';

    protected static ?string $slug = 'newsroom/activity';

    protected string $view = 'filament.pages.newsroom-activity';

    #[Url(as: 'action', except: 'all', history: true)]
    public string $action = 'all';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $activity = app(NewsroomWorkspaceService::class)->activity($user, 80);

        if ($this->action !== 'all') {
            $activity = $activity->filter(fn ($log): bool => str_starts_with((string) $log->action, "article.{$this->action}"));
        }

        return [
            'activity' => $activity->values(),
            'activityGroups' => $activity->groupBy(fn ($log): string => $log->created_at->timezone(config('newsroom.timezone', 'Europe/Skopje'))->toDateString()),
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
}
