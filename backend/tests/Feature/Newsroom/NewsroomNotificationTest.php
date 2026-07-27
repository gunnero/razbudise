<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Livewire\NewsroomDatabaseNotifications;
use App\Models\Article;
use App\Models\Author;
use App\Models\EditorialSupervision;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Articles\ArticleEditorialWorkflow;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use App\Services\Newsroom\NewsroomNotificationService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NewsroomNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_uses_grouped_durable_database_notifications(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasDatabaseNotifications());
        $this->assertSame(NewsroomDatabaseNotifications::class, $panel->getDatabaseNotificationsLivewireComponent());
        $this->assertSame('15s', $panel->getDatabaseNotificationsPollingInterval());
    }

    public function test_submission_notifies_only_role_appropriate_reviewers_with_a_direct_link(): void
    {
        $this->seedRoles();
        $authorUser = $this->userWithRole('author');
        $managedEditor = $this->userWithRole('editor');
        $unrelatedEditor = $this->userWithRole('editor');
        $editorInChief = $this->userWithRole('editor_in_chief');
        $author = Author::factory()->create(['is_active' => true]);
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $author->id]);
        EditorialSupervision::factory()->create([
            'editor_id' => $managedEditor->id,
            'author_id' => $author->id,
            'ends_at' => null,
        ]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'draft',
            'created_by' => $authorUser->id,
        ]);

        app(ArticleEditorialWorkflow::class)->submit($article, $authorUser);

        $this->assertCount(1, $managedEditor->notifications()->get());
        $this->assertCount(1, $editorInChief->notifications()->get());
        $this->assertCount(0, $unrelatedEditor->notifications()->get());
        $this->assertCount(0, $authorUser->notifications()->get());

        $notification = $managedEditor->notifications()->firstOrFail();
        $this->assertNull($notification->read_at);
        $this->assertSame('filament', $notification->data['format']);
        $this->assertSame(
            NewsroomNotificationService::NEEDS_ACTION,
            data_get($notification->data, 'viewData.newsroom_category'),
        );
        $this->assertStringContainsString(
            "/backoffice/articles/{$article->id}/edit",
            data_get($notification->data, 'actions.0.url'),
        );
        $this->assertTrue(data_get($notification->data, 'actions.0.shouldMarkAsRead'));
    }

    public function test_changes_requested_notifies_the_linked_author_as_needs_action(): void
    {
        $this->seedRoles();
        $authorUser = $this->userWithRole('author');
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create(['is_active' => true]);
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $author->id]);
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $author->id,
            'ends_at' => null,
        ]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'in_review',
            'submitted_by' => $authorUser->id,
        ]);

        app(ArticleEditorialWorkflow::class)->requestChanges($article, $editor);

        $notification = $authorUser->notifications()->firstOrFail();
        $this->assertSame('Потребна доработка', $notification->data['title']);
        $this->assertSame(
            NewsroomNotificationService::NEEDS_ACTION,
            data_get($notification->data, 'viewData.newsroom_category'),
        );
    }

    public function test_account_events_are_durable_and_grouped_in_the_accessible_drawer(): void
    {
        $this->seedRoles();
        $user = $this->userWithRole('author');
        $service = app(NewsroomNotificationService::class);
        $service->accountCreated($user);
        $service->accountUpdated($user, ['roles', 'password']);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertSame(2, $user->unreadNotifications()->count());
        $this->assertTrue($user->notifications()->get()->every(
            fn ($notification): bool => data_get($notification->data, 'viewData.newsroom_category') === NewsroomNotificationService::ACCOUNT_EVENTS,
        ));

        $component = Livewire::test(NewsroomDatabaseNotifications::class)
            ->assertOk()
            ->assertSee('Известувања')
            ->assertSee('Сметка')
            ->assertSee('2 непрочитани известувања')
            ->assertSee('Добредојдовте во редакцијата на RazbudiSe')
            ->assertSee('Вашата корисничка сметка е ажурирана');

        $component->call('markNotificationAsRead', $user->notifications()->firstOrFail()->id);
        $this->assertSame(1, $user->unreadNotifications()->count());
    }

    public function test_notification_drawer_renders_all_four_groups_in_a_stable_order(): void
    {
        $this->seedRoles();
        $user = $this->userWithRole('editor');

        foreach ([
            NewsroomNotificationService::NEEDS_ACTION,
            NewsroomNotificationService::EDITORIAL_UPDATES,
            NewsroomNotificationService::PUBLICATION_EVENTS,
            NewsroomNotificationService::ACCOUNT_EVENTS,
        ] as $index => $category) {
            Notification::make()
                ->title('Group '.$index)
                ->viewData(['newsroom_category' => $category])
                ->sendToDatabase($user);
        }

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(NewsroomDatabaseNotifications::class)
            ->assertOk()
            ->assertSeeInOrder([
                'Потребно дејство',
                'Уреднички новости',
                'Објавување',
                'Сметка',
            ]);
    }

    public function test_user_admin_lifecycle_creates_account_notifications(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $authorRole = Role::query()->where('name', 'author')->firstOrFail();
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Newsroom Account',
                'email' => 'newsroom-account@example.test',
                'locale' => 'mk',
                'status' => 'active',
                'password' => 'Newsroom-Account-2026!',
                'roles' => [$authorRole->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'newsroom-account@example.test')->firstOrFail();
        $this->assertSame('Добредојдовте во редакцијата на RazbudiSe', $user->notifications()->firstOrFail()->data['title']);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm([
                'name' => $user->name,
                'email' => $user->email,
                'locale' => 'mk',
                'status' => 'suspended',
                'password' => null,
                'roles' => [$authorRole->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $updated = $user->refresh();
        $this->assertSame('suspended', $updated->status);
        $this->assertSame(2, $updated->notifications()->count());
        $this->assertTrue($updated->notifications()->get()->contains(
            fn ($notification): bool => $notification->data['title'] === 'Вашата корисничка сметка е ажурирана',
        ));
    }

    private function seedRoles(): void
    {
        app(RazbudiseRolePermissionMatrix::class)->sync();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
