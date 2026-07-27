<?php

namespace App\Filament\Pages;

use App\Models\Author;
use App\Models\User;
use App\Services\Newsroom\NewsroomWorkspaceService;
use App\Services\Operations\AdminAuditLogger;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Url;

class NewsroomManagedAuthors extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Редакција';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Автори';

    protected static ?string $slug = 'newsroom/authors';

    protected string $view = 'filament.pages.newsroom-managed-authors';

    #[Url(as: 'q', except: '', history: true)]
    public string $search = '';

    #[Url(as: 'status', except: 'all', history: true)]
    public string $status = 'all';

    #[Url(as: 'account', except: 'all', history: true)]
    public string $accountStatus = 'all';

    #[Url(as: 'editor', except: 'all', history: true)]
    public string $supervisingEditor = 'all';

    #[Url(as: 'department', except: 'all', history: true)]
    public string $department = 'all';

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->hasRole('editor')
            ? 'Автори што ги управувам'
            : 'Автори';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $authors = app(NewsroomWorkspaceService::class)->managedAuthors($user);
        if ($authors instanceof EloquentCollection) {
            $authors->loadMissing([
                'teamMembers.primaryDepartment',
                'teamMembers.user.roles',
            ]);
        }
        $canUseManagementFilters = $user->isAdmin() || $user->hasRole('editor_in_chief');

        $supervisingEditors = $authors
            ->pluck('primarySupervision.editor')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
        $departments = $authors
            ->flatMap(fn (Author $author) => $author->teamMembers->pluck('primaryDepartment'))
            ->filter()
            ->unique('id')
            ->sortBy('sort_order')
            ->values();

        if ($this->status !== 'all') {
            $isActive = $this->status === 'active';
            $authors = $authors->filter(fn ($author): bool => (bool) $author->is_active === $isActive);
        }

        if ($canUseManagementFilters && $this->accountStatus !== 'all') {
            $authors = $authors->filter(function (Author $author): bool {
                $account = $author->teamMembers->pluck('user')->filter()->first();

                return match ($this->accountStatus) {
                    'active' => $account?->isActive() === true,
                    'disabled' => $account instanceof User && ! $account->isActive(),
                    'unlinked' => $account === null,
                    default => true,
                };
            });
        }

        if ($canUseManagementFilters && $this->supervisingEditor !== 'all') {
            $authors = $authors->filter(fn (Author $author): bool => $this->supervisingEditor === 'unassigned'
                ? $author->primarySupervision === null
                : (string) $author->primarySupervision?->editor_id === $this->supervisingEditor);
        }

        if ($canUseManagementFilters && $this->department !== 'all') {
            $authors = $authors->filter(fn (Author $author): bool => $author->teamMembers
                ->contains(fn ($member): bool => (string) $member->primary_department_id === $this->department));
        }

        if (filled($this->search)) {
            $needle = mb_strtolower(trim($this->search));
            $authors = $authors->filter(fn ($author): bool => str_contains(mb_strtolower((string) $author->name), $needle));
        }

        return [
            'authors' => $authors->values(),
            'canUseManagementFilters' => $canUseManagementFilters,
            'supervisingEditors' => $supervisingEditors,
            'departments' => $departments,
            'drilldownContext' => array_filter([
                'q' => $this->search,
                'status' => $this->status,
                'account' => $this->accountStatus,
                'editor' => $this->supervisingEditor,
                'department' => $this->department,
            ], fn (string $value, string $key): bool => filled($value)
                && $value !== 'all', ARRAY_FILTER_USE_BOTH),
        ];
    }

    public function setAccountStatus(int $authorId, string $status): void
    {
        abort_unless(in_array($status, ['active', 'disabled'], true), 422);

        /** @var User $actor */
        $actor = auth()->user();
        abort_unless(($actor->isAdmin() || $actor->hasRole('editor_in_chief')) && $actor->hasPermission('editorial.users.manage'), 403);

        $author = app(NewsroomWorkspaceService::class)
            ->managedAuthors($actor)
            ->firstWhere('id', $authorId);
        abort_unless($author instanceof Author, 404);

        $target = $author->teamMembers
            ->pluck('user')
            ->filter()
            ->first();
        abort_unless($target instanceof User, 404);

        $before = ['status' => $target->status];
        $target->forceFill(['status' => $status])->save();

        app(AdminAuditLogger::class)->record(
            action: $status === 'active' ? 'user.reactivated' : 'user.disabled',
            auditable: $target,
            actor: $actor,
            before: $before,
            after: ['status' => $target->status],
            metadata: ['surface' => 'newsroom_managed_authors', 'author_id' => $author->id],
        );

        Notification::make()
            ->success()
            ->title($status === 'active' ? 'Корисничката сметка е активирана.' : 'Корисничката сметка е оневозможена.')
            ->send();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isAdmin()
            || ($user?->hasRole('editor_in_chief') && $user?->hasPermission('authors.manage'))
            || ($user?->hasRole('editor') && $user?->hasPermission('articles.review'))
            || false;
    }
}
