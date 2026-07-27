<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\NewsroomApprovalQueue;
use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\Author;
use App\Models\Category;
use App\Models\EditorialSupervision;
use App\Models\MediaAsset;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use App\Services\Newsroom\NewsroomWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class NewsroomWorkspaceServiceTest extends TestCase
{
    use RefreshDatabase;

    private NewsroomWorkspaceService $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-21 08:00:00', 'UTC'));
        app(RazbudiseRolePermissionMatrix::class)->sync();
        $this->workspace = app(NewsroomWorkspaceService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_role_resolves_newsroom_personas_in_precedence_order(): void
    {
        $admin = $this->userWithRole('admin');
        $eic = $this->userWithRole('editor_in_chief');
        $editor = $this->userWithRole('editor');
        $author = $this->userWithRole('author');
        $staff = User::factory()->create(['status' => 'active']);

        $this->assertSame('administrator', $this->workspace->role($admin));
        $this->assertSame('editor_in_chief', $this->workspace->role($eic));
        $this->assertSame('editor', $this->workspace->role($editor));
        $this->assertSame('author', $this->workspace->role($author));
        $this->assertSame('staff', $this->workspace->role($staff));
    }

    public function test_elena_sees_all_romina_posts_but_not_unrelated_editorial_work(): void
    {
        $elena = $this->userWithRole('editor', ['name' => 'Elena Naumovski']);
        $unrelatedEditor = $this->userWithRole('editor');
        $rominaUser = $this->userWithRole('author', ['name' => 'Romina Markovska']);
        $romina = Author::factory()->create(['name' => 'Romina Markovska', 'slug' => 'romina-markovska']);
        $other = Author::factory()->create();

        TeamMember::factory()->create(['user_id' => $rominaUser->id, 'author_id' => $romina->id]);
        EditorialSupervision::factory()->create([
            'editor_id' => $elena->id,
            'author_id' => $romina->id,
            'is_primary' => true,
            'ends_at' => null,
        ]);

        $rominaPosts = Article::factory()->count(3)->create(['author_id' => $romina->id]);
        $unrelatedPost = Article::factory()->create(['author_id' => $other->id]);

        $this->assertEqualsCanonicalizing(
            $rominaPosts->modelKeys(),
            $this->workspace->managedPosts($elena)->pluck('id')->all(),
        );
        $this->assertSame([], $this->workspace->managedPosts($unrelatedEditor)->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            $rominaPosts->modelKeys(),
            $this->workspace->managedPosts($rominaUser)->pluck('id')->all(),
        );
        $this->assertNotContains($unrelatedPost->id, $this->workspace->managedPosts($elena)->pluck('id')->all());
        $this->assertSame([$romina->id], $this->workspace->managedAuthors($elena)->modelKeys());
    }

    public function test_metrics_are_scoped_and_use_sk_opje_day_boundaries(): void
    {
        $elena = $this->userWithRole('editor');
        $author = Author::factory()->create();
        $otherAuthor = Author::factory()->create();
        EditorialSupervision::factory()->create(['editor_id' => $elena->id, 'author_id' => $author->id]);

        Article::factory()->create(['author_id' => $author->id, 'status' => 'submitted', 'submitted_at' => now()]);
        Article::factory()->create(['author_id' => $author->id, 'status' => 'changes_requested']);
        Article::factory()->create(['author_id' => $author->id, 'status' => 'in_review']);
        Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'scheduled',
            'scheduled_at' => Carbon::parse('2026-07-21 18:00', 'Europe/Skopje')->utc(),
        ]);
        Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-21 00:30', 'Europe/Skopje')->utc(),
        ]);
        Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'failed',
            'publication_failed_at' => now(),
        ]);
        Article::factory()->create(['author_id' => $otherAuthor->id, 'status' => 'submitted', 'submitted_at' => now()]);

        $metrics = $this->workspace->metrics($elena);

        $this->assertSame(2, $metrics['needs_approval']['count']);
        $this->assertSame(1, $metrics['changes_requested']['count']);
        $this->assertSame(1, $metrics['in_review']['count']);
        $this->assertSame(1, $metrics['scheduled_today']['count']);
        $this->assertSame(1, $metrics['published_today']['count']);
        $this->assertSame(1, $metrics['failed_publications']['count']);
    }

    public function test_editor_dashboard_metrics_only_include_assigned_or_managed_work(): void
    {
        $editor = $this->userWithRole('editor');
        $managedAuthor = Author::factory()->create();
        $assignedAuthor = Author::factory()->create();
        $unrelatedAuthor = Author::factory()->create();
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $managedAuthor->id,
        ]);

        Article::factory()->create([
            'author_id' => $managedAuthor->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        Article::factory()->create([
            'author_id' => $assignedAuthor->id,
            'assigned_editor_id' => $editor->id,
            'status' => 'in_review',
        ]);
        Article::factory()->create([
            'author_id' => $assignedAuthor->id,
            'supervising_editor_id' => $editor->id,
            'status' => 'changes_requested',
        ]);
        Article::factory()->create([
            'author_id' => $assignedAuthor->id,
            'assigned_editor_id' => $editor->id,
            'status' => 'scheduled',
            'scheduled_at' => Carbon::parse('2026-07-21 14:00', 'Europe/Skopje')->utc(),
        ]);
        Article::factory()->create([
            'author_id' => $assignedAuthor->id,
            'assigned_editor_id' => $editor->id,
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-21 12:00', 'Europe/Skopje')->utc(),
        ]);
        Article::factory()->create([
            'author_id' => $assignedAuthor->id,
            'assigned_editor_id' => $editor->id,
            'status' => 'failed',
            'publication_failed_at' => now(),
        ]);
        Article::factory()->create([
            'author_id' => $assignedAuthor->id,
            'assigned_editor_id' => $editor->id,
            'status' => 'failed',
            'publication_failed_at' => null,
        ]);

        // Editing an unrelated post once must not turn it into managed workload.
        Article::factory()->create([
            'author_id' => $unrelatedAuthor->id,
            'updated_by' => $editor->id,
            'status' => 'scheduled',
            'scheduled_at' => Carbon::parse('2026-07-21 16:00', 'Europe/Skopje')->utc(),
        ]);

        $metrics = $this->workspace->metrics($editor);

        $this->assertSame(2, $metrics['needs_approval']['count']);
        $this->assertSame(1, $metrics['changes_requested']['count']);
        $this->assertSame(1, $metrics['in_review']['count']);
        $this->assertSame(1, $metrics['scheduled_today']['count']);
        $this->assertSame(1, $metrics['published_today']['count']);
        $this->assertSame(2, $metrics['failed_publications']['count']);
    }

    public function test_author_and_contributor_dashboard_metrics_are_personal_workflow_totals(): void
    {
        foreach (['author', 'contributor'] as $roleName) {
            $user = $this->userWithRole($roleName);
            $author = Author::factory()->create();
            TeamMember::factory()->create([
                'user_id' => $user->id,
                'author_id' => $author->id,
            ]);

            foreach (['draft', 'submitted', 'changes_requested', 'approved', 'scheduled', 'published'] as $status) {
                Article::factory()->create([
                    'author_id' => $author->id,
                    'status' => $status,
                ]);
            }

            Article::factory()->create(['author_id' => $author->id, 'status' => 'draft']);
            Article::factory()->create(['author_id' => Author::factory()->create()->id, 'status' => 'published']);

            $metrics = $this->workspace->metrics($user);

            $this->assertSame(
                ['drafts', 'submitted', 'changes_requested', 'approved', 'scheduled', 'published'],
                array_keys($metrics),
            );
            $this->assertSame(2, $metrics['drafts']['count']);
            $this->assertSame(1, $metrics['submitted']['count']);
            $this->assertSame(1, $metrics['changes_requested']['count']);
            $this->assertSame(1, $metrics['approved']['count']);
            $this->assertSame(1, $metrics['scheduled']['count']);
            $this->assertSame(1, $metrics['published']['count']);
            $this->assertNull($metrics['published']['delta']);
        }
    }

    public function test_author_dashboard_queue_ignores_editor_only_seo_checks(): void
    {
        $user = $this->userWithRole('author');
        $author = Author::factory()->create();
        TeamMember::factory()->create([
            'user_id' => $user->id,
            'author_id' => $author->id,
        ]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'submitted',
            'submitted_at' => now(),
            'approved_at' => null,
        ]);

        $this->actingAs($user);

        $item = $this->workspace->approvalQueue($user)->firstWhere(
            fn (array $item): bool => $item['article']->is($article),
        );

        $this->assertNotNull($item);
        $this->assertSame(['complete' => 0, 'total' => 0, 'percent' => 100], $item['seo']);
    }

    public function test_editor_in_chief_and_administrator_dashboard_metrics_are_global(): void
    {
        Article::factory()->create([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        Article::factory()->create([
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-21 10:00', 'Europe/Skopje')->utc(),
        ]);

        foreach (['editor_in_chief', 'admin'] as $roleName) {
            $metrics = $this->workspace->metrics($this->userWithRole($roleName));

            $this->assertSame(1, $metrics['needs_approval']['count']);
            $this->assertSame(1, $metrics['published_today']['count']);
            $this->assertSame(
                ['needs_approval', 'changes_requested', 'in_review', 'scheduled_today', 'published_today', 'failed_publications'],
                array_keys($metrics),
            );
        }
    }

    public function test_approval_queue_marks_only_items_older_than_the_twenty_four_hour_sla_as_overdue(): void
    {
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create();
        EditorialSupervision::factory()->create(['editor_id' => $editor->id, 'author_id' => $author->id]);

        $overdue = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'submitted',
            'submitted_at' => now()->subHours(25),
        ]);
        $withinSla = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'submitted',
            'submitted_at' => now()->subHours(23),
        ]);

        $queue = $this->workspace->approvalQueue($editor)->keyBy(fn (array $item): int => $item['article']->id);

        $this->assertTrue($queue[$overdue->id]['overdue']);
        $this->assertFalse($queue[$withinSla->id]['overdue']);
        $this->assertGreaterThan($queue[$withinSla->id]['waiting_minutes'], $queue[$overdue->id]['waiting_minutes']);

        $urgent = $this->workspace->urgentItems($editor);
        $this->assertTrue($urgent->contains(fn (array $item): bool => $item['type'] === 'overdue_approval' && $item['article']->is($overdue)));
        $this->assertFalse($urgent->contains(fn (array $item): bool => $item['type'] === 'overdue_approval' && $item['article']->is($withinSla)));
    }

    public function test_approval_queue_paginates_the_full_scoped_workload_in_stable_sla_order(): void
    {
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create();
        $unrelatedAuthor = Author::factory()->create();
        $category = Category::factory()->create();
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $author->id,
        ]);

        $expectedIds = collect();

        foreach (range(1, 45) as $position) {
            $submittedAt = $position <= 2
                ? now()->subMinutes(120)
                : now()->subMinutes(120 - $position);
            $article = Article::factory()->create([
                'title' => sprintf('Ред за одобрување %02d', $position),
                'author_id' => $author->id,
                'category_id' => $category->id,
                'status' => 'submitted',
                'submitted_at' => $submittedAt,
                'approved_at' => null,
            ]);
            $expectedIds->push($article->id);
        }

        Article::factory()->count(5)->create([
            'author_id' => $unrelatedAuthor->id,
            'category_id' => $category->id,
            'status' => 'submitted',
            'submitted_at' => now()->subDays(10),
            'approved_at' => null,
        ]);
        Article::factory()->create([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'status' => 'submitted',
            'submitted_at' => now()->subDays(20),
            'approved_at' => now()->subDay(),
        ]);

        $firstPage = $this->workspace->paginatedApprovalQueue($editor, 20, 'queue', 1);
        $secondPage = $this->workspace->paginatedApprovalQueue($editor, 20, 'queue', 2);
        $thirdPage = $this->workspace->paginatedApprovalQueue($editor, 20, 'queue', 3);

        $this->assertSame(45, $firstPage->total());
        $this->assertSame(3, $firstPage->lastPage());
        $this->assertCount(20, $firstPage->items());
        $this->assertCount(20, $secondPage->items());
        $this->assertCount(5, $thirdPage->items());
        $this->assertSame(
            $expectedIds->take(20)->all(),
            collect($firstPage->items())->pluck('article.id')->all(),
        );
        $this->assertSame(
            $expectedIds->slice(20, 20)->values()->all(),
            collect($secondPage->items())->pluck('article.id')->all(),
        );
        $this->assertSame(
            $expectedIds->slice(40)->values()->all(),
            collect($thirdPage->items())->pluck('article.id')->all(),
        );

        $this->actingAs($editor);

        Livewire::test(NewsroomApprovalQueue::class)
            ->assertSee('45 објави чекаат преглед')
            ->assertSee('Прикажани 1 до 20 од 45.')
            ->assertSee('Ред за одобрување 01')
            ->assertDontSee('Ред за одобрување 21')
            ->call('setPage', 3)
            ->assertSee('Прикажани 41 до 45 од 45.')
            ->assertSee('Ред за одобрување 45')
            ->assertDontSee('Ред за одобрување 40');
    }

    public function test_author_managed_posts_and_urgent_items_do_not_expose_internal_comments(): void
    {
        $authorUser = $this->userWithRole('author');
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create();
        TeamMember::factory()->create([
            'user_id' => $authorUser->id,
            'author_id' => $author->id,
        ]);
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $author->id,
        ]);

        $internalOnly = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'draft',
        ]);
        $authorVisible = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'draft',
        ]);

        ArticleEditorialComment::factory()->create([
            'article_id' => $internalOnly->id,
            'author_id' => $editor->id,
            'visibility' => ArticleEditorialComment::VISIBILITY_INTERNAL,
            'is_blocking' => true,
            'is_resolved' => false,
        ]);
        ArticleEditorialComment::factory()->create([
            'article_id' => $authorVisible->id,
            'author_id' => $editor->id,
            'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
            'is_blocking' => true,
            'is_resolved' => false,
        ]);

        $authorPosts = $this->workspace->managedPosts($authorUser)->get()->keyBy('id');
        $authorUrgent = $this->workspace->urgentItems($authorUser);

        $this->assertSame(0, (int) $authorPosts[$internalOnly->id]->unresolved_editorial_comments_count);
        $this->assertSame(0, (int) $authorPosts[$internalOnly->id]->unresolved_blocking_editorial_comments_count);
        $this->assertSame(1, (int) $authorPosts[$authorVisible->id]->unresolved_editorial_comments_count);
        $this->assertSame(1, (int) $authorPosts[$authorVisible->id]->unresolved_blocking_editorial_comments_count);
        $this->assertFalse($authorUrgent->contains(fn (array $item): bool => $item['article']?->is($internalOnly)));
        $this->assertTrue($authorUrgent->contains(
            fn (array $item): bool => $item['type'] === 'blocking_comments' && $item['article']?->is($authorVisible),
        ));

        $this->actingAs($authorUser);
        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertDontSee($internalOnly->title)
            ->assertSee($authorVisible->title);

        $editorPosts = $this->workspace->managedPosts($editor)->get()->keyBy('id');
        $editorUrgent = $this->workspace->urgentItems($editor);

        $this->assertSame(1, (int) $editorPosts[$internalOnly->id]->unresolved_editorial_comments_count);
        $this->assertSame(1, (int) $editorPosts[$internalOnly->id]->unresolved_blocking_editorial_comments_count);
        $this->assertTrue($editorUrgent->contains(
            fn (array $item): bool => $item['type'] === 'blocking_comments' && $item['article']?->is($internalOnly),
        ));
    }

    public function test_managed_author_workload_counts_are_correct(): void
    {
        $elena = $this->userWithRole('editor');
        $romina = Author::factory()->create(['name' => 'Romina Markovska']);
        EditorialSupervision::factory()->create(['editor_id' => $elena->id, 'author_id' => $romina->id]);

        Article::factory()->count(2)->create(['author_id' => $romina->id, 'status' => 'draft']);
        Article::factory()->create(['author_id' => $romina->id, 'status' => 'submitted']);
        Article::factory()->create(['author_id' => $romina->id, 'status' => 'changes_requested']);
        Article::factory()->create(['author_id' => $romina->id, 'status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
        Article::factory()->published()->create(['author_id' => $romina->id]);
        Article::query()->where('author_id', $romina->id)->firstOrFail()->update(['last_editorial_activity_at' => now()]);

        $workload = $this->workspace->managedAuthors($elena)->sole();

        $this->assertSame(2, $workload->drafts_count);
        $this->assertSame(1, $workload->pending_approval_count);
        $this->assertSame(1, $workload->changes_requested_count);
        $this->assertSame(1, $workload->scheduled_count);
        $this->assertSame(1, $workload->published_this_month_count);
        $this->assertInstanceOf(Carbon::class, $workload->last_activity_at);
        $this->assertTrue($workload->relationLoaded('teamMembers'));
    }

    public function test_published_this_month_drilldown_is_scoped_to_author_and_month(): void
    {
        $editor = $this->userWithRole('editor');
        $managed = Author::factory()->create();
        $other = Author::factory()->create();
        EditorialSupervision::factory()->create(['editor_id' => $editor->id, 'author_id' => $managed->id]);

        $atMonthStart = Article::factory()->published()->create([
            'author_id' => $managed->id,
            'published_at' => Carbon::parse('2026-07-01 00:00:00', 'Europe/Skopje')->utc(),
        ]);
        $current = Article::factory()->published()->create([
            'author_id' => $managed->id,
            'published_at' => Carbon::parse('2026-07-21 09:59:59', 'Europe/Skopje')->utc(),
        ]);
        Article::factory()->published()->create([
            'author_id' => $managed->id,
            'published_at' => Carbon::parse('2026-06-30 23:59:59', 'Europe/Skopje')->utc(),
        ]);
        Article::factory()->published()->create([
            'author_id' => $managed->id,
            'published_at' => Carbon::parse('2026-08-01 00:00:00', 'Europe/Skopje')->utc(),
        ]);
        Article::factory()->published()->create([
            'author_id' => $managed->id,
            'published_at' => Carbon::parse('2026-07-22 00:00:00', 'Europe/Skopje')->utc(),
        ]);
        Article::factory()->published()->create([
            'author_id' => $other->id,
            'published_at' => now()->subDay(),
        ]);

        $results = $this->workspace
            ->managedPosts($editor, 'published_this_month', $managed->id)
            ->get();

        $this->assertEqualsCanonicalizing([$atMonthStart->id, $current->id], $results->modelKeys());
        $this->assertSame(2, $this->workspace->managedAuthors($editor)->sole()->published_this_month_count);
    }

    public function test_each_author_counter_matches_its_role_scoped_drilldown(): void
    {
        $editor = $this->userWithRole('editor');
        $managed = Author::factory()->create();
        $other = Author::factory()->create();
        EditorialSupervision::factory()->create(['editor_id' => $editor->id, 'author_id' => $managed->id]);

        $articles = collect([
            'draft' => Article::factory()->create(['author_id' => $managed->id, 'status' => 'draft']),
            'submitted' => Article::factory()->create(['author_id' => $managed->id, 'status' => 'submitted']),
            'review' => Article::factory()->create(['author_id' => $managed->id, 'status' => 'review']),
            'in_review' => Article::factory()->create(['author_id' => $managed->id, 'status' => 'in_review']),
            'changes_requested' => Article::factory()->create(['author_id' => $managed->id, 'status' => 'changes_requested']),
            'scheduled' => Article::factory()->create(['author_id' => $managed->id, 'status' => 'scheduled']),
            'published_this_month' => Article::factory()->published()->create([
                'author_id' => $managed->id,
                'published_at' => now()->subMinute(),
            ]),
        ]);
        Article::factory()->create(['author_id' => $other->id, 'status' => 'draft']);

        $expected = [
            'draft' => [$articles['draft']->id],
            'needs_approval' => [
                $articles['submitted']->id,
                $articles['review']->id,
                $articles['in_review']->id,
            ],
            'changes_requested' => [$articles['changes_requested']->id],
            'scheduled' => [$articles['scheduled']->id],
            'published_this_month' => [$articles['published_this_month']->id],
        ];

        foreach ($expected as $status => $ids) {
            $this->assertEqualsCanonicalizing(
                $ids,
                $this->workspace->managedPosts($editor, $status, $managed->id)->pluck('id')->all(),
                "Counter drilldown [{$status}] did not match its author workload.",
            );
        }

        $workload = $this->workspace->managedAuthors($editor)->sole();
        $this->assertSame(1, $workload->drafts_count);
        $this->assertSame(3, $workload->pending_approval_count);
        $this->assertSame(1, $workload->changes_requested_count);
        $this->assertSame(1, $workload->scheduled_count);
        $this->assertSame(1, $workload->published_this_month_count);
    }

    public function test_editor_workload_is_batched_and_scoped_to_the_newsroom_role(): void
    {
        $eic = $this->userWithRole('editor_in_chief');
        $elena = $this->userWithRole('editor', ['name' => 'Elena Naumovski']);
        $otherEditor = $this->userWithRole('editor', ['name' => 'Other Editor']);
        $authorUser = $this->userWithRole('author');
        $romina = Author::factory()->create();
        EditorialSupervision::factory()->create([
            'editor_id' => $elena->id,
            'author_id' => $romina->id,
            'is_primary' => true,
        ]);

        Article::factory()->create([
            'author_id' => $romina->id,
            'status' => 'submitted',
            'submitted_at' => now()->subHours(30),
        ]);
        Article::factory()->create([
            'author_id' => $romina->id,
            'status' => 'in_review',
            'submitted_at' => now()->subHours(4),
        ]);
        Article::factory()->create([
            'author_id' => $romina->id,
            'status' => 'approved',
            'submitted_at' => now()->subHours(8),
            'approved_at' => now()->subHours(4),
            'approved_by' => $elena->id,
        ]);

        $global = $this->workspace->editorWorkload($eic)->keyBy(fn (array $item): int => $item['editor']->id);
        $own = $this->workspace->editorWorkload($elena);

        $this->assertTrue($global->has($elena->id));
        $this->assertTrue($global->has($otherEditor->id));
        $this->assertSame(1, $global[$elena->id]['authors_managed']);
        $this->assertSame(2, $global[$elena->id]['posts_waiting']);
        $this->assertSame(1, $global[$elena->id]['posts_overdue']);
        $this->assertSame(1, $global[$elena->id]['posts_in_review']);
        $this->assertSame(1, $global[$elena->id]['approved_this_week']);
        $this->assertSame(4.0, $global[$elena->id]['avg_review_hours']);
        $this->assertCount(1, $own);
        $this->assertTrue($own->sole()['editor']->is($elena));
        $this->assertTrue($this->workspace->editorWorkload($authorUser)->isEmpty());
    }

    public function test_publication_checklist_reports_complete_warning_and_blocking_states(): void
    {
        $editor = $this->userWithRole('editor');
        $image = MediaAsset::factory()->create(['alt_text' => 'Публиката на настанот']);
        $article = Article::factory()->create([
            'title' => 'Целосно подготвена објава',
            'excerpt' => 'Краток и јасен опис.',
            'body_html' => '<p>Содржина со доволно информации.</p>',
            'author_id' => Author::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'featured_image_id' => $image->id,
            'type' => 'guide',
            'presentation_type' => 'event_guide',
            'guide_metadata' => ['sources' => [['label' => 'Организатор', 'url' => 'https://example.test/event']]],
            'approved_at' => now(),
            'approved_by' => $editor->id,
        ]);
        $article->seoMetadata()->create([
            'seo_title' => 'Целосно подготвена објава',
            'meta_description' => 'Опис за пребарувачите.',
            'canonical_url' => 'https://example.test/celosno-podgotvena-objava',
        ]);

        $complete = $this->workspace->checklist($article->refresh());

        $this->assertSame('complete', $complete['title']['state']);
        $this->assertSame('complete', $complete['sources']['state']);
        $this->assertSame('complete', $complete['alt_text']['state']);
        $this->assertSame('complete', $complete['approval_status']['state']);

        $article->featuredImage->forceFill(['alt_text' => null])->save();
        ArticleEditorialComment::factory()->create([
            'article_id' => $article->id,
            'author_id' => $editor->id,
            'is_blocking' => true,
            'is_resolved' => false,
        ]);

        $incomplete = $this->workspace->checklist($article->refresh());

        $this->assertSame('warning', $incomplete['alt_text']['state']);
        $this->assertSame('blocking', $incomplete['blocking_comments']['state']);
        $this->assertFalse($incomplete['blocking_comments']['complete']);
    }

    public function test_activity_feed_excludes_unrelated_article_events_for_an_editor(): void
    {
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create();
        $otherAuthor = Author::factory()->create();
        EditorialSupervision::factory()->create(['editor_id' => $editor->id, 'author_id' => $author->id]);
        $visible = Article::factory()->create(['author_id' => $author->id]);
        $hidden = Article::factory()->create(['author_id' => $otherAuthor->id]);

        foreach ([$visible, $hidden] as $article) {
            AdminAuditLog::query()->create([
                'actor_id' => $editor->id,
                'auditable_type' => Article::class,
                'auditable_id' => $article->id,
                'action' => 'article.submitted',
            ]);
        }

        $this->assertSame([$visible->id], $this->workspace->activity($editor)->pluck('auditable_id')->all());
    }

    public function test_calendar_keeps_approved_unscheduled_posts_inside_the_requested_window(): void
    {
        $eic = $this->userWithRole('editor_in_chief');
        $today = Article::factory()->create([
            'status' => 'approved',
            'approved_at' => now()->subHour(),
            'approved_by' => $eic->id,
        ]);
        $old = Article::factory()->create([
            'status' => 'approved',
            'approved_at' => now()->subDays(10),
            'approved_by' => $eic->id,
        ]);

        $items = $this->workspace->calendar(
            $eic,
            Carbon::parse('2026-07-21', 'Europe/Skopje'),
            Carbon::parse('2026-07-21', 'Europe/Skopje'),
        );

        $this->assertContains($today->id, $items->modelKeys());
        $this->assertNotContains($old->id, $items->modelKeys());
    }

    /** @param array<string, mixed> $attributes */
    private function userWithRole(string $roleName, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
