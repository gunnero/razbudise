<?php

namespace App\Filament\Navigation;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\NewsroomActivity;
use App\Filament\Pages\NewsroomApprovalQueue;
use App\Filament\Pages\NewsroomCalendar;
use App\Filament\Pages\NewsroomManagedAuthors;
use App\Filament\Pages\NewsroomManagedPosts;
use App\Filament\Resources\Articles\ArticleResource;
use App\Models\User;
use Closure;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

use function Filament\Support\original_request;

final class NewsroomNavigation
{
    private const string EDITORIAL_GROUP = 'Редакција';

    private const string AUTHOR_GROUP = 'Мои објави';

    /**
     * @return array<int, NavigationItem>
     */
    public static function items(): array
    {
        return [
            ...self::editorInChiefItems(),
            ...self::editorItems(),
            ...self::authorItems(),
            self::pageItem(
                label: 'Редакциски календар',
                page: NewsroomCalendar::class,
                icon: Heroicon::OutlinedCalendarDays,
                sort: 140,
                roles: ['administrator'],
                group: self::EDITORIAL_GROUP,
            ),
        ];
    }

    /**
     * @return array<int, NavigationItem>
     */
    private static function editorInChiefItems(): array
    {
        $roles = ['administrator', 'editor_in_chief'];

        return [
            self::pageItem('Преглед', Dashboard::class, Heroicon::OutlinedHome, 10, $roles, self::EDITORIAL_GROUP),
            self::pageItem(
                'Чекаат одобрување',
                NewsroomApprovalQueue::class,
                Heroicon::OutlinedClipboardDocumentCheck,
                20,
                $roles,
                self::EDITORIAL_GROUP,
                badge: fn (): ?string => NewsroomApprovalQueue::getNavigationBadge(),
            ),
            self::pageItem('Објави што ги управувам', NewsroomManagedPosts::class, Heroicon::OutlinedDocumentText, 30, $roles, self::EDITORIAL_GROUP),
            self::articleItem('Сите објави', Heroicon::OutlinedFolderOpen, 40, $roles),
            self::articleQueueItem('На преглед', 'in_review', Heroicon::OutlinedPencilSquare, 50, $roles),
            self::articleQueueItem('Потребна доработка', 'changes_requested', Heroicon::OutlinedArrowPath, 60, $roles),
            self::articleQueueItem('Одобрени', 'approved', Heroicon::OutlinedCheckCircle, 70, $roles),
            self::articleQueueItem('Закажани', 'scheduled', Heroicon::OutlinedCalendarDays, 80, $roles),
            self::articleQueueItem('Објавени денес', 'published_today', Heroicon::OutlinedPaperAirplane, 90, $roles),
            self::articleStatusItem('Архива', 'archived', Heroicon::OutlinedArchiveBox, 100, $roles),
            self::pageItem('Автори', NewsroomManagedAuthors::class, Heroicon::OutlinedUsers, 110, $roles, self::EDITORIAL_GROUP),
            self::pageItem('Активност', NewsroomActivity::class, Heroicon::OutlinedClock, 130, $roles, self::EDITORIAL_GROUP),
        ];
    }

    /**
     * @return array<int, NavigationItem>
     */
    private static function editorItems(): array
    {
        $roles = ['editor'];

        return [
            self::pageItem('Преглед', Dashboard::class, Heroicon::OutlinedHome, 10, $roles, self::EDITORIAL_GROUP),
            self::pageItem(
                'Чекаат мое одобрување',
                NewsroomApprovalQueue::class,
                Heroicon::OutlinedClipboardDocumentCheck,
                20,
                $roles,
                self::EDITORIAL_GROUP,
                badge: fn (): ?string => NewsroomApprovalQueue::getNavigationBadge(),
            ),
            self::pageItem('Објави што ги управувам', NewsroomManagedPosts::class, Heroicon::OutlinedDocumentText, 30, $roles, self::EDITORIAL_GROUP),
            self::articleQueueItem('Мои објави', 'my_posts', Heroicon::OutlinedFolderOpen, 40, $roles),
            self::articleQueueItem('На преглед', 'in_review', Heroicon::OutlinedPencilSquare, 50, $roles),
            self::articleQueueItem('Потребна доработка', 'changes_requested', Heroicon::OutlinedArrowPath, 60, $roles),
            self::articleQueueItem('Одобрени', 'approved', Heroicon::OutlinedCheckCircle, 70, $roles),
            self::articleQueueItem('Закажани', 'scheduled', Heroicon::OutlinedCalendarDays, 80, $roles),
            self::articleStatusItem('Неодамна објавени', 'published', Heroicon::OutlinedPaperAirplane, 90, $roles),
            self::pageItem('Автори што ги управувам', NewsroomManagedAuthors::class, Heroicon::OutlinedUsers, 100, $roles, self::EDITORIAL_GROUP),
        ];
    }

    /**
     * @return array<int, NavigationItem>
     */
    private static function authorItems(): array
    {
        $roles = ['author', 'contributor'];

        return [
            self::pageItem('Преглед', Dashboard::class, Heroicon::OutlinedHome, 10, $roles, self::AUTHOR_GROUP),
            self::articleStatusItem('Работни верзии', 'draft', Heroicon::OutlinedDocumentText, 20, $roles, self::AUTHOR_GROUP),
            self::articleStatusItem('Испратени', 'submitted', Heroicon::OutlinedPaperAirplane, 30, $roles, self::AUTHOR_GROUP),
            self::articleStatusItem('Потребна доработка', 'changes_requested', Heroicon::OutlinedArrowPath, 40, $roles, self::AUTHOR_GROUP),
            self::articleStatusItem('Одобрени', 'approved', Heroicon::OutlinedCheckCircle, 50, $roles, self::AUTHOR_GROUP),
            self::articleStatusItem('Закажани', 'scheduled', Heroicon::OutlinedCalendarDays, 60, $roles, self::AUTHOR_GROUP),
            self::articleStatusItem('Објавени', 'published', Heroicon::OutlinedFolderOpen, 70, $roles, self::AUTHOR_GROUP),
        ];
    }

    /**
     * @param  class-string<Page>  $page
     * @param  array<int, string>  $roles
     */
    private static function pageItem(
        string $label,
        string $page,
        Heroicon $icon,
        int $sort,
        array $roles,
        string $group,
        ?Closure $badge = null,
    ): NavigationItem {
        return NavigationItem::make($label)
            ->group($group)
            ->icon($icon)
            ->sort($sort)
            ->badge($badge)
            ->visible(fn (): bool => self::roleIs($roles) && self::canAccess($page, fn (): bool => $page::canAccess()))
            ->url(fn (): string => $page::getUrl())
            ->isActiveWhen(fn (): bool => original_request()->routeIs($page::getRouteName()));
    }

    /**
     * @param  array<int, string>  $roles
     */
    private static function articleItem(string $label, Heroicon $icon, int $sort, array $roles): NavigationItem
    {
        return NavigationItem::make($label)
            ->group(self::EDITORIAL_GROUP)
            ->icon($icon)
            ->sort($sort)
            ->visible(fn (): bool => self::roleIs($roles) && self::canAccess(ArticleResource::class, fn (): bool => ArticleResource::canAccess()))
            ->url(fn (): string => ArticleResource::getUrl('index', [
                'resetFilters' => 1,
                'filters' => [
                    'editorial_queue' => ['value' => ''],
                ],
            ]))
            ->isActiveWhen(fn (): bool => self::isArticleRoute() && ! self::hasArticleFilter());
    }

    /**
     * @param  array<int, string>  $roles
     */
    private static function articleQueueItem(
        string $label,
        string $queue,
        Heroicon $icon,
        int $sort,
        array $roles,
        string $group = self::EDITORIAL_GROUP,
    ): NavigationItem {
        return NavigationItem::make($label)
            ->group($group)
            ->icon($icon)
            ->sort($sort)
            ->visible(fn (): bool => self::roleIs($roles) && self::canAccess(ArticleResource::class, fn (): bool => ArticleResource::canAccess()))
            ->url(fn (): string => ArticleResource::getUrl('index', [
                'filters' => [
                    'editorial_queue' => ['value' => $queue],
                ],
            ]))
            ->isActiveWhen(fn (): bool => self::isArticleRoute()
                && data_get(original_request()->query(), 'filters.editorial_queue.value') === $queue);
    }

    /**
     * @param  array<int, string>  $roles
     */
    private static function articleStatusItem(
        string $label,
        string $status,
        Heroicon $icon,
        int $sort,
        array $roles,
        string $group = self::EDITORIAL_GROUP,
    ): NavigationItem {
        return NavigationItem::make($label)
            ->group($group)
            ->icon($icon)
            ->sort($sort)
            ->visible(fn (): bool => self::roleIs($roles) && self::canAccess(ArticleResource::class, fn (): bool => ArticleResource::canAccess()))
            ->url(fn (): string => ArticleResource::getUrl('index', [
                'filters' => [
                    'status' => ['value' => $status],
                ],
            ]))
            ->isActiveWhen(fn (): bool => self::isArticleRoute()
                && data_get(original_request()->query(), 'filters.status.value') === $status);
    }

    /**
     * @param  array<int, string>  $roles
     */
    private static function roleIs(array $roles): bool
    {
        return in_array(self::currentRole(), $roles, true);
    }

    private static function canAccess(string $surface, Closure $resolve): bool
    {
        $cacheKey = 'newsroom.navigation.access.'.sha1($surface.'|'.(string) auth()->id());
        $request = request();

        if ($request->attributes->has($cacheKey)) {
            return (bool) $request->attributes->get($cacheKey);
        }

        $allowed = (bool) $resolve();
        $request->attributes->set($cacheKey, $allowed);

        return $allowed;
    }

    private static function currentRole(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->isActive()) {
            return null;
        }

        $user->loadMissing('roles');
        $roleNames = $user->roles->pluck('name');

        return match (true) {
            $roleNames->contains(fn (string $role): bool => in_array($role, ['admin', 'super_admin'], true)) => 'administrator',
            $roleNames->contains('editor_in_chief') => 'editor_in_chief',
            $roleNames->contains('editor') => 'editor',
            $roleNames->contains('author') => 'author',
            $roleNames->contains('contributor') => 'contributor',
            default => null,
        };
    }

    private static function isArticleRoute(): bool
    {
        return original_request()->routeIs(ArticleResource::getRouteBaseName().'.*');
    }

    private static function hasArticleFilter(): bool
    {
        return filled(data_get(original_request()->query(), 'filters.editorial_queue.value'))
            || filled(data_get(original_request()->query(), 'filters.status.value'));
    }
}
