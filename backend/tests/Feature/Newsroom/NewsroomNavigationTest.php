<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Navigation\NewsroomNavigation;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\NewsroomActivity;
use App\Filament\Pages\NewsroomApprovalQueue;
use App\Filament\Pages\NewsroomCalendar;
use App\Filament\Pages\NewsroomManagedAuthors;
use App\Filament\Pages\NewsroomManagedPosts;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

class NewsroomNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(RazbudiseRolePermissionMatrix::class)->sync();
    }

    public function test_editor_in_chief_sees_the_exact_global_newsroom_navigation(): void
    {
        $this->actingAs($this->userWithRole('editor_in_chief'));

        $items = $this->visibleItems();

        $this->assertSame([
            'Преглед',
            'Чекаат одобрување',
            'Објави што ги управувам',
            'Сите објави',
            'На преглед',
            'Потребна доработка',
            'Одобрени',
            'Закажани',
            'Објавени денес',
            'Архива',
            'Автори',
            'Активност',
        ], $items->map->getLabel()->all());
        $this->assertSame(['Редакција'], $items->map->getGroup()->unique()->values()->all());

        $this->assertFilter($items, 'На преглед', 'editorial_queue', 'in_review');
        $this->assertFilter($items, 'Потребна доработка', 'editorial_queue', 'changes_requested');
        $this->assertFilter($items, 'Одобрени', 'editorial_queue', 'approved');
        $this->assertFilter($items, 'Закажани', 'editorial_queue', 'scheduled');
        $this->assertFilter($items, 'Објавени денес', 'editorial_queue', 'published_today');
        $this->assertFilter($items, 'Архива', 'status', 'archived');
        $this->assertNotContains('Пристап на уредници и автори', $items->map->getLabel());
    }

    public function test_editor_sees_only_assigned_newsroom_queues(): void
    {
        $this->actingAs($this->userWithRole('editor'));

        $items = $this->visibleItems();

        $this->assertSame([
            'Преглед',
            'Чекаат мое одобрување',
            'Објави што ги управувам',
            'Мои објави',
            'На преглед',
            'Потребна доработка',
            'Одобрени',
            'Закажани',
            'Неодамна објавени',
            'Автори што ги управувам',
        ], $items->map->getLabel()->all());
        $this->assertSame(['Редакција'], $items->map->getGroup()->unique()->values()->all());
        $this->assertNotContains('Сите објави', $items->map->getLabel());
        $this->assertNotContains('Пристап на уредници и автори', $items->map->getLabel());
        $this->assertNotContains('Активност', $items->map->getLabel());

        $this->assertFilter($items, 'Мои објави', 'editorial_queue', 'my_posts');
        $this->assertFilter($items, 'Неодамна објавени', 'status', 'published');
    }

    public function test_author_and_contributor_see_only_their_personal_queues(): void
    {
        foreach (['author', 'contributor'] as $role) {
            $this->actingAs($this->userWithRole($role));

            $items = $this->visibleItems();

            $this->assertSame([
                'Преглед',
                'Работни верзии',
                'Испратени',
                'Потребна доработка',
                'Одобрени',
                'Закажани',
                'Објавени',
            ], $items->map->getLabel()->all());
            $this->assertSame(['Мои објави'], $items->map->getGroup()->unique()->values()->all());

            $this->assertFilter($items, 'Работни верзии', 'status', 'draft');
            $this->assertFilter($items, 'Испратени', 'status', 'submitted');
            $this->assertFilter($items, 'Објавени', 'status', 'published');
        }
    }

    public function test_administrator_gets_the_global_newsroom_and_calendar(): void
    {
        $this->actingAs($this->userWithRole('super_admin'));

        $labels = $this->visibleItems()->map->getLabel();

        $this->assertContains('Сите објави', $labels);
        $this->assertNotContains('Пристап на уредници и автори', $labels);
        $this->assertContains('Активност', $labels);
        $this->assertContains('Редакциски календар', $labels);
        $this->assertNotContains('Чекаат мое одобрување', $labels);
    }

    public function test_shared_newsroom_items_replace_auto_registered_duplicates_without_removing_routes(): void
    {
        $this->assertFalse(Dashboard::shouldRegisterNavigation());
        $this->assertFalse(NewsroomApprovalQueue::shouldRegisterNavigation());
        $this->assertFalse(NewsroomManagedPosts::shouldRegisterNavigation());
        $this->assertFalse(NewsroomManagedAuthors::shouldRegisterNavigation());
        $this->assertFalse(NewsroomCalendar::shouldRegisterNavigation());
        $this->assertFalse(NewsroomActivity::shouldRegisterNavigation());
        $this->assertFalse(ArticleResource::shouldRegisterNavigation());

        $this->assertStringEndsWith('/backoffice', Dashboard::getUrl());
        $this->assertStringEndsWith('/backoffice/newsroom/approval', NewsroomApprovalQueue::getUrl());
        $this->assertStringEndsWith('/backoffice/newsroom/posts', NewsroomManagedPosts::getUrl());
        $this->assertStringEndsWith('/backoffice/newsroom/authors', NewsroomManagedAuthors::getUrl());
        $this->assertStringEndsWith('/backoffice/newsroom/calendar', NewsroomCalendar::getUrl());
        $this->assertStringEndsWith('/backoffice/newsroom/activity', NewsroomActivity::getUrl());
        $this->assertStringEndsWith('/backoffice/articles', ArticleResource::getUrl('index'));
    }

    public function test_users_editorial_access_filter_lists_editors_authors_and_contributors(): void
    {
        $administrator = $this->userWithRole('super_admin');
        $editorInChief = $this->userWithRole('editor_in_chief');
        $editor = $this->userWithRole('editor');
        $author = $this->userWithRole('author');
        $author->update(['name' => 'Romina Markovska']);
        $contributor = $this->userWithRole('contributor');
        $photographer = $this->userWithRole('photographer');

        $this->actingAs($administrator);

        Livewire::withQueryParams([
            'filters' => [
                'editorial_access' => ['isActive' => true],
            ],
        ])
            ->test(ListUsers::class)
            ->assertCanSeeTableRecords([$editorInChief, $editor, $author, $contributor])
            ->assertCanNotSeeTableRecords([$administrator, $photographer]);
    }

    /**
     * @return Collection<int, NavigationItem>
     */
    private function visibleItems(): Collection
    {
        return collect(NewsroomNavigation::items())
            ->filter(fn (NavigationItem $item): bool => $item->isVisible())
            ->sortBy(fn (NavigationItem $item): int => $item->getSort())
            ->values();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    /**
     * @param  Collection<int, NavigationItem>  $items
     */
    private function assertFilter(Collection $items, string $label, string $filter, string $expected): void
    {
        $query = $this->queryFor($items, $label);

        $this->assertArrayNotHasKey('tableFilters', $query);
        $this->assertSame($expected, $query['filters'][$filter]['value'] ?? null);
    }

    /**
     * @param  Collection<int, NavigationItem>  $items
     * @return array<string, mixed>
     */
    private function queryFor(Collection $items, string $label): array
    {
        $item = $items->first(fn (NavigationItem $item): bool => $item->getLabel() === $label);
        $this->assertInstanceOf(NavigationItem::class, $item);

        parse_str((string) parse_url((string) $item->getUrl(), PHP_URL_QUERY), $query);

        return $query;
    }
}
