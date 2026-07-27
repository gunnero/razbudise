<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Pages\NewsroomApprovalQueue;
use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NewsroomApprovalQueueActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_in_chief_can_assign_an_active_reviewer_from_the_approval_queue(): void
    {
        $this->seedRoles();
        $editorInChief = $this->userWithRole('editor_in_chief');
        $reviewer = $this->userWithRole('editor');
        $article = Article::factory()->create([
            'status' => 'submitted',
            'submitted_at' => now()->subHours(2),
            'assigned_editor_id' => null,
        ]);

        $this->actingAs($editorInChief);

        Livewire::test(NewsroomApprovalQueue::class)
            ->assertOk()
            ->assertSee('Дополнителни дејства')
            ->assertSee('Додели уредник')
            ->assertSee('Прегледај ревизии')
            ->assertSee('Преглед во живо')
            ->assertSee('Додај интерна белешка')
            ->assertSee('/images/authors/default-male-avatar.png', escape: false)
            ->callAction(
                TestAction::make('assignEditor')->arguments(['articleId' => $article->id]),
                ['editor_id' => $reviewer->id],
            )
            ->assertHasNoActionErrors();

        $this->assertSame($reviewer->id, $article->refresh()->assigned_editor_id);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $editorInChief->id,
            'auditable_type' => Article::class,
            'auditable_id' => $article->id,
            'action' => 'article.editor_assigned',
        ]);
    }

    public function test_editor_can_add_a_private_blocking_note_to_an_article_in_their_scope(): void
    {
        $this->seedRoles();
        $editor = $this->userWithRole('editor');
        $article = Article::factory()->create([
            'status' => 'submitted',
            'submitted_at' => now()->subHour(),
            'assigned_editor_id' => $editor->id,
        ]);

        $this->actingAs($editor);

        Livewire::test(NewsroomApprovalQueue::class)
            ->callAction(
                TestAction::make('addInternalNote')->arguments(['articleId' => $article->id]),
                [
                    'note' => 'Провери го наведениот извор пред одобрување.',
                    'is_blocking' => true,
                ],
            )
            ->assertHasNoActionErrors();

        $comment = ArticleEditorialComment::query()->sole();
        $this->assertSame($article->id, $comment->article_id);
        $this->assertSame($editor->id, $comment->author_id);
        $this->assertSame(ArticleEditorialComment::VISIBILITY_INTERNAL, $comment->visibility);
        $this->assertTrue($comment->is_blocking);
        $this->assertFalse($comment->is_resolved);

        $audit = AdminAuditLog::query()
            ->where('auditable_type', Article::class)
            ->where('auditable_id', $article->id)
            ->where('action', 'article.comment_added')
            ->sole();

        $this->assertSame('newsroom_approval_queue', data_get($audit->metadata, 'surface'));
        $this->assertSame($comment->id, data_get($audit->after, 'comment_id'));
    }

    public function test_author_cannot_access_the_approval_queue_or_internal_actions(): void
    {
        $this->seedRoles();
        $this->actingAs($this->userWithRole('author'));

        $this->assertFalse(NewsroomApprovalQueue::canAccess());
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
