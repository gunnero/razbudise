<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Articles\RelationManagers\EditorialCommentsRelationManager;
use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\Author;
use App\Models\Category;
use App\Models\EditorialSupervision;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Articles\ArticleFormDataService;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use App\Support\AdminTranslations;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleReviewWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_resource_defers_navigation_to_the_shared_newsroom_builder(): void
    {
        $this->seedRoles();

        $this->actingAs($this->userWithRole('editor'));

        $this->assertFalse(ArticleResource::shouldRegisterNavigation());
        $this->assertSame('Content', ArticleResource::getNavigationGroup());
        $this->assertSame('Articles', ArticleResource::getNavigationLabel());
    }

    public function test_create_article_prefills_only_an_author_inside_the_editor_scope(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $managedAuthor = Author::factory()->create(['is_active' => true]);
        $unrelatedAuthor = Author::factory()->create(['is_active' => true]);
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $managedAuthor->id,
            'ends_at' => null,
        ]);
        $this->actingAs($editor);

        Livewire::withQueryParams(['author_id' => $managedAuthor->id])
            ->test(CreateArticle::class)
            ->assertFormSet(['author_id' => $managedAuthor->id]);

        Livewire::withQueryParams(['author_id' => $unrelatedAuthor->id])
            ->test(CreateArticle::class)
            ->assertFormSet(['author_id' => null]);
    }

    public function test_editor_cannot_create_an_article_for_an_unmanaged_author(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $unrelatedAuthor = Author::factory()->create(['is_active' => true]);
        $category = Category::factory()->create(['is_active' => true]);
        $this->actingAs($editor);

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'title' => 'Туѓа авторска објава',
                'excerpt' => 'Краток опис.',
                'body_html' => '<p>Содржина</p>',
                'author_id' => $unrelatedAuthor->id,
                'category_id' => $category->id,
                'status' => 'draft',
                'type' => 'editorial',
            ])
            ->call('create')
            ->assertHasFormErrors(['author_id']);

        $this->assertDatabaseMissing('articles', ['title' => 'Туѓа авторска објава']);
    }

    public function test_author_assignment_is_forced_to_the_linked_profile_server_side(): void
    {
        $this->seedRoles();
        $authorUser = $this->userWithRole('author');
        $ownAuthor = Author::factory()->create();
        $otherAuthor = Author::factory()->create();
        TeamMember::factory()->create([
            'user_id' => $authorUser->id,
            'author_id' => $ownAuthor->id,
        ]);
        $this->actingAs($authorUser);

        $prepared = app(ArticleFormDataService::class)->prepare([
            'title' => 'Серверски ограничена објава',
            'author_id' => $otherAuthor->id,
            'status' => 'draft',
            'type' => 'editorial',
        ]);

        $this->assertSame($ownAuthor->id, $prepared['author_id']);
    }

    public function test_assigned_editor_can_preserve_the_current_unmanaged_author_on_edit(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $currentAuthor = Author::factory()->create();
        $article = Article::factory()->create([
            'author_id' => $currentAuthor->id,
            'assigned_editor_id' => $editor->id,
            'status' => 'draft',
        ]);
        $this->actingAs($editor);

        $prepared = app(ArticleFormDataService::class)->prepare([
            'title' => $article->title,
            'author_id' => $currentAuthor->id,
            'status' => 'draft',
            'type' => $article->type,
        ], $article);

        $this->assertSame($currentAuthor->id, $prepared['author_id']);
    }

    public function test_articles_table_supports_distinct_newsroom_presets_and_operational_columns(): void
    {
        $this->seedRoles();
        $this->actingAs($this->userWithRole('super_admin'));

        $scheduledThisWeek = Article::factory()->create([
            'status' => 'scheduled',
            'scheduled_at' => now()->startOfWeek()->addDay(),
        ]);
        $scheduledLater = Article::factory()->create([
            'status' => 'scheduled',
            'scheduled_at' => now()->addWeeks(3),
        ]);

        Livewire::test(ListArticles::class)
            ->assertTableFilterExists('editorial_queue')
            ->assertTableColumnExists('updated_at')
            ->assertTableColumnExists('submitted_at')
            ->assertTableColumnExists('scheduled_at')
            ->assertTableColumnExists('published_at')
            ->assertTableColumnExists('unresolved_comments_count')
            ->assertTableColumnExists('views_count')
            ->filterTable('editorial_queue', 'scheduled_this_week')
            ->assertCanSeeTableRecords([$scheduledThisWeek])
            ->assertCanNotSeeTableRecords([$scheduledLater]);
    }

    public function test_articles_table_uses_compact_accessible_newsroom_columns(): void
    {
        $this->seedRoles();
        $this->actingAs($this->userWithRole('super_admin'));

        $author = Author::factory()->create(['name' => 'Тест Автор']);
        $category = Category::factory()->create(['name' => 'Култура']);
        $supervisingEditor = User::factory()->create(['name' => 'Надлежен Уредник']);
        $assignedEditor = User::factory()->create(['name' => 'Назначен Уредник']);
        $articleWithComment = Article::factory()->create([
            'title' => 'Долг наслов што треба да остане целосно достапен во табелата за објави',
            'type' => 'editorial',
            'author_id' => $author->id,
            'category_id' => $category->id,
            'supervising_editor_id' => $supervisingEditor->id,
            'assigned_editor_id' => $assignedEditor->id,
        ]);
        $articleWithoutComments = Article::factory()->create();
        ArticleEditorialComment::factory()->create([
            'article_id' => $articleWithComment->id,
            'is_resolved' => false,
        ]);

        $component = Livewire::test(ListArticles::class)
            ->assertTableColumnExists('title', fn (TextColumn $column): bool => $column->canWrap()
                && $column->getLineClamp() === 2
                && $column->getWidth() === '18rem'
                && $column->getTooltip($articleWithComment->title) === $articleWithComment->title)
            ->assertTableColumnHasDescription(
                'title',
                'Тест Автор · Култура · '.AdminTranslations::articleType('editorial'),
                $articleWithComment,
            )
            ->assertTableColumnStateSet('editorial_editor', 'Надлежен Уредник', $articleWithComment)
            ->assertTableColumnHasDescription('editorial_editor', 'Назначен: Назначен Уредник', $articleWithComment)
            ->assertTableColumnStateSet('unresolved_comments_count', 1, $articleWithComment)
            ->assertTableColumnStateSet('unresolved_comments_count', null, $articleWithoutComments)
            ->assertTableColumnExists('type', fn (TextColumn $column): bool => $column->isToggledHiddenByDefault())
            ->assertTableColumnExists('author.name', fn (TextColumn $column): bool => $column->isToggledHiddenByDefault())
            ->assertTableColumnExists('category.name', fn (TextColumn $column): bool => $column->isToggledHiddenByDefault())
            ->assertCanRenderTableColumn('title')
            ->assertCanRenderTableColumn('editorial_editor')
            ->assertCanNotRenderTableColumn('author.name')
            ->assertCanNotRenderTableColumn('category.name');

        $this->assertStringEndsWith('_columns_v2', $component->instance()->getTableColumnsSessionKey());

        $tableAttributes = $component->instance()->getTable()->getExtraAttributes();
        $this->assertSame('newsroom-posts-table', $tableAttributes['class']);
        $this->assertStringContainsString("scroller.setAttribute('tabindex', '0')", $tableAttributes['x-init']);
        $this->assertStringContainsString("scroller.setAttribute('aria-label', 'Објави, скролувачка табела')", $tableAttributes['x-init']);
    }

    public function test_review_screen_renders_checklist_and_handles_associative_guide_event_keys(): void
    {
        $this->seedRoles();
        $this->actingAs($this->userWithRole('super_admin'));

        $article = Article::factory()->create([
            'presentation_type' => 'event_guide',
            'guide_metadata' => [
                'events' => [
                    'temporary-repeater-key' => [
                        'title' => '',
                        'start_date' => null,
                    ],
                ],
            ],
        ]);

        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertOk()
            ->assertSee('Проверка пред објавување')
            ->assertSee('Работен тек')
            ->assertSee('Настан 1: недостига наслов.');
    }

    public function test_workflow_action_state_is_not_reverted_by_a_later_form_save(): void
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
            ->callAction(TestAction::make('send_to_review'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('submitted', $article->refresh()->status);
    }

    public function test_author_cannot_see_internal_comments_and_editor_can_reply(): void
    {
        $this->seedRoles();

        $authorUser = $this->userWithRole('author');
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create();
        TeamMember::factory()->create([
            'user_id' => $authorUser->id,
            'author_id' => $author->id,
        ]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'assigned_editor_id' => $editor->id,
            'status' => 'draft',
        ]);
        $authorVisible = ArticleEditorialComment::factory()->create([
            'article_id' => $article->id,
            'author_id' => $editor->id,
            'body' => 'Коментар за авторот',
            'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
        ]);
        $internal = ArticleEditorialComment::factory()->create([
            'article_id' => $article->id,
            'author_id' => $editor->id,
            'body' => 'Само интерна белешка',
            'visibility' => ArticleEditorialComment::VISIBILITY_INTERNAL,
        ]);
        $authoredComment = ArticleEditorialComment::factory()->create([
            'article_id' => $article->id,
            'author_id' => $authorUser->id,
            'body' => 'Авторски одговор',
            'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
        ]);

        $this->actingAs($authorUser);
        Livewire::test(EditorialCommentsRelationManager::class, [
            'ownerRecord' => $article,
            'pageClass' => EditArticle::class,
        ])
            ->assertTableFilterExists('resolution')
            ->assertCanSeeTableRecords([$authorVisible])
            ->assertCanNotSeeTableRecords([$internal]);

        $this->actingAs($editor);
        $editorComments = Livewire::test(EditorialCommentsRelationManager::class, [
            'ownerRecord' => $article,
            'pageClass' => EditArticle::class,
        ]);
        $editorComments->callTableAction('reply', $authorVisible, [
            'body' => 'Уреднички одговор',
            'visibility' => ArticleEditorialComment::VISIBILITY_INTERNAL,
            'is_blocking' => false,
        ]);
        $editorComments->callTableAction('edit', $authoredComment, [
            'body' => 'Авторски одговор со исправка',
            'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
            'is_blocking' => false,
        ]);

        $this->assertDatabaseHas('article_editorial_comments', [
            'article_id' => $article->id,
            'parent_id' => $authorVisible->id,
            'author_id' => $editor->id,
            'body' => 'Уреднички одговор',
            'visibility' => ArticleEditorialComment::VISIBILITY_INTERNAL,
        ]);
        $this->assertSame($authorUser->id, $authoredComment->refresh()->author_id);
    }

    private function seedRoles(): void
    {
        app(RazbudiseRolePermissionMatrix::class)->sync();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
