<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use App\Models\ArticleChangeRequest;
use App\Models\ArticleEditorialComment;
use App\Models\Author;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use App\Services\Newsroom\NewsroomWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AuthorRequestedChangesDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00', 'Europe/Skopje'));
        app(RazbudiseRolePermissionMatrix::class)->sync();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_author_dashboard_shows_every_requested_changes_article_with_actionable_context(): void
    {
        $authorUser = $this->userWithRole('author', ['name' => 'Автор Тест']);
        $editor = $this->userWithRole('editor', ['name' => 'Елена Наумовски']);
        $author = Author::factory()->create();
        TeamMember::factory()->create(['user_id' => $authorUser->id, 'author_id' => $author->id]);

        $nonBlockingArticle = Article::factory()->create([
            'author_id' => $author->id,
            'title' => 'Текст со неблокирачка доработка',
            'status' => 'changes_requested',
            'changes_requested_at' => Carbon::parse('2026-07-22 10:15', 'Europe/Skopje')->utc(),
            'changes_requested_by' => $editor->id,
        ]);
        $request = ArticleChangeRequest::factory()->create([
            'article_id' => $nonBlockingArticle->id,
            'requested_by' => $editor->id,
            'deadline_at' => '2026-07-25',
            'created_at' => Carbon::parse('2026-07-22 10:15', 'Europe/Skopje')->utc(),
            'updated_at' => Carbon::parse('2026-07-22 10:15', 'Europe/Skopje')->utc(),
        ]);
        ArticleEditorialComment::factory()->create([
            'article_id' => $nonBlockingArticle->id,
            'change_request_id' => $request->id,
            'author_id' => $editor->id,
            'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
            'is_blocking' => false,
            'is_resolved' => false,
        ]);
        ArticleEditorialComment::factory()->create([
            'article_id' => $nonBlockingArticle->id,
            'change_request_id' => $request->id,
            'author_id' => $editor->id,
            'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
            'is_blocking' => false,
            'is_resolved' => true,
        ]);
        ArticleEditorialComment::factory()->create([
            'article_id' => $nonBlockingArticle->id,
            'change_request_id' => $request->id,
            'author_id' => $editor->id,
            'visibility' => ArticleEditorialComment::VISIBILITY_INTERNAL,
            'is_blocking' => false,
            'is_resolved' => false,
        ]);

        $legacyArticle = Article::factory()->create([
            'author_id' => $author->id,
            'title' => 'Постар текст што бара доработка',
            'status' => 'changes_requested',
            'changes_requested_at' => Carbon::parse('2026-07-21 09:30', 'Europe/Skopje')->utc(),
            'changes_requested_by' => $editor->id,
        ]);
        ArticleEditorialComment::factory()->create([
            'article_id' => $legacyArticle->id,
            'author_id' => $editor->id,
            'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
            'is_blocking' => false,
            'is_resolved' => false,
        ]);

        $unrelatedArticle = Article::factory()->create([
            'author_id' => Author::factory()->create()->id,
            'title' => 'Туѓ текст што не смее да се прикаже',
            'status' => 'changes_requested',
            'changes_requested_at' => now(),
            'changes_requested_by' => $editor->id,
        ]);

        $workspace = app(NewsroomWorkspaceService::class);
        $requestedChanges = $workspace->requestedChanges($authorUser);

        $this->assertEqualsCanonicalizing(
            [$nonBlockingArticle->id, $legacyArticle->id],
            $requestedChanges->pluck('article.id')->all(),
        );
        $this->assertSame(1, $requestedChanges->firstWhere('article.id', $nonBlockingArticle->id)['unresolved_comments']);
        $this->assertSame(1, $requestedChanges->firstWhere('article.id', $legacyArticle->id)['unresolved_comments']);
        $this->assertFalse($workspace->urgentItems($authorUser)->contains(
            fn (array $item): bool => $item['article']?->is($nonBlockingArticle),
        ), 'A non-blocking request must still appear in its dedicated dashboard card.');

        $this->actingAs($authorUser);
        $component = Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSeeHtml('data-testid="author-requested-changes"')
            ->assertSeeHtml('data-requested-change-article="'.$nonBlockingArticle->id.'"')
            ->assertSeeHtml('data-requested-change-article="'.$legacyArticle->id.'"')
            ->assertSeeHtml('data-unresolved-comments="1"')
            ->assertSee('Текст со неблокирачка доработка')
            ->assertSee('Постар текст што бара доработка')
            ->assertSee('Елена Наумовски')
            ->assertSee('22.07.2026')
            ->assertSee('25.07.2026')
            ->assertSee('Нерешени забелешки')
            ->assertSee('Отвори и поправи')
            ->assertDontSee($unrelatedArticle->title);

        $component->assertSeeHtml('href="'.e(ArticleResource::getUrl('edit', ['record' => $nonBlockingArticle])).'"');
        $this->assertSame(2, substr_count($component->html(), 'Отвори и поправи'));
    }

    public function test_requested_changes_cards_are_author_only_and_hidden_without_matching_work(): void
    {
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create();
        Article::factory()->create([
            'author_id' => $author->id,
            'status' => 'changes_requested',
            'assigned_editor_id' => $editor->id,
        ]);

        $this->assertTrue(app(NewsroomWorkspaceService::class)->requestedChanges($editor)->isEmpty());

        $this->actingAs($editor);
        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertDontSeeHtml('data-testid="author-requested-changes"')
            ->assertDontSee('Отвори и поправи');
    }

    /** @param array<string, mixed> $attributes */
    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['status' => 'active'], $attributes));
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
