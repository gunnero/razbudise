<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Models\Article;
use App\Models\Author;
use App\Models\EditorialSupervision;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Articles\ArticleEditorialWorkflow;
use App\Services\Articles\ArticleFormDataService;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ArticlePublicationRolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_editorial_publishers_receive_seo_access_but_authors_do_not(): void
    {
        $this->seedRoles();

        foreach (['super_admin', 'admin', 'editor_in_chief', 'editor'] as $roleName) {
            $this->assertTrue(
                Role::query()->where('name', $roleName)->firstOrFail()
                    ->permissions()
                    ->where('name', 'seo.manage')
                    ->exists(),
                "Expected {$roleName} to receive seo.manage.",
            );
        }

        foreach (['author', 'contributor'] as $roleName) {
            $this->assertFalse(
                Role::query()->where('name', $roleName)->firstOrFail()
                    ->permissions()
                    ->where('name', 'seo.manage')
                    ->exists(),
                "Expected {$roleName} not to receive seo.manage.",
            );
        }
    }

    public function test_editor_can_publish_and_schedule_directly_without_a_separate_approval_step(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create(['is_active' => true]);
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $author->id,
        ]);
        $workflow = app(ArticleEditorialWorkflow::class);

        $published = $workflow->publish(Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'draft',
        ]), $editor);

        $this->assertSame('published', $published->status);
        $this->assertSame($editor->id, $published->published_by);
        $this->assertSame($editor->id, $published->approved_by);
        $this->assertNotNull($published->approved_at);

        $scheduledAt = now()->addDay()->startOfMinute();
        $scheduled = $workflow->schedule(Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'draft',
        ]), $scheduledAt, $editor);

        $this->assertSame('scheduled', $scheduled->status);
        $this->assertSame($scheduledAt->toDateTimeString(), $scheduled->scheduled_at?->toDateTimeString());
        $this->assertSame($editor->id, $scheduled->approved_by);
        $this->assertNotNull($scheduled->approved_at);
    }

    public function test_author_can_submit_an_article_without_seo_but_cannot_publish_or_schedule_it(): void
    {
        $this->seedRoles();
        $authorUser = $this->userWithRole('author');
        $article = Article::factory()->create(['status' => 'draft']);
        TeamMember::factory()->create([
            'user_id' => $authorUser->id,
            'author_id' => $article->author_id,
        ]);
        $workflow = app(ArticleEditorialWorkflow::class);

        $submitted = $workflow->submit($article, $authorUser);
        $this->assertSame('submitted', $submitted->status);
        $this->assertFalse($submitted->seoMetadata()->exists());

        foreach (['publish', 'schedule'] as $transition) {
            try {
                $transition === 'schedule'
                    ? $workflow->schedule($submitted, now()->addHour(), $authorUser)
                    : $workflow->publish($submitted, $authorUser);
                $this->fail("Author unexpectedly performed {$transition}.");
            } catch (AuthorizationException) {
                $this->assertSame('submitted', $submitted->refresh()->status);
            }
        }
    }

    public function test_editor_in_chief_and_administrator_can_publish_directly(): void
    {
        $this->seedRoles();
        $workflow = app(ArticleEditorialWorkflow::class);

        foreach (['editor_in_chief', 'admin'] as $roleName) {
            $actor = $this->userWithRole($roleName);
            $article = Article::factory()->create(['status' => 'draft']);

            $published = $workflow->publish($article, $actor);

            $this->assertSame('published', $published->status);
            $this->assertSame($actor->id, $published->published_by);
            $this->assertSame($actor->id, $published->approved_by);
        }
    }

    public function test_missing_actor_cannot_use_the_direct_editor_publication_path(): void
    {
        $this->seedRoles();
        $workflow = app(ArticleEditorialWorkflow::class);
        $article = Article::factory()->create(['status' => 'draft']);

        $this->assertFalse($workflow->canPublish($article));

        try {
            $workflow->publish($article);
            $this->fail('A missing actor unexpectedly used direct publication.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame('draft', $article->refresh()->status);
    }

    public function test_article_actions_and_seo_fields_match_the_current_role(): void
    {
        $this->seedRoles();
        $authorUser = $this->userWithRole('author');
        $article = Article::factory()->create(['status' => 'draft']);
        TeamMember::factory()->create([
            'user_id' => $authorUser->id,
            'author_id' => $article->author_id,
        ]);

        $this->actingAs($authorUser);
        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertActionVisible('preview')
            ->assertActionVisible('send_to_review')
            ->assertActionHidden('publish')
            ->assertActionHidden('schedule')
            ->assertActionHidden('delete')
            ->assertFormFieldHidden('seoMetadata.seo_title')
            ->assertFormFieldHidden('seoMetadata.meta_description')
            ->fillForm([
                'seoMetadata' => [
                    'seo_title' => 'Неовластена SEO промена',
                    'meta_description' => 'Ова не смее да се зачува.',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($article->seoMetadata()->exists());

        $editor = $this->userWithRole('editor');
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $article->author_id,
        ]);
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertActionVisible('preview')
            ->assertActionHidden('send_to_review')
            ->assertActionVisible('publish')
            ->assertActionVisible('schedule')
            ->assertFormFieldVisible('seoMetadata.seo_title')
            ->assertFormFieldVisible('seoMetadata.meta_description')
            ->assertFormFieldVisible('seoMetadata.canonical_url')
            ->assertFormFieldVisible('seoMetadata.og_image_id')
            ->fillForm([
                'seoMetadata' => [
                    'seo_title' => 'Уреднички SEO наслов',
                    'meta_description' => 'Уреднички мета опис за објавата.',
                    'canonical_url' => 'https://razbudise.mk/'.$article->slug,
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('seo_metadata', [
            'seoable_type' => Article::class,
            'seoable_id' => $article->id,
            'seo_title' => 'Уреднички SEO наслов',
        ]);
    }

    public function test_editor_can_create_for_their_own_linked_author_profile(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $ownAuthor = Author::factory()->create(['is_active' => true]);
        TeamMember::factory()->create([
            'user_id' => $editor->id,
            'author_id' => $ownAuthor->id,
        ]);
        $this->actingAs($editor);

        $prepared = app(ArticleFormDataService::class)->prepare([
            'title' => 'Уредничка објава',
            'author_id' => $ownAuthor->id,
            'status' => 'draft',
            'type' => 'editorial',
        ]);

        $this->assertSame($ownAuthor->id, $prepared['author_id']);

        $ownArticle = Article::factory()->create([
            'author_id' => $ownAuthor->id,
            'status' => 'draft',
        ]);
        $this->assertTrue($editor->can('publish', $ownArticle));
        $this->assertTrue($editor->can('manageSeo', $ownArticle));

        Livewire::test(CreateArticle::class)
            ->assertFormSet(['author_id' => $ownAuthor->id])
            ->assertFormFieldVisible('seoMetadata.seo_title');
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
