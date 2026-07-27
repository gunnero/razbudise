<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\Author;
use App\Models\Category;
use App\Models\EditorialSupervision;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Articles\ArticleEditorialWorkflow;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use App\Services\Editorial\EditorialSupervisionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EditorialSupervisionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_elena_romina_supervision_sync_is_idempotent(): void
    {
        $this->seedRoles();

        $elena = $this->userWithRole('editor_in_chief', ['name' => 'Elena']);
        $rominaAuthor = Author::factory()->create(['name' => 'Romina', 'slug' => 'romina']);

        TeamMember::factory()->create([
            'name' => 'Елена Наумовска',
            'slug' => 'elena-naumovska',
            'user_id' => $elena->id,
            'status' => 'active',
        ]);
        TeamMember::factory()->create([
            'name' => 'Romina Markovska',
            'slug' => 'romina-markovska',
            'author_id' => $rominaAuthor->id,
            'status' => 'active',
        ]);

        app(EditorialSupervisionService::class)->syncLaunchMapping($elena);
        app(EditorialSupervisionService::class)->syncLaunchMapping($elena);

        $this->assertSame(1, EditorialSupervision::query()->count());
        $this->assertDatabaseHas('editorial_supervisions', [
            'editor_id' => $elena->id,
            'author_id' => $rominaAuthor->id,
            'is_primary' => true,
            'ends_at' => null,
        ]);
    }

    public function test_visibility_scope_matches_editorial_supervision_rules(): void
    {
        $this->seedRoles();

        $eic = $this->userWithRole('editor_in_chief');
        $editor = $this->userWithRole('editor');
        $unrelatedEditor = $this->userWithRole('editor');
        $authorUser = $this->userWithRole('author');

        $managedAuthor = Author::factory()->create();
        $otherAuthor = Author::factory()->create();
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $managedAuthor->id]);

        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $managedAuthor->id,
            'is_primary' => true,
            'ends_at' => null,
        ]);

        $managedDraft = Article::factory()->create(['author_id' => $managedAuthor->id, 'status' => 'draft']);
        $otherDraft = Article::factory()->create(['author_id' => $otherAuthor->id, 'status' => 'draft']);

        $this->assertContains($managedDraft->id, Article::query()->visibleToEditorialUser($eic)->pluck('id')->all());
        $this->assertContains($otherDraft->id, Article::query()->visibleToEditorialUser($eic)->pluck('id')->all());

        $this->assertSame([$managedDraft->id], Article::query()->visibleToEditorialUser($editor)->pluck('id')->all());
        $this->assertSame([], Article::query()->visibleToEditorialUser($unrelatedEditor)->pluck('id')->all());
        $this->assertSame([$managedDraft->id], Article::query()->visibleToEditorialUser($authorUser)->pluck('id')->all());
    }

    public function test_author_cannot_publish_and_supervising_editor_must_approve_first(): void
    {
        $this->seedRoles();

        $editor = $this->userWithRole('editor');
        $authorUser = $this->userWithRole('author');
        $author = Author::factory()->create(['is_active' => true]);
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $author->id]);
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $author->id,
            'is_primary' => true,
            'ends_at' => null,
        ]);

        $article = Article::factory()->create([
            'author_id' => $author->id,
            'category_id' => Category::factory()->create()->id,
            'status' => 'draft',
        ]);

        $workflow = app(ArticleEditorialWorkflow::class);
        $submitted = $workflow->submit($article, $authorUser);

        $this->assertSame('submitted', $submitted->status);
        $this->assertFalse($authorUser->can('publish', $submitted));

        $this->expectException(AuthorizationException::class);
        $workflow->publish($submitted, $authorUser);
    }

    public function test_supervising_editor_can_publish_submitted_managed_post_directly(): void
    {
        $this->seedRoles();

        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create(['is_active' => true]);
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $author->id,
            'is_primary' => true,
            'ends_at' => null,
        ]);

        $article = Article::factory()->create([
            'author_id' => $author->id,
            'category_id' => Category::factory()->create()->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $workflow = app(ArticleEditorialWorkflow::class);

        $published = $workflow->publish($article, $editor);

        $this->assertSame('published', $published->status);
        $this->assertSame($editor->id, $published->approved_by);
        $this->assertSame($editor->id, $published->published_by);
        $this->assertNotNull($published->approved_at);
        $this->assertNotNull($published->published_at);
    }

    public function test_blocking_editorial_comments_prevent_approval(): void
    {
        $this->seedRoles();

        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create(['is_active' => true]);
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $author->id,
            'is_primary' => true,
            'ends_at' => null,
        ]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'category_id' => Category::factory()->create()->id,
            'status' => 'in_review',
        ]);
        ArticleEditorialComment::factory()->create([
            'article_id' => $article->id,
            'author_id' => $editor->id,
            'is_blocking' => true,
            'is_resolved' => false,
        ]);

        $this->expectException(ValidationException::class);
        app(ArticleEditorialWorkflow::class)->approve($article, $editor);
    }

    public function test_supervising_editor_can_approve_then_publish(): void
    {
        $this->seedRoles();

        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create(['is_active' => true]);
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $author->id,
            'is_primary' => true,
            'ends_at' => null,
        ]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'category_id' => Category::factory()->create()->id,
            'status' => 'in_review',
        ]);

        $approved = app(ArticleEditorialWorkflow::class)->approve($article, $editor);
        $published = app(ArticleEditorialWorkflow::class)->publish($approved, $editor);

        $this->assertSame('published', $published->status);
        $this->assertSame($editor->id, $published->approved_by);
        $this->assertSame($editor->id, $published->published_by);
    }

    private function seedRoles(): void
    {
        app(RazbudiseRolePermissionMatrix::class)->sync();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(string $roleName, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
