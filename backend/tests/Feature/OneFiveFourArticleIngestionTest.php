<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OneFiveFourArticleIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'https://razbudise.test',
            'services.onefivefour.article_ingestion_token' => 'onefivefour-secret',
        ]);
    }

    public function test_ingestion_requires_server_to_server_bearer_token(): void
    {
        $this->postJson('/api/v1/integrations/onefivefour/articles', $this->validPayload())
            ->assertUnauthorized();

        $this->withToken('wrong-token')
            ->postJson('/api/v1/integrations/onefivefour/articles', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_ingestion_validates_payload_strongly(): void
    {
        $this->withToken('onefivefour-secret')
            ->postJson('/api/v1/integrations/onefivefour/articles', [
                'title' => '',
                'body_html' => '',
                'status' => 'live-now',
                'author' => [],
                'categories' => [],
                'tags' => [['bad' => 'shape']],
                'featured_image_url' => 'not-a-url',
                'source_id' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title',
                'body_html',
                'status',
                'author.name',
                'categories',
                'tags.0',
                'featured_image_url',
                'source_id',
            ]);
    }

    public function test_ingestion_creates_article_from_onefivefour_payload(): void
    {
        $response = $this->withToken('onefivefour-secret')
            ->postJson('/api/v1/integrations/onefivefour/articles', $this->validPayload())
            ->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'article_id',
                    'slug',
                    'status',
                    'preview_url',
                    'edit_url',
                ],
            ])
            ->assertJsonPath('data.status', 'draft');

        $article = Article::query()->firstOrFail();

        $this->assertSame($article->id, $response->json('data.article_id'));
        $this->assertSame('onefivefour-source-001', $article->onefivefour_source_id);
        $this->assertSame('Europe needs a cooling strategy.', $article->title);
        $this->assertSame('<p>Europe has a growing adaptation gap.</p>', $article->body_html);
        $this->assertSame('onefivefour', $article->onefivefour_metadata['source']);
        $this->assertStringContainsString($article->slug, $response->json('data.preview_url'));
        $this->assertStringContainsString("/backoffice/articles/{$article->id}/edit", $response->json('data.edit_url'));
    }

    public function test_ingestion_is_idempotent_and_updates_existing_article_by_source_id(): void
    {
        $this->withToken('onefivefour-secret')
            ->postJson('/api/v1/integrations/onefivefour/articles', $this->validPayload())
            ->assertCreated();

        $article = Article::query()->firstOrFail();

        $this->withToken('onefivefour-secret')
            ->postJson('/api/v1/integrations/onefivefour/articles', $this->validPayload([
                'title' => 'Updated cooling strategy',
                'status' => 'published',
                'excerpt' => 'Updated excerpt.',
            ]))
            ->assertOk()
            ->assertJsonPath('data.article_id', $article->id)
            ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseCount('articles', 1);

        $article->refresh();

        $this->assertSame('Updated cooling strategy', $article->title);
        $this->assertSame('published', $article->status);
        $this->assertSame('Updated excerpt.', $article->excerpt);
        $this->assertNotNull($article->published_at);
    }

    public function test_ingestion_maps_existing_author(): void
    {
        $author = Author::factory()->create([
            'name' => 'Mila Andonova',
            'slug' => 'mila-andonova',
            'email' => 'mila@onefivefour.ai',
        ]);

        $this->withToken('onefivefour-secret')
            ->postJson('/api/v1/integrations/onefivefour/articles', $this->validPayload())
            ->assertCreated();

        $this->assertSame($author->id, Article::query()->firstOrFail()->author_id);
        $this->assertDatabaseCount('authors', 1);
    }

    public function test_ingestion_maps_existing_categories(): void
    {
        $primary = Category::factory()->create([
            'name' => 'Европа',
            'slug' => 'evropa',
        ]);
        $secondary = Category::factory()->create([
            'name' => 'Клима',
            'slug' => 'klima',
        ]);

        $this->withToken('onefivefour-secret')
            ->postJson('/api/v1/integrations/onefivefour/articles', $this->validPayload([
                'categories' => [
                    ['name' => 'Европа', 'slug' => 'evropa'],
                    ['name' => 'Клима', 'slug' => 'klima'],
                ],
            ]))
            ->assertCreated();

        $article = Article::query()->firstOrFail()->load('categories');

        $this->assertSame($primary->id, $article->category_id);
        $this->assertEqualsCanonicalizing(
            [$primary->id, $secondary->id],
            $article->categories->pluck('id')->all(),
        );
        $this->assertDatabaseCount('categories', 2);
    }

    public function test_ingestion_creates_missing_tags(): void
    {
        $this->withToken('onefivefour-secret')
            ->postJson('/api/v1/integrations/onefivefour/articles', $this->validPayload([
                'tags' => ['Europe', 'Air Conditioning', 'Climate Adaptation'],
            ]))
            ->assertCreated();

        $article = Article::query()->firstOrFail()->load('tags');

        $this->assertEqualsCanonicalizing(
            ['air-conditioning', 'climate-adaptation', 'europe'],
            $article->tags->pluck('slug')->all(),
        );
        $this->assertSame(3, Tag::query()->count());
    }

    public function test_ingestion_localizes_featured_image(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://onefivefour.test/images/cooling.png' => Http::response($this->pngBytes(), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $this->withToken('onefivefour-secret')
            ->postJson('/api/v1/integrations/onefivefour/articles', $this->validPayload([
                'featured_image_url' => 'https://onefivefour.test/images/cooling.png',
            ]))
            ->assertCreated();

        $article = Article::query()->firstOrFail()->load('featuredImage');

        $this->assertInstanceOf(MediaAsset::class, $article->featuredImage);
        $this->assertSame('public', $article->featuredImage->disk);
        $this->assertStringStartsWith('media/onefivefour/', $article->featuredImage->path);
        $this->assertSame('https://onefivefour.test/images/cooling.png', $article->featuredImage->metadata['source_url']);
        $this->assertSame('onefivefour-source-001', $article->featuredImage->metadata['onefivefour_source_id']);

        Storage::disk('public')->assertExists($article->featuredImage->path);
    }

    public function test_ingestion_creates_admin_audit_log(): void
    {
        $this->withToken('onefivefour-secret')
            ->postJson('/api/v1/integrations/onefivefour/articles', $this->validPayload())
            ->assertCreated();

        $article = Article::query()->firstOrFail();
        $auditLog = AdminAuditLog::query()->firstOrFail();

        $this->assertSame('onefivefour.article_created', $auditLog->action);
        $this->assertSame(Article::class, $auditLog->auditable_type);
        $this->assertSame($article->id, $auditLog->auditable_id);
        $this->assertSame('onefivefour-source-001', $auditLog->metadata['source_id']);
        $this->assertSame('onefivefour', $auditLog->metadata['integration']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'Europe needs a cooling strategy.',
            'excerpt' => 'Heat waves are exposing infrastructure gaps.',
            'body_html' => '<p>Europe has a growing adaptation gap.</p>',
            'status' => 'draft',
            'author' => [
                'name' => 'Mila Andonova',
                'email' => 'mila@onefivefour.ai',
                'slug' => 'mila-andonova',
            ],
            'categories' => [
                ['name' => 'Европа', 'slug' => 'evropa'],
            ],
            'tags' => ['Europe', 'Climate'],
            'featured_image_url' => null,
            'seo' => [
                'title' => 'Europe needs a cooling strategy',
                'meta_description' => 'Heat waves are exposing infrastructure gaps.',
                'canonical_url' => 'https://onefivefour.ai/articles/europe-cooling',
            ],
            'og' => [
                'title' => 'Europe needs a cooling strategy',
                'description' => 'Heat waves are exposing infrastructure gaps.',
            ],
            'source_id' => 'onefivefour-source-001',
        ], $overrides);
    }

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
        );
    }
}
