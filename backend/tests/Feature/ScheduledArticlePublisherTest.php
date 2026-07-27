<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledArticlePublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_scheduled_command_publishes_only_due_articles(): void
    {
        $approver = User::factory()->create();
        $dueArticle = Article::factory()->create([
            'title' => 'Закажана објава',
            'status' => 'scheduled',
            'published_at' => null,
            'scheduled_at' => now()->subMinute(),
            'approved_at' => now()->subHour(),
            'approved_by' => $approver->id,
        ]);

        $futureArticle = Article::factory()->create([
            'status' => 'scheduled',
            'published_at' => null,
            'scheduled_at' => now()->addHour(),
        ]);

        $draftArticle = Article::factory()->create([
            'status' => 'draft',
            'published_at' => null,
            'scheduled_at' => now()->subHour(),
        ]);

        $this->artisan('articles:publish-scheduled')
            ->expectsOutput('Published 1 scheduled article.')
            ->assertExitCode(0);

        $this->assertSame('published', $dueArticle->fresh()->status);
        $this->assertNotNull($dueArticle->fresh()->published_at);
        $this->assertNull($dueArticle->fresh()->scheduled_at);

        $this->assertSame('scheduled', $futureArticle->fresh()->status);
        $this->assertNull($futureArticle->fresh()->published_at);

        $this->assertSame('draft', $draftArticle->fresh()->status);
        $this->assertNull($draftArticle->fresh()->published_at);
    }

    public function test_scheduled_article_without_approval_fails_instead_of_publishing(): void
    {
        $article = Article::factory()->create([
            'status' => 'scheduled',
            'published_at' => null,
            'scheduled_at' => now()->subMinute(),
            'approved_at' => null,
            'approved_by' => null,
        ]);

        $this->artisan('articles:publish-scheduled')
            ->expectsOutput('Published 0 scheduled articles.')
            ->assertExitCode(0);

        $fresh = $article->fresh();

        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->publication_failed_at);
        $this->assertSame('Scheduled article is missing approval metadata.', $fresh->publication_failure_reason);
        $this->assertDatabaseHas(AdminAuditLog::class, [
            'auditable_type' => Article::class,
            'auditable_id' => $article->id,
            'action' => 'article.publication_failed',
        ]);
    }
}
