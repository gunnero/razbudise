<?php

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticlePublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_scope_returns_only_published_articles_with_published_dates(): void
    {
        $published = Article::factory()->create([
            'title' => 'Објавена приказна',
            'slug' => 'objavena-prikazna',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        Article::factory()->create([
            'status' => 'draft',
            'published_at' => now()->subMinute(),
        ]);

        Article::factory()->create([
            'status' => 'published',
            'published_at' => now()->addDay(),
        ]);

        $this->assertTrue(Article::published()->get()->contains($published));
        $this->assertSame([$published->id], Article::published()->pluck('id')->all());
    }

    public function test_article_can_be_published_scheduled_and_archived(): void
    {
        $article = Article::factory()->create([
            'status' => 'draft',
            'published_at' => null,
            'scheduled_at' => null,
        ]);

        $article->publish();

        $this->assertSame('published', $article->fresh()->status);
        $this->assertNotNull($article->fresh()->published_at);

        $scheduledAt = now()->addDay();

        $article->scheduleFor($scheduledAt);

        $this->assertSame('scheduled', $article->fresh()->status);
        $this->assertSame($scheduledAt->toDateTimeString(), $article->fresh()->scheduled_at->toDateTimeString());

        $article->archive();

        $this->assertSame('archived', $article->fresh()->status);
    }

    public function test_article_public_url_uses_configured_frontend_url(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001/']);

        $article = Article::factory()->create([
            'slug' => 'javen-pregled',
        ]);

        $this->assertSame('http://localhost:3001/javen-pregled', $article->publicUrl());
    }

    public function test_article_preview_url_generates_signed_preview_path(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001/']);

        $article = Article::factory()->create([
            'slug' => 'draft-preview',
            'status' => 'draft',
            'published_at' => null,
        ]);

        $previewUrl = $article->previewUrl();

        $this->assertStringStartsWith('http://localhost:3001/draft-preview?preview_path=', $previewUrl);
        $this->assertStringContainsString(rawurlencode('/api/preview/articles/'.$article->id), $previewUrl);
        $this->assertStringContainsString('signature', rawurldecode($previewUrl));
    }
}
