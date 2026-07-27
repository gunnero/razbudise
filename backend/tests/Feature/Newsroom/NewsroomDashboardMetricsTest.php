<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\NewsroomApprovalQueue;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Models\Article;
use App\Models\Author;
use App\Models\EditorialSupervision;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class NewsroomDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(RazbudiseRolePermissionMatrix::class)->sync();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_editor_in_chief_and_editor_render_operational_metric_cards(): void
    {
        foreach (['editor_in_chief', 'editor'] as $roleName) {
            $user = $this->userWithRole($roleName);
            $this->actingAs($user);

            $component = Livewire::test(Dashboard::class)
                ->assertSee('Чекаат одобрување')
                ->assertSee('Потребна доработка')
                ->assertSee('Во преглед')
                ->assertSee('Закажани денес')
                ->assertSee('Објавени денес')
                ->assertSee('Неуспешни објавувања')
                ->assertDontSee('Ваши објави што сè уште ги подготвувате');

            $component->assertSeeHtml('data-metric-key="scheduled_today"');
            $this->assertSame('Редакциски преглед', $component->instance()->getTitle());
        }
    }

    public function test_author_and_contributor_render_personal_metric_cards(): void
    {
        foreach (['author', 'contributor'] as $roleName) {
            $user = $this->userWithRole($roleName);
            TeamMember::factory()->create([
                'user_id' => $user->id,
                'author_id' => Author::factory()->create()->id,
            ]);
            $this->actingAs($user);

            $component = Livewire::test(Dashboard::class)
                ->assertSee('Мои објави')
                ->assertSee('Работни верзии')
                ->assertSee('Испратени')
                ->assertSee('Потребна доработка')
                ->assertSee('Одобрени')
                ->assertSee('Закажани')
                ->assertSee('Објавени')
                ->assertSeeHtml('data-metric-key="drafts"')
                ->assertSeeHtml('data-metric-key="published"')
                ->assertDontSee('Неуспешни објавувања');

            $this->assertSame('Мој преглед', $component->instance()->getTitle());
        }
    }

    public function test_operational_metric_cards_target_the_exact_canonical_queues(): void
    {
        $user = $this->userWithRole('editor_in_chief');
        $this->actingAs($user);
        $component = Livewire::test(Dashboard::class);
        $urls = Dashboard::metricUrlsFor($user);

        $this->assertSame(NewsroomApprovalQueue::getUrl(), $urls['needs_approval']);
        $this->assertQueue($urls['changes_requested'], 'changes_requested');
        $this->assertQueue($urls['in_review'], 'in_review');
        $this->assertQueue($urls['scheduled_today'], 'scheduled_today');
        $this->assertQueue($urls['published_today'], 'published_today');
        $this->assertQueue($urls['failed_publications'], 'failed');

        $component
            ->assertSeeHtml('href="'.e($urls['scheduled_today']).'"')
            ->assertSeeHtml('href="'.e($urls['published_today']).'"')
            ->assertSeeHtml('href="'.e($urls['failed_publications']).'"');
    }

    public function test_personal_metric_cards_target_exact_status_filters(): void
    {
        $user = $this->userWithRole('author');
        TeamMember::factory()->create([
            'user_id' => $user->id,
            'author_id' => Author::factory()->create()->id,
        ]);
        $this->actingAs($user);
        $component = Livewire::test(Dashboard::class);
        $urls = Dashboard::metricUrlsFor($user);

        foreach ([
            'drafts' => 'draft',
            'submitted' => 'submitted',
            'changes_requested' => 'changes_requested',
            'approved' => 'approved',
            'scheduled' => 'scheduled',
            'published' => 'published',
        ] as $key => $status) {
            $query = $this->query($urls[$key]);
            $this->assertSame($status, data_get($query, 'filters.status.value'));
            $component->assertSeeHtml('href="'.e($urls[$key]).'"');
        }
    }

    public function test_operational_queue_filters_match_today_and_editor_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 08:00:00', 'UTC'));
        $editor = $this->userWithRole('editor');
        $managedAuthor = Author::factory()->create();
        $unrelatedAuthor = Author::factory()->create();
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $managedAuthor->id,
        ]);
        $this->actingAs($editor);

        $managedScheduled = Article::factory()->create([
            'author_id' => $managedAuthor->id,
            'status' => 'scheduled',
            'scheduled_at' => Carbon::parse('2026-07-21 12:00', 'Europe/Skopje')->utc(),
        ]);
        $unrelatedScheduled = Article::factory()->create([
            'author_id' => $unrelatedAuthor->id,
            'status' => 'scheduled',
            'scheduled_at' => Carbon::parse('2026-07-21 13:00', 'Europe/Skopje')->utc(),
        ]);
        $nextDayScheduled = Article::factory()->create([
            'author_id' => $managedAuthor->id,
            'status' => 'scheduled',
            'scheduled_at' => Carbon::parse('2026-07-22 00:00', 'Europe/Skopje')->utc(),
        ]);
        $managedPublished = Article::factory()->create([
            'author_id' => $managedAuthor->id,
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-21 09:00', 'Europe/Skopje')->utc(),
        ]);
        $unrelatedPublished = Article::factory()->create([
            'author_id' => $unrelatedAuthor->id,
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-21 09:30', 'Europe/Skopje')->utc(),
        ]);
        $managedFailed = Article::factory()->create([
            'author_id' => $managedAuthor->id,
            'status' => 'failed',
            'publication_failed_at' => now(),
        ]);
        $unrelatedFailed = Article::factory()->create([
            'author_id' => $unrelatedAuthor->id,
            'status' => 'failed',
            'publication_failed_at' => now(),
        ]);

        Livewire::test(ListArticles::class)
            ->filterTable('editorial_queue', 'scheduled_today')
            ->assertCanSeeTableRecords([$managedScheduled])
            ->assertCanNotSeeTableRecords([$unrelatedScheduled, $nextDayScheduled]);

        Livewire::test(ListArticles::class)
            ->filterTable('editorial_queue', 'published_today')
            ->assertCanSeeTableRecords([$managedPublished])
            ->assertCanNotSeeTableRecords([$unrelatedPublished]);

        Livewire::test(ListArticles::class)
            ->filterTable('editorial_queue', 'failed')
            ->assertCanSeeTableRecords([$managedFailed])
            ->assertCanNotSeeTableRecords([$unrelatedFailed]);

    }

    private function assertQueue(string $url, string $expected): void
    {
        $this->assertSame(
            $expected,
            data_get($this->query($url), 'filters.editorial_queue.value'),
        );
    }

    /** @return array<string, mixed> */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
