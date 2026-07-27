<?php

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleViewTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_article_views_are_deduplicated_per_visitor_and_day(): void
    {
        $article = Article::factory()->create([
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);

        $payload = ['visitor_token' => 'reader-token-1234567890', 'analytics_consent' => true, 'referrer' => 'https://google.com/search?q=razbudise'];

        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson("/api/articles/{$article->slug}/view", $payload)->assertAccepted();
        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson("/api/articles/{$article->slug}/view", $payload)->assertAccepted();
        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson("/api/articles/{$article->slug}/view", ['visitor_token' => 'another-reader-1234567890', 'analytics_consent' => true])->assertAccepted();

        $this->assertDatabaseCount('article_views', 2);
        $this->assertDatabaseHas('article_views', [
            'article_id' => $article->id,
            'referrer_host' => 'google.com',
        ]);
    }

    public function test_drafts_cannot_receive_public_view_events(): void
    {
        $article = Article::factory()->create(['status' => 'draft', 'published_at' => null]);

        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson("/api/articles/{$article->slug}/view", ['visitor_token' => 'reader-token-1234567890', 'analytics_consent' => true])
            ->assertNotFound();
    }

    public function test_consent_and_human_user_agent_are_required_before_a_view_is_stored(): void
    {
        $article = Article::factory()->published()->create();

        $this->withHeader('User-Agent', 'Mozilla/5.0')->postJson("/api/articles/{$article->slug}/view", [
            'visitor_token' => 'reader-token-1234567890',
        ])->assertUnprocessable();
        $this->withHeader('User-Agent', 'Googlebot/2.1')->postJson("/api/articles/{$article->slug}/view", [
            'visitor_token' => 'reader-token-1234567890',
            'analytics_consent' => true,
        ])->assertAccepted()->assertJson(['recorded' => false]);

        $this->assertDatabaseCount('article_views', 0);
    }
}
