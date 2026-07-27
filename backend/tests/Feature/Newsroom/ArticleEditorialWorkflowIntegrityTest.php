<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Pages\NewsroomApprovalQueue;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Articles\ArticleEditorialWorkflow;
use App\Services\Articles\ArticlePublicationReadiness;
use App\Services\Articles\ScheduledArticlePublisher;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use App\Services\Newsroom\NewsroomWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleEditorialWorkflowIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_transitions_reject_published_and_archived_articles_server_side(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $workflow = app(ArticleEditorialWorkflow::class);

        foreach (['published', 'archived'] as $status) {
            foreach (['submit', 'startReview', 'requestChanges', 'approve'] as $transition) {
                $article = Article::factory()->create([
                    'status' => $status,
                    'published_at' => $status === 'published' ? now()->subMinute() : null,
                ]);

                try {
                    $workflow->{$transition}($article, $admin);
                    $this->fail("The {$transition} transition accepted an article in {$status} status.");
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('status', $exception->errors());
                }

                $this->assertSame($status, $article->refresh()->status);
                $this->assertDatabaseMissing('admin_audit_logs', [
                    'auditable_type' => Article::class,
                    'auditable_id' => $article->id,
                ]);
            }
        }
    }

    public function test_transition_capability_matches_each_valid_editorial_source_state(): void
    {
        $workflow = app(ArticleEditorialWorkflow::class);

        $this->assertTrue($workflow->canTransition(new Article(['status' => 'draft']), 'submit'));
        $this->assertTrue($workflow->canTransition(new Article(['status' => 'changes_requested']), 'submit'));
        $this->assertTrue($workflow->canTransition(new Article(['status' => 'submitted']), 'startReview'));
        $this->assertTrue($workflow->canTransition(new Article(['status' => 'in_review']), 'requestChanges'));
        $this->assertTrue($workflow->canTransition(new Article(['status' => 'in_review']), 'approve'));
        $this->assertTrue($workflow->canTransition(new Article(['status' => 'approved']), 'schedule'));
        $this->assertTrue($workflow->canTransition(new Article(['status' => 'scheduled']), 'publish'));
        $this->assertTrue($workflow->canTransition(new Article(['status' => 'published']), 'archive'));

        $this->assertFalse($workflow->canTransition(new Article(['status' => 'in_review']), 'startReview'));
        $this->assertFalse($workflow->canTransition(new Article(['status' => 'approved']), 'approve'));
        $this->assertFalse($workflow->canTransition(new Article(['status' => 'published']), 'requestChanges'));
        $this->assertFalse($workflow->canTransition(new Article(['status' => 'archived']), 'submit'));
        $this->assertFalse($workflow->canTransition(new Article(['status' => 'draft']), 'unknownTransition'));
    }

    public function test_table_and_edit_page_offer_direct_publish_and_schedule_only_for_nonpublic_articles(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $draft = Article::factory()->create(['status' => 'draft']);
        $published = Article::factory()->create(['status' => 'published', 'published_at' => now()]);
        $this->actingAs($admin);

        Livewire::test(EditArticle::class, ['record' => $draft->getRouteKey()])
            ->assertActionVisible('publish')
            ->assertActionVisible('schedule')
            ->assertActionHidden('archive');
        Livewire::test(EditArticle::class, ['record' => $published->getRouteKey()])
            ->assertActionHidden('publish')
            ->assertActionHidden('schedule')
            ->assertActionVisible('archive');

        Livewire::test(ListArticles::class)
            ->assertTableActionVisible('publish', $draft)
            ->assertTableActionVisible('schedule', $draft)
            ->assertTableActionHidden('archive', $draft)
            ->assertTableActionHidden('publish', $published)
            ->assertTableActionHidden('schedule', $published)
            ->assertTableActionVisible('archive', $published);
    }

    public function test_schedule_publish_and_archive_have_server_side_source_state_guards(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $workflow = app(ArticleEditorialWorkflow::class);

        $invalidTransitions = [
            'schedule' => Article::factory()->create(['status' => 'published', 'published_at' => now()]),
            'publish' => Article::factory()->create(['status' => 'published', 'published_at' => now()]),
            'archive' => Article::factory()->create(['status' => 'draft']),
        ];

        foreach ($invalidTransitions as $transition => $article) {
            try {
                $transition === 'schedule'
                    ? $workflow->schedule($article, now()->addHour(), $admin)
                    : $workflow->{$transition}($article, $admin);
                $this->fail("The {$transition} transition accepted status {$article->status}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }

            $this->assertSame($article->status, $article->refresh()->status);
        }
    }

    public function test_workspace_and_workflow_share_required_source_and_warning_severity_contract(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $article = Article::factory()->create([
            'status' => 'in_review',
            'type' => 'guide',
            'presentation_type' => 'event_guide',
            'guide_metadata' => ['events' => []],
            'featured_image_id' => null,
        ]);

        $readiness = app(ArticlePublicationReadiness::class)->checklist($article);
        $workspace = app(NewsroomWorkspaceService::class)->checklist($article);

        $this->assertSame($readiness, $workspace);
        $this->assertSame('blocking', $readiness['sources']['state']);
        $this->assertSame('warning', $readiness['featured_image']['state']);
        $this->assertSame('warning', $readiness['seo_title']['state']);
        $this->assertSame('warning', $readiness['canonical']['state']);

        try {
            app(ArticleEditorialWorkflow::class)->approve($article, $admin);
            $this->fail('Approval accepted a guide without a required source.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sources', $exception->errors());
        }

        $this->assertSame('in_review', $article->refresh()->status);
    }

    public function test_public_approval_queue_method_cannot_bypass_publication_readiness(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor_in_chief');
        $article = Article::factory()->create([
            'status' => 'submitted',
            'submitted_at' => now(),
            'type' => 'guide',
            'presentation_type' => 'event_guide',
            'guide_metadata' => ['events' => []],
        ]);
        $this->actingAs($editor);

        Livewire::test(NewsroomApprovalQueue::class)
            ->call('approve', $article->id)
            ->assertOk();

        $this->assertSame('submitted', $article->refresh()->status);
        $this->assertNull($article->approved_at);
        $this->assertDatabaseMissing('admin_audit_logs', [
            'auditable_type' => Article::class,
            'auditable_id' => $article->id,
            'action' => 'article.approved',
        ]);
    }

    public function test_invalid_review_actions_are_hidden_even_for_an_admin(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $article = Article::factory()->create([
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        $this->actingAs($admin);

        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertActionHidden('send_to_review')
            ->assertActionHidden('start_review')
            ->assertActionHidden('request_changes')
            ->assertActionHidden('approve');
    }

    public function test_livewire_form_save_cannot_bypass_guarded_publication_actions(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $article = Article::factory()->create([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $admin->id,
            'published_at' => null,
        ]);
        $this->actingAs($admin);

        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->fillForm(['status' => 'published'])
            ->call('save')
            ->assertHasNoFormErrors();

        $article->refresh();
        $this->assertSame('approved', $article->status);
        $this->assertNull($article->published_at);
        $this->assertDatabaseMissing('admin_audit_logs', [
            'auditable_type' => Article::class,
            'auditable_id' => $article->id,
            'action' => 'article.published',
        ]);
    }

    public function test_requesting_changes_and_resubmitting_clear_stale_approval_metadata(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $article = Article::factory()->create([
            'status' => 'in_review',
            'approved_at' => now()->subHour(),
            'approved_by' => $admin->id,
        ]);
        $workflow = app(ArticleEditorialWorkflow::class);

        $changesRequested = $workflow->requestChanges($article, $admin);
        $this->assertSame('changes_requested', $changesRequested->status);
        $this->assertNull($changesRequested->approved_at);
        $this->assertNull($changesRequested->approved_by);

        $submitted = $workflow->submit($changesRequested, $admin);
        $this->assertSame('submitted', $submitted->status);
        $this->assertNull($submitted->approved_at);
        $this->assertNull($submitted->approved_by);
    }

    public function test_admin_emergency_publish_from_draft_remains_available_and_clears_old_failure(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $article = Article::factory()->create([
            'status' => 'draft',
            'publication_failed_at' => now()->subHour(),
            'publication_failure_reason' => 'Previous publication attempt failed.',
        ]);

        $published = app(ArticleEditorialWorkflow::class)->publish($article, $admin);

        $this->assertSame('published', $published->status);
        $this->assertSame($admin->id, $published->published_by);
        $this->assertNull($published->publication_failed_at);
        $this->assertNull($published->publication_failure_reason);
    }

    public function test_rescheduling_and_scheduled_publication_clear_old_failure_state(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $article = Article::factory()->create([
            'status' => 'failed',
            'approved_at' => now()->subHour(),
            'approved_by' => $admin->id,
            'publication_failed_at' => now()->subMinutes(30),
            'publication_failure_reason' => 'Transient worker error.',
        ]);

        $scheduled = app(ArticleEditorialWorkflow::class)->schedule($article, now()->subMinute(), $admin);

        $this->assertSame('scheduled', $scheduled->status);
        $this->assertNull($scheduled->publication_failed_at);
        $this->assertNull($scheduled->publication_failure_reason);

        // Defend the worker independently in case legacy data reaches it with stale markers.
        $scheduled->forceFill([
            'publication_failed_at' => now()->subMinutes(10),
            'publication_failure_reason' => 'Stale legacy marker.',
        ])->save();

        $this->assertSame(1, app(ScheduledArticlePublisher::class)->publishDue());

        $published = $article->refresh();
        $this->assertSame('published', $published->status);
        $this->assertNull($published->publication_failed_at);
        $this->assertNull($published->publication_failure_reason);
        $this->assertDatabaseHas('admin_audit_logs', [
            'auditable_type' => Article::class,
            'auditable_id' => $article->id,
            'action' => 'article.published',
        ]);
        $this->assertSame(
            'scheduled_article_publisher',
            AdminAuditLog::query()
                ->where('auditable_type', Article::class)
                ->where('auditable_id', $article->id)
                ->where('action', 'article.published')
                ->firstOrFail()
                ->metadata['surface'],
        );
    }

    public function test_scheduled_publisher_revalidates_blocking_requirements_immediately_before_publish(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $article = Article::factory()->create([
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'approved_at' => now()->subHour(),
            'approved_by' => $admin->id,
        ]);
        ArticleEditorialComment::factory()->create([
            'article_id' => $article->id,
            'author_id' => $admin->id,
            'is_blocking' => true,
            'is_resolved' => false,
        ]);

        $this->assertSame(0, app(ScheduledArticlePublisher::class)->publishDue());

        $failed = $article->refresh();
        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->publication_failed_at);
        $this->assertStringContainsString('блокирачки коментари', $failed->publication_failure_reason);
        $this->assertDatabaseHas('admin_audit_logs', [
            'auditable_type' => Article::class,
            'auditable_id' => $article->id,
            'action' => 'article.publication_failed',
        ]);
    }

    public function test_publication_readiness_does_not_trust_a_stale_preloaded_blocker_count(): void
    {
        $this->seedRoles();
        $admin = $this->userWithRole('super_admin');
        $article = Article::factory()->create([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ]);
        $staleArticle = Article::query()
            ->withCount('unresolvedBlockingEditorialComments')
            ->findOrFail($article->id);
        $this->assertSame(0, (int) $staleArticle->unresolved_blocking_editorial_comments_count);

        ArticleEditorialComment::factory()->create([
            'article_id' => $article->id,
            'author_id' => $admin->id,
            'is_blocking' => true,
            'is_resolved' => false,
        ]);

        try {
            app(ArticlePublicationReadiness::class)->assertReady($staleArticle);
            $this->fail('Publication readiness trusted a stale preloaded blocker count.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('blocking_comments', $exception->errors());
        }
    }

    public function test_author_review_sidebar_does_not_count_internal_editorial_comments(): void
    {
        $this->seedRoles();
        $authorUser = $this->userWithRole('author');
        $editor = $this->userWithRole('editor');
        $article = Article::factory()->create(['status' => 'draft']);
        TeamMember::factory()->create([
            'user_id' => $authorUser->id,
            'author_id' => $article->author_id,
        ]);
        ArticleEditorialComment::factory()->create([
            'article_id' => $article->id,
            'author_id' => $editor->id,
            'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
            'is_resolved' => false,
        ]);
        ArticleEditorialComment::factory()->create([
            'article_id' => $article->id,
            'author_id' => $editor->id,
            'visibility' => ArticleEditorialComment::VISIBILITY_INTERNAL,
            'is_resolved' => false,
        ]);
        $this->actingAs($authorUser);

        $table = Livewire::test(ListArticles::class)->assertOk();
        $tableArticle = $table->instance()
            ->getTableRecords()
            ->firstWhere('id', $article->id);

        $this->assertNotNull($tableArticle);
        $this->assertSame(1, (int) $tableArticle->unresolved_comments_count);

        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertOk()
            ->assertSeeHtml('data-newsroom-unresolved-comments="1"');
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
