<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\Role;
use App\Models\User;
use App\Services\Articles\ArticleEditorialWorkflow;
use App\Services\Operations\AdminAuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_workflow_transitions_write_admin_audit_logs(): void
    {
        $this->seed();

        $actor = User::factory()->create();
        $actor->roles()->attach(Role::query()->where('name', 'editor_in_chief')->firstOrFail());

        $article = Article::factory()->create([
            'status' => 'draft',
            'published_at' => null,
            'scheduled_at' => null,
        ]);

        $workflow = app(ArticleEditorialWorkflow::class);

        $workflow->submit($article, $actor);
        $workflow->startReview($article->fresh(), $actor);
        $workflow->approve($article->fresh(), $actor);
        $workflow->schedule($article->fresh(), '2026-07-01 10:00:00', $actor);
        $workflow->publish($article->fresh(), $actor);
        $workflow->archive($article->fresh(), $actor);

        $this->assertSame('archived', $article->fresh()->status);
        $this->assertSame([
            'article.submitted',
            'article.review_started',
            'article.approved',
            'article.scheduled',
            'article.published',
            'article.archived',
        ], AdminAuditLog::query()
            ->where('auditable_type', Article::class)
            ->orderBy('id')
            ->pluck('action')
            ->all());

        $publishLog = AdminAuditLog::query()->where('action', 'article.published')->firstOrFail();

        $this->assertSame($actor->id, $publishLog->actor_id);
        $this->assertSame(Article::class, $publishLog->auditable_type);
        $this->assertSame($article->id, $publishLog->auditable_id);
        $this->assertSame('scheduled', $publishLog->before['status']);
        $this->assertSame('published', $publishLog->after['status']);
    }

    public function test_article_workflow_rejects_status_changes_without_publish_permission(): void
    {
        $this->seed();

        $actor = User::factory()->create();
        $actor->roles()->attach(Role::query()->where('name', 'ad_manager')->firstOrFail());

        $article = Article::factory()->create([
            'status' => 'draft',
            'published_at' => null,
            'scheduled_at' => null,
        ]);

        $this->expectException(AuthorizationException::class);

        app(ArticleEditorialWorkflow::class)->publish($article, $actor);
    }

    public function test_audit_logger_can_record_redirect_and_ad_campaign_changes(): void
    {
        $actor = User::factory()->create();
        $article = Article::factory()->create();

        app(AdminAuditLogger::class)->record(
            action: 'redirect.updated',
            auditable: $article,
            actor: $actor,
            before: ['target_url' => '/old'],
            after: ['target_url' => '/new'],
            metadata: ['surface' => 'filament'],
        );

        $log = AdminAuditLog::query()->firstOrFail();

        $this->assertSame('redirect.updated', $log->action);
        $this->assertSame('/old', $log->before['target_url']);
        $this->assertSame('/new', $log->after['target_url']);
        $this->assertSame('filament', $log->metadata['surface']);
    }

    public function test_article_status_changes_are_limited_to_publish_permission(): void
    {
        $this->seed();

        $article = Article::factory()->create();
        $publisher = User::factory()->create();
        $adManager = User::factory()->create();

        $publisher->roles()->attach(Role::query()->where('name', 'editor_in_chief')->firstOrFail());
        $adManager->roles()->attach(Role::query()->where('name', 'ad_manager')->firstOrFail());

        $this->assertTrue($publisher->can('publish', $article));
        $this->assertFalse($adManager->can('publish', $article));
    }
}
