<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Pages\NewsroomActivity;
use App\Filament\Pages\NewsroomCalendar;
use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\Author;
use App\Models\EditorialSupervision;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use App\Services\Newsroom\NewsroomActivityPresenter;
use App\Services\Newsroom\NewsroomWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NewsroomActivityAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(RazbudiseRolePermissionMatrix::class)->sync();
    }

    public function test_every_newsroom_audit_action_has_one_canonical_event_mapping(): void
    {
        $expected = [
            'article.editor_assigned',
            'article.comment_added',
            'article.comment_replied',
            'user.reactivated',
            'author.profile_updated',
            'author.self_profile_updated',
            'editorial_supervision.created',
            'editorial_supervision.updated',
        ];

        $events = config('newsroom.activity_events');

        $this->assertSame(array_keys($events), config('newsroom.activity_actions'));

        foreach ($expected as $action) {
            $this->assertIsString($events[$action]['label'] ?? null, "Missing label for {$action}");
            $this->assertIsString($events[$action]['icon'] ?? null, "Missing icon for {$action}");
        }

        $this->assertArrayNotHasKey('article.comment_created', $events);
    }

    public function test_editor_activity_contains_only_managed_articles_authors_users_and_supervisions(): void
    {
        $editor = $this->userWithRole('editor');
        $managedAuthor = Author::factory()->create(['name' => 'Авторка во опсег']);
        $unrelatedAuthor = Author::factory()->create(['name' => 'Неповрзана авторка']);
        $managedUser = $this->userWithRole('author');
        $unrelatedUser = $this->userWithRole('author');
        TeamMember::factory()->create(['user_id' => $managedUser->id, 'author_id' => $managedAuthor->id]);
        TeamMember::factory()->create(['user_id' => $unrelatedUser->id, 'author_id' => $unrelatedAuthor->id]);
        $managedSupervision = EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $managedAuthor->id,
        ]);
        $unrelatedSupervision = EditorialSupervision::factory()->create([
            'editor_id' => $this->userWithRole('editor')->id,
            'author_id' => $unrelatedAuthor->id,
        ]);
        $managedArticle = Article::factory()->create(['author_id' => $managedAuthor->id]);
        $unrelatedArticle = Article::factory()->create(['author_id' => $unrelatedAuthor->id]);

        $visibleLogs = [
            $this->audit($editor, $managedArticle, 'article.submitted'),
            $this->audit($editor, $managedAuthor, 'author.profile_updated'),
            $this->audit($editor, $managedUser, 'user.disabled'),
            $this->audit($editor, $managedSupervision, 'editorial_supervision.updated'),
        ];
        $hiddenLogs = [
            $this->audit($editor, $unrelatedArticle, 'article.submitted'),
            $this->audit($editor, $unrelatedAuthor, 'author.profile_updated'),
            $this->audit($editor, $unrelatedUser, 'user.disabled'),
            $this->audit($editor, $unrelatedSupervision, 'editorial_supervision.updated'),
        ];

        $activityIds = app(NewsroomWorkspaceService::class)->activity($editor)->modelKeys();

        $this->assertEqualsCanonicalizing(collect($visibleLogs)->pluck('id')->all(), $activityIds);
        $this->assertEmpty(array_intersect(collect($hiddenLogs)->pluck('id')->all(), $activityIds));
    }

    public function test_editor_in_chief_activity_is_global_and_non_article_events_have_direct_destinations(): void
    {
        $eic = $this->userWithRole('editor_in_chief');
        $editor = $this->userWithRole('editor', ['name' => 'Одговорен уредник']);
        $author = Author::factory()->create(['name' => 'Авторка со профил']);
        $supervision = EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $author->id,
        ]);
        $profileLog = $this->audit($eic, $author, 'author.profile_updated');
        $userLog = $this->audit($eic, $editor, 'user.reactivated');
        $supervisionLog = $this->audit($eic, $supervision, 'editorial_supervision.updated');

        $this->actingAs($eic);
        $activity = app(NewsroomWorkspaceService::class)->activity($eic);
        $presenter = app(NewsroomActivityPresenter::class);

        $this->assertEqualsCanonicalizing(
            [$profileLog->id, $userLog->id, $supervisionLog->id],
            $activity->modelKeys(),
        );

        $profileItem = $presenter->present($activity->firstWhere('id', $profileLog->id), $eic);
        $userItem = $presenter->present($activity->firstWhere('id', $userLog->id), $eic);
        $supervisionItem = $presenter->present($activity->firstWhere('id', $supervisionLog->id), $eic);

        $this->assertSame('ажурираше авторски профил', $profileItem['label']);
        $this->assertStringContainsString("/authors/{$author->id}/edit", $profileItem['url']);
        $this->assertSame('активираше корисничка сметка', $userItem['label']);
        $this->assertStringContainsString("/users/{$editor->id}/edit", $userItem['url']);
        $this->assertSame($author->name, $supervisionItem['subject']);
        $this->assertStringContainsString("/authors/{$author->id}/edit", $supervisionItem['url']);

        Livewire::test(NewsroomActivity::class)
            ->assertSee('ажурираше авторски профил')
            ->assertSee('активираше корисничка сметка')
            ->assertSee('ажурираше уреднички надзор')
            ->assertSee("/authors/{$author->id}/edit", false)
            ->assertSee("/users/{$editor->id}/edit", false);
    }

    public function test_calendar_and_activity_require_view_permission_for_editorial_roles(): void
    {
        foreach (['editor', 'editor_in_chief'] as $roleName) {
            $user = $this->userWithRole($roleName);
            $this->actingAs($user);

            $this->assertTrue(NewsroomCalendar::canAccess());
            $this->assertTrue(NewsroomActivity::canAccess());

            Role::query()
                ->where('name', $roleName)
                ->firstOrFail()
                ->permissions()
                ->detach(Permission::query()->where('name', 'articles.view')->firstOrFail());

            $this->assertFalse(NewsroomCalendar::canAccess());
            $this->assertFalse(NewsroomActivity::canAccess());
        }
    }

    /** @param array<string, mixed> $attributes */
    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    private function audit(User $actor, Article|Author|EditorialSupervision|User $subject, string $action): AdminAuditLog
    {
        return AdminAuditLog::query()->create([
            'actor_id' => $actor->id,
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'action' => $action,
        ]);
    }
}
