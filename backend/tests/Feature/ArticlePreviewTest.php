<?php

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ArticlePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_preview_route_returns_unpublished_article_details(): void
    {
        $article = Article::factory()->create([
            'title' => 'Таен уреднички преглед',
            'slug' => 'taen-urednicki-pregled',
            'status' => 'review',
            'published_at' => null,
            'body_html' => '<p>Материјал за преглед.</p>',
        ]);

        $this->getJson('/api/articles/taen-urednicki-pregled')->assertNotFound();

        $previewPath = URL::temporarySignedRoute(
            'api.articles.preview',
            now()->addMinutes(15),
            ['article' => $article],
            absolute: false,
        );

        $this->getJson($previewPath)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertJsonPath('data.slug', 'taen-urednicki-pregled')
            ->assertJsonPath('data.status', 'review')
            ->assertJsonPath('data.body_html', '<p>Материјал за преглед.</p>');
    }

    public function test_signed_preview_supports_every_non_archived_editorial_state(): void
    {
        foreach (['draft', 'submitted', 'changes_requested', 'approved', 'scheduled'] as $status) {
            $article = Article::factory()->create([
                'status' => $status,
                'published_at' => null,
                'scheduled_at' => $status === 'scheduled' ? now()->addHour() : null,
            ]);

            $previewPath = URL::temporarySignedRoute(
                'api.articles.preview',
                now()->addMinutes(15),
                ['article' => $article],
                absolute: false,
            );

            $this->getJson($previewPath)
                ->assertOk()
                ->assertJsonPath('data.id', $article->id)
                ->assertJsonPath('data.status', $status);
        }
    }

    public function test_expired_signed_preview_fails_safely(): void
    {
        $article = Article::factory()->create();
        $previewPath = URL::temporarySignedRoute(
            'api.articles.preview',
            now()->subMinute(),
            ['article' => $article],
            absolute: false,
        );

        $this->getJson($previewPath)->assertForbidden();
    }

    public function test_signed_preview_route_rejects_invalid_signatures_and_archived_articles(): void
    {
        $article = Article::factory()->create([
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->getJson("/api/preview/articles/{$article->id}?signature=bad")->assertForbidden();

        $archived = Article::factory()->create([
            'status' => 'archived',
            'published_at' => null,
        ]);

        $previewPath = URL::temporarySignedRoute(
            'api.articles.preview',
            now()->addMinutes(15),
            ['article' => $archived],
            absolute: false,
        );

        $this->getJson($previewPath)->assertNotFound();
    }

    public function test_article_frontend_preview_url_contains_signed_api_path(): void
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
