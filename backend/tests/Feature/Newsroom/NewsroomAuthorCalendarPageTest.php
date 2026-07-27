<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Pages\NewsroomCalendar;
use App\Filament\Pages\NewsroomManagedAuthors;
use App\Filament\Pages\NewsroomManagedPosts;
use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Author;
use App\Models\EditorialSupervision;
use App\Models\Role;
use App\Models\TeamDepartment;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class NewsroomAuthorCalendarPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-21 08:00:00', 'UTC'));
        app(RazbudiseRolePermissionMatrix::class)->sync();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_editor_in_chief_can_filter_authors_by_supervisor_account_profile_and_department(): void
    {
        $eic = $this->userWithRole('editor_in_chief', ['name' => 'Главен уредник']);
        $editorOne = $this->userWithRole('editor', ['name' => 'Елена Уредник']);
        $editorTwo = $this->userWithRole('editor', ['name' => 'Втори Уредник']);
        $activeAccount = $this->userWithRole('author', ['status' => 'active']);
        $disabledAccount = $this->userWithRole('author', ['status' => 'disabled']);
        $culture = TeamDepartment::factory()->create(['name' => 'Култура и настани', 'sort_order' => 10]);
        $news = TeamDepartment::factory()->create(['name' => 'Вести', 'sort_order' => 20]);
        $activeAuthor = Author::factory()->create(['name' => 'Активна Авторка', 'slug' => 'aktivna-avtorka', 'is_active' => true]);
        $inactiveAuthor = Author::factory()->create(['name' => 'Неактивна Авторка', 'slug' => 'neaktivna-avtorka', 'is_active' => false]);

        TeamMember::factory()->create([
            'author_id' => $activeAuthor->id,
            'user_id' => $activeAccount->id,
            'primary_department_id' => $culture->id,
        ]);
        TeamMember::factory()->create([
            'author_id' => $inactiveAuthor->id,
            'user_id' => $disabledAccount->id,
            'primary_department_id' => $news->id,
        ]);
        EditorialSupervision::factory()->create(['editor_id' => $editorOne->id, 'author_id' => $activeAuthor->id]);
        EditorialSupervision::factory()->create(['editor_id' => $editorTwo->id, 'author_id' => $inactiveAuthor->id]);

        $this->actingAs($eic);

        Livewire::test(NewsroomManagedAuthors::class)
            ->assertSee('Активна Авторка')
            ->assertSee('Неактивна Авторка')
            ->assertSee('Погледни објави')
            ->assertSee('Јавен профил')
            ->assertSee('Нова објава')
            ->set('supervisingEditor', (string) $editorOne->id)
            ->assertSee('Активна Авторка')
            ->assertDontSee('Неактивна Авторка')
            ->set('supervisingEditor', 'all')
            ->set('accountStatus', 'disabled')
            ->assertDontSee('Активна Авторка')
            ->assertSee('Неактивна Авторка')
            ->set('accountStatus', 'all')
            ->set('department', (string) $culture->id)
            ->assertSee('Активна Авторка')
            ->assertDontSee('Неактивна Авторка')
            ->set('department', 'all')
            ->set('status', 'inactive')
            ->assertDontSee('Активна Авторка')
            ->assertSee('Неактивна Авторка');
    }

    public function test_editor_author_directory_remains_scoped_to_managed_authors(): void
    {
        $editor = $this->userWithRole('editor');
        $managed = Author::factory()->create(['name' => 'Авторка во опсег']);
        $unrelated = Author::factory()->create(['name' => 'Неповрзана Авторка']);
        EditorialSupervision::factory()->create(['editor_id' => $editor->id, 'author_id' => $managed->id]);

        $this->actingAs($editor);

        Livewire::test(NewsroomManagedAuthors::class)
            ->assertSee('Авторка во опсег')
            ->assertDontSee('Неповрзана Авторка')
            ->assertDontSee('Корисничка сметка')
            ->assertDontSee('Одговорен уредник');

        $this->assertNotNull($unrelated->id);
    }

    public function test_author_workflow_counters_link_to_the_filtered_post_drilldown(): void
    {
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create(['name' => 'Филтрирана Авторка']);
        EditorialSupervision::factory()->create(['editor_id' => $editor->id, 'author_id' => $author->id]);
        Article::factory()->create(['author_id' => $author->id, 'status' => 'draft']);

        $this->actingAs($editor);

        Livewire::test(NewsroomManagedAuthors::class)
            ->assertSee('Работни верзии')
            ->assertSeeHtml('author='.$author->id.'&amp;status=draft');

        Livewire::withQueryParams(['author' => $author->id, 'status' => 'draft'])
            ->test(NewsroomManagedPosts::class)
            ->assertSet('author', (string) $author->id)
            ->assertSet('status', 'draft')
            ->assertSee('Филтрирани објави')
            ->assertSee('Работната верзија сè уште не е испратена на преглед.');
    }

    public function test_calendar_renders_editorial_context_and_supports_authorized_schedule_and_publish_actions(): void
    {
        $eic = $this->userWithRole('editor_in_chief', ['name' => 'Главен уредник']);
        $editor = $this->userWithRole('editor', ['name' => 'Одговорен уредник']);
        $article = Article::factory()->create([
            'title' => 'Планирана културна објава',
            'status' => 'approved',
            'approved_at' => now()->subHour(),
            'approved_by' => $eic->id,
            'assigned_editor_id' => $editor->id,
        ]);

        $this->actingAs($eic);

        $calendar = Livewire::withQueryParams(['view' => 'today', 'date' => '2026-07-21'])
            ->test(NewsroomCalendar::class)
            ->assertSee('Планирана културна објава')
            ->assertSee($article->author->name)
            ->assertSee($article->category->name)
            ->assertSee('Одговорен уредник')
            ->assertSee('Нема термин');

        $calendar->callAction(
            TestAction::make('reschedule')->arguments(['article' => $article->id]),
            ['scheduled_at' => '2026-07-22 14:30:00'],
        )->assertHasNoErrors();

        $scheduled = $article->refresh();
        $this->assertSame('scheduled', $scheduled->status);
        $this->assertNotNull($scheduled->scheduled_at);

        Livewire::withQueryParams(['view' => 'week', 'date' => '2026-07-22'])
            ->test(NewsroomCalendar::class)
            ->callAction(TestAction::make('publishNow')->arguments(['article' => $article->id]));

        $this->assertSame('published', $article->refresh()->status);
        $this->assertNotNull($article->published_at);
        $this->assertNull($article->scheduled_at);
    }

    public function test_editor_in_chief_can_assign_an_active_editor_from_the_calendar_with_audit_and_revision(): void
    {
        $eic = $this->userWithRole('editor_in_chief');
        $editor = $this->userWithRole('editor', ['name' => 'Нов одговорен уредник']);
        $article = Article::factory()->create([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $eic->id,
        ]);

        $this->actingAs($eic);

        Livewire::withQueryParams(['view' => 'today', 'date' => '2026-07-21'])
            ->test(NewsroomCalendar::class)
            ->callAction(
                TestAction::make('assignEditor')->arguments(['article' => $article->id]),
                ['assigned_editor_id' => $editor->id],
            )
            ->assertHasNoErrors();

        $this->assertSame($editor->id, $article->refresh()->assigned_editor_id);
        $this->assertDatabaseHas(AdminAuditLog::class, [
            'auditable_type' => Article::class,
            'auditable_id' => $article->id,
            'action' => 'article.editor_assigned',
        ]);
        $this->assertDatabaseHas(ArticleRevision::class, [
            'article_id' => $article->id,
            'reason' => 'article.editor_assigned',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
