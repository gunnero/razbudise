<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\HomepageSection;
use App\Services\Articles\ArticlePublicationPropagationDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ArticlePublicationPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_article_is_immediately_visible_on_every_public_discovery_surface(): void
    {
        HomepageSection::query()->create([
            'name' => 'Најново',
            'key' => 'latest',
            'type' => 'latest',
            'configuration' => ['limit' => 6],
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $article = Article::factory()->published()->create([
            'title' => 'Проверка на јавната објава',
            'slug' => 'proverka-na-javnata-objava',
        ]);

        $this->getJson('/api/articles/proverka-na-javnata-objava')
            ->assertOk()
            ->assertJsonPath('data.id', $article->id);
        $this->getJson('/api/homepage')
            ->assertOk()
            ->assertJsonPath('data.sections.0.articles.0.id', $article->id);
        $this->getJson("/api/categories/{$article->category->slug}/articles")
            ->assertOk()
            ->assertJsonPath('data.articles.data.0.id', $article->id);
        $this->getJson("/api/authors/{$article->author->slug}/articles")
            ->assertOk()
            ->assertJsonPath('data.0.id', $article->id);
        $this->getJson('/api/search?query='.urlencode('Проверка на јавната објава'))
            ->assertOk()
            ->assertJsonPath('data.articles.data.0.id', $article->id);
    }

    public function test_propagation_diagnostics_log_structured_failure_context(): void
    {
        Log::spy();
        $article = Article::factory()->create([
            'status' => 'published',
            'published_at' => now()->addHour(),
        ]);

        $context = app(ArticlePublicationPropagationDiagnostics::class)
            ->verify($article, 'feature_test');

        $this->assertFalse($context['checks']['public_route']);
        $this->assertContains('public_route', $context['failures']);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $logged): bool => $message === 'Article publication propagation check failed.'
                && $logged['article_id'] === $article->id
                && $logged['surface'] === 'feature_test'
                && in_array('public_route', $logged['failures'], true));
    }
}
