<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\RelationManagers\ChangeRequestsRelationManager;
use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\Author;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Articles\ArticleChangeRequestService;
use App\Services\Articles\ArticlePublicationReadiness;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use App\Support\AdminTranslations;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RequestedChangesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_requires_a_summary_and_at_least_one_actionable_comment(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $article = Article::factory()->create([
            'status' => 'in_review',
            'assigned_editor_id' => $editor->id,
        ]);

        try {
            app(ArticleChangeRequestService::class)->request($article, $editor, [
                'summary' => '',
                'comments' => [],
            ]);
            $this->fail('A vague status-only change request was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('summary', $exception->errors());
            $this->assertArrayHasKey('comments', $exception->errors());
        }

        $this->assertSame('in_review', $article->refresh()->status);
        $this->assertDatabaseCount('article_change_requests', 0);
        $this->assertDatabaseCount('article_editorial_comments', 0);
    }

    public function test_request_creates_visible_comments_notifies_the_author_and_preserves_structured_history(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $authorUser = $this->userWithRole('author');
        $author = Author::factory()->create();
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $author->id]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'in_review',
            'assigned_editor_id' => $editor->id,
        ]);

        $result = app(ArticleChangeRequestService::class)->request($article, $editor, [
            'summary' => 'Потребна е појасна структура и проверка на фактите.',
            'deadline_at' => now()->addDays(2)->toDateString(),
            'comments' => [
                ['body' => 'Наведете извор за првата бројка.', 'is_blocking' => true],
                ['body' => 'Скратете го вториот пасус.', 'is_blocking' => false],
            ],
        ]);

        $this->assertSame('changes_requested', $result->status);
        $request = $article->changeRequests()->with('comments')->sole();
        $this->assertSame($editor->id, $request->requested_by);
        $this->assertSame('Потребна е појасна структура и проверка на фактите.', $request->summary);
        $this->assertCount(2, $request->comments);
        $this->assertTrue($request->comments->first()->is_blocking);
        $this->assertTrue($request->comments->every(
            fn (ArticleEditorialComment $comment): bool => $comment->visibility === ArticleEditorialComment::VISIBILITY_AUTHOR,
        ));
        $this->assertDatabaseHas('admin_audit_logs', [
            'auditable_type' => Article::class,
            'auditable_id' => $article->id,
            'action' => 'article.change_request_created',
        ]);

        $notification = $authorUser->notifications()->firstOrFail();
        $this->assertSame('Потребна доработка', $notification->data['title']);
        $this->assertStringContainsString('појасна структура', (string) $notification->data['body']);
        $this->assertStringContainsString('Рок:', (string) $notification->data['body']);
    }

    public function test_editor_request_changes_action_uses_the_required_structured_modal_payload(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $article = Article::factory()->create([
            'status' => 'in_review',
            'assigned_editor_id' => $editor->id,
        ]);
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertActionVisible('request_changes')
            ->callAction(TestAction::make('request_changes'), [
                'summary' => 'Потребна е проверка на воведот.',
                'comments' => [
                    ['body' => 'Потврдете го датумот во првиот пасус.', 'is_blocking' => true],
                ],
                'deadline_at' => now()->addDay()->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('changes_requested', $article->refresh()->status);
        $this->assertDatabaseHas('article_change_requests', [
            'article_id' => $article->id,
            'summary' => 'Потребна е проверка на воведот.',
        ]);
        $this->assertDatabaseHas('article_editorial_comments', [
            'article_id' => $article->id,
            'body' => 'Потврдете го датумот во првиот пасус.',
            'is_blocking' => true,
        ]);
    }

    public function test_author_can_reply_mark_an_item_addressed_and_resubmit_without_losing_history(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $authorUser = $this->userWithRole('author');
        $author = Author::factory()->create();
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $author->id]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'in_review',
            'assigned_editor_id' => $editor->id,
            'submitted_by' => $authorUser->id,
        ]);
        $service = app(ArticleChangeRequestService::class);
        $service->request($article, $editor, [
            'summary' => 'Проверете го воведот.',
            'comments' => [
                ['body' => 'Додадете контекст во воведот.', 'is_blocking' => true],
            ],
        ]);
        $request = $article->changeRequests()->sole();
        $comment = $request->comments()->sole();
        $comment->replies()->create([
            'article_id' => $article->id,
            'author_id' => $authorUser->id,
            'body' => 'Контекстот е додаден.',
            'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
            'is_blocking' => false,
            'is_resolved' => false,
        ]);

        $addressed = $service->markAddressed($comment, $authorUser);
        $this->assertSame($authorUser->id, $addressed->addressed_by);
        $this->assertNotNull($addressed->addressed_at);
        $this->assertFalse($addressed->is_resolved, 'Only the editor closes the comment after verification.');

        $resubmitted = $service->submitForReview($article->refresh(), $authorUser);

        $this->assertSame('submitted', $resubmitted->status);
        $this->assertNotNull($request->refresh()->resubmitted_at);
        $this->assertSame($authorUser->id, $request->resubmitted_by);
        $this->assertDatabaseHas('article_editorial_comments', [
            'id' => $comment->id,
            'change_request_id' => $request->id,
            'body' => 'Додадете контекст во воведот.',
        ]);
        $this->assertDatabaseHas('article_editorial_comments', [
            'parent_id' => $comment->id,
            'body' => 'Контекстот е додаден.',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'auditable_type' => Article::class,
            'auditable_id' => $article->id,
            'action' => 'article.change_request_resubmitted',
        ]);
        $this->assertGreaterThanOrEqual(1, $editor->notifications()->count());
    }

    public function test_checklist_explains_each_fix_and_hides_seo_from_authors(): void
    {
        $this->seedRoles();
        $authorUser = $this->userWithRole('author');
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create();
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $author->id]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'title' => '',
            'excerpt' => null,
            'body_html' => null,
            'featured_image_id' => null,
            'assigned_editor_id' => $editor->id,
        ]);
        $readiness = app(ArticlePublicationReadiness::class);

        $authorChecklist = $readiness->checklist($article, $authorUser);
        $this->assertArrayNotHasKey('seo_title', $authorChecklist);
        $this->assertArrayNotHasKey('meta_description', $authorChecklist);
        $this->assertArrayNotHasKey('canonical', $authorChecklist);
        $this->assertArrayNotHasKey('open_graph_image', $authorChecklist);
        $this->assertSame('blocking', $authorChecklist['title']['severity']);
        $this->assertNotEmpty($authorChecklist['title']['why']);
        $this->assertNotEmpty($authorChecklist['title']['how']);
        $this->assertSame('Додај наслов', $authorChecklist['title']['action_label']);
        $this->assertSame('data.title', $authorChecklist['title']['target']);

        $editorChecklist = $readiness->checklist($article, $editor);
        $this->assertArrayHasKey('seo_title', $editorChecklist);
        $this->assertArrayHasKey('meta_description', $editorChecklist);
        $this->assertArrayHasKey('canonical', $editorChecklist);
        $this->assertArrayHasKey('open_graph_image', $editorChecklist);
        $this->assertTrue($editorChecklist['approval_status']['complete']);
    }

    public function test_author_editor_shows_the_requested_changes_panel_and_working_version_terminology(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $authorUser = $this->userWithRole('author');
        $author = Author::factory()->create();
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $author->id]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'in_review',
            'assigned_editor_id' => $editor->id,
        ]);
        app(ArticleChangeRequestService::class)->request($article, $editor, [
            'summary' => 'Направете го воведот поконкретен.',
            'comments' => [
                ['body' => 'Додадете место и датум.', 'is_blocking' => true],
            ],
        ]);
        $changeComment = $article->changeRequests()->sole()->comments()->sole();
        $changeComment->replies()->createMany([
            [
                'article_id' => $article->id,
                'author_id' => $editor->id,
                'body' => 'Одговор видлив за авторот.',
                'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
                'is_blocking' => false,
                'is_resolved' => false,
            ],
            [
                'article_id' => $article->id,
                'author_id' => $editor->id,
                'body' => 'Доверлива интерна белешка.',
                'visibility' => ArticleEditorialComment::VISIBILITY_INTERNAL,
                'is_blocking' => false,
                'is_resolved' => false,
            ],
        ]);
        $this->actingAs($authorUser);

        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertOk()
            ->assertSeeHtml('data-testid="requested-change-panel"')
            ->assertSee('Потребна доработка')
            ->assertSee('Направете го воведот поконкретен.')
            ->assertSee('Додадете место и датум.')
            ->assertSee('Одговор видлив за авторот.')
            ->assertDontSee('Доверлива интерна белешка.')
            ->assertSee('Зошто и како да се поправи');

        app()->setLocale('mk');
        $this->assertSame('Работна верзија', AdminTranslations::articleStatus('draft'));
        $this->assertSame('draft', (new Article(['status' => 'draft']))->status);
    }

    public function test_requested_changes_panel_hides_internal_top_level_comments_from_authors_but_shows_them_to_editors(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $authorUser = $this->userWithRole('author');
        $author = Author::factory()->create();
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $author->id]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'in_review',
            'assigned_editor_id' => $editor->id,
        ]);
        app(ArticleChangeRequestService::class)->request($article, $editor, [
            'summary' => 'Проверете ги забелешките пред повторното испраќање.',
            'comments' => [
                ['body' => 'Оваа забелешка е видлива за авторот.', 'is_blocking' => true],
            ],
        ]);
        $article->changeRequests()->sole()->comments()->create([
            'article_id' => $article->id,
            'author_id' => $editor->id,
            'body' => 'Ова е доверлива забелешка само за редакцијата.',
            'visibility' => ArticleEditorialComment::VISIBILITY_INTERNAL,
            'is_blocking' => false,
            'is_resolved' => false,
        ]);

        $this->actingAs($authorUser);
        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertOk()
            ->assertSee('Оваа забелешка е видлива за авторот.')
            ->assertDontSee('Ова е доверлива забелешка само за редакцијата.');

        $this->actingAs($editor);
        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertOk()
            ->assertSee('Оваа забелешка е видлива за авторот.')
            ->assertSee('Ова е доверлива забелешка само за редакцијата.');
    }

    public function test_change_request_history_does_not_leak_internal_comment_counts_to_authors(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $authorUser = $this->userWithRole('author');
        $author = Author::factory()->create();
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $author->id]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'in_review',
            'assigned_editor_id' => $editor->id,
        ]);
        app(ArticleChangeRequestService::class)->request($article, $editor, [
            'summary' => 'Проверете ја структурата.',
            'comments' => [
                ['body' => 'Јавна забелешка.', 'is_blocking' => true],
            ],
        ]);
        $changeRequest = $article->changeRequests()->sole();
        $changeRequest->comments()->create([
            'article_id' => $article->id,
            'author_id' => $editor->id,
            'body' => 'Интерна забелешка.',
            'visibility' => ArticleEditorialComment::VISIBILITY_INTERNAL,
            'is_blocking' => false,
            'is_resolved' => false,
        ]);

        $this->actingAs($authorUser);
        Livewire::test(ChangeRequestsRelationManager::class, [
            'ownerRecord' => $article->refresh(),
            'pageClass' => EditArticle::class,
        ])->assertTableColumnStateSet('unresolved_comments_count', 1, $changeRequest);

        $this->actingAs($editor);
        Livewire::test(ChangeRequestsRelationManager::class, [
            'ownerRecord' => $article->refresh(),
            'pageClass' => EditArticle::class,
        ])->assertTableColumnStateSet('unresolved_comments_count', 2, $changeRequest);
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
