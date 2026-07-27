<?php

namespace Tests\Feature;

use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\EditorialSupervision;
use App\Models\Role;
use App\Models\Tag;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Articles\ArticleEditorialWorkflow;
use App\Services\Articles\ArticleRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleAuthoringAndRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_account_creates_article_with_its_linked_author_automatically(): void
    {
        $this->seed();
        $user = $this->userWithRole('author');
        $author = Author::factory()->create();
        $otherAuthor = Author::factory()->create();
        $category = Category::factory()->create(['is_active' => true]);
        TeamMember::factory()->create(['user_id' => $user->id, 'author_id' => $author->id]);
        $otherArticle = Article::factory()->create(['author_id' => $otherAuthor->id, 'status' => 'draft']);
        $this->actingAs($user);

        Livewire::test(CreateArticle::class)
            ->assertFormSet(['author_id' => $author->id])
            ->fillForm([
                'title' => 'Моја нова објава',
                'slug' => 'moja-nova-objava',
                'body_html' => '<p>Содржина</p>',
                'author_id' => $author->id,
                'category_id' => $category->id,
                'status' => 'draft',
                'type' => 'editorial',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = Article::query()->where('slug', 'moja-nova-objava')->firstOrFail();
        $this->assertSame($author->id, $article->author_id);
        $this->assertSame($user->id, $article->created_by);
        $this->assertSame(1, $article->revisions()->count());
        $this->assertFalse($user->can('update', $otherArticle));
        $visibleArticleIds = ArticleResource::getEloquentQuery()->pluck('id');
        $this->assertTrue($visibleArticleIds->contains($article->id));
        $this->assertFalse($visibleArticleIds->contains($otherArticle->id));
    }

    public function test_revision_can_restore_article_content_and_creates_a_new_audit_version(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $tag = Tag::query()->firstOrCreate(['slug' => 'revision-test-tag'], ['name' => 'Revision test']);
        $article = Article::factory()->create(['title' => 'Original title', 'body_html' => '<p>Original</p>']);
        $article->tags()->sync([$tag->id]);
        $article->blocks()->create(['type' => 'paragraph', 'data' => ['html' => 'Original'], 'sort_order' => 1]);
        $revisions = app(ArticleRevisionService::class);
        $original = $revisions->capture($article, $admin, 'created');

        $article->update(['title' => 'Changed title', 'body_html' => '<p>Changed</p>']);
        $article->tags()->detach();
        $article->blocks()->delete();
        $revisions->capture($article, $admin);
        $restored = $revisions->restore($original, $admin);

        $this->assertSame('Original title', $restored->title);
        $this->assertSame('<p>Original</p>', $restored->body_html);
        $this->assertSame([$tag->id], $restored->tags()->pluck('tags.id')->all());
        $this->assertSame('Original', $restored->blocks()->firstOrFail()->data['html']);
        $this->assertSame(3, $restored->revisions()->count());
        $this->assertSame('restored revision 1', $restored->revisions()->first()->reason);
    }

    public function test_editor_can_create_submit_approve_and_schedule_article(): void
    {
        $this->seed();
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create();
        EditorialSupervision::factory()->create(['editor_id' => $editor->id, 'author_id' => $author->id]);
        $category = Category::factory()->create(['is_active' => true]);
        $scheduledAt = now()->addDay()->startOfMinute();
        $this->actingAs($editor);

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'title' => 'Закажана објава',
                'slug' => 'zakazana-objava',
                'excerpt' => 'Краток опис за закажаната објава.',
                'body_html' => '<p>Содржина</p>',
                'author_id' => $author->id,
                'category_id' => $category->id,
                'status' => 'submitted',
                'type' => 'editorial',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = Article::query()->where('slug', 'zakazana-objava')->firstOrFail();
        $workflow = app(ArticleEditorialWorkflow::class);
        $approved = $workflow->approve($article, $editor);
        $scheduled = $workflow->schedule($approved, $scheduledAt, $editor);

        $this->assertSame('scheduled', $scheduled->status);
        $this->assertTrue($scheduled->scheduled_at?->isFuture());
        $this->assertNull($scheduled->published_at);
        $this->assertSame($editor->id, $scheduled->approved_by);
    }

    public function test_editor_cannot_schedule_directly_from_the_create_form(): void
    {
        $this->seed();
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create();
        EditorialSupervision::factory()->create(['editor_id' => $editor->id, 'author_id' => $author->id]);
        $category = Category::factory()->create(['is_active' => true]);
        $scheduledAt = now()->addDay()->startOfMinute();
        $this->actingAs($editor);

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'title' => 'Закажана директно',
                'slug' => 'zakazana-direktno',
                'excerpt' => 'Краток опис.',
                'body_html' => '<p>Содржина</p>',
                'author_id' => $author->id,
                'category_id' => $category->id,
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                'type' => 'editorial',
            ])
            ->call('create')
            ->assertStatus(422);

        $article = Article::query()->where('slug', 'zakazana-direktno')->first();

        $this->assertNull($article);
    }

    public function test_legacy_article_schedule_helper_still_sets_scheduled_state(): void
    {
        $scheduledAt = now()->addDay();
        $article = Article::factory()->create([
            'status' => 'draft',
            'published_at' => null,
            'scheduled_at' => null,
        ]);

        $article->scheduleFor($scheduledAt);

        $this->assertSame('scheduled', $article->status);
        $this->assertSame($scheduledAt->toDateTimeString(), $article->fresh()->scheduled_at->toDateTimeString());
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
