<?php

namespace Database\Seeders;

use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\Author;
use App\Models\Category;
use App\Models\EditorialSupervision;
use App\Models\MediaAsset;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

class NewsroomBrowserTestSeeder extends Seeder
{
    public const PASSWORD = 'Newsroom-Testing-Only-2026!';

    public const EIC_EMAIL = 'newsroom.eic@example.test';

    public const EDITOR_EMAIL = 'newsroom.editor@example.test';

    public const AUTHOR_EMAIL = 'newsroom.author@example.test';

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('NewsroomBrowserTestSeeder may only run when APP_ENV=testing.');
        }

        DB::transaction(function (): void {
            app(RazbudiseRolePermissionMatrix::class)->sync();

            $editorInChief = $this->upsertUser(
                self::EIC_EMAIL,
                'Elena Newsroom Test',
                'editor_in_chief',
            );
            $editor = $this->upsertUser(
                self::EDITOR_EMAIL,
                'Editor Newsroom Test',
                'editor',
            );
            $authorUser = $this->upsertUser(
                self::AUTHOR_EMAIL,
                'Romina Newsroom Test',
                'author',
            );

            $avatar = MediaAsset::query()->updateOrCreate(
                ['disk' => 'external', 'path' => 'newsroom-browser-test/romina-avatar.png'],
                [
                    'url' => '/images/authors/default-female-avatar.png',
                    'mime_type' => 'image/png',
                    'alt_text' => 'Romina Newsroom Test',
                    'caption' => 'Testing-only newsroom fixture avatar.',
                    'width' => 512,
                    'height' => 512,
                    'size_bytes' => 1,
                    'uploaded_by' => $editorInChief->id,
                    'metadata' => ['source' => 'newsroom_browser_test_fixture'],
                ],
            );

            $featuredImage = MediaAsset::query()->updateOrCreate(
                ['disk' => 'external', 'path' => 'newsroom-browser-test/featured.png'],
                [
                    'url' => '/brand/razbudise-logo.png',
                    'mime_type' => 'image/png',
                    'alt_text' => 'RazbudiSe newsroom testing illustration',
                    'caption' => 'Testing-only newsroom fixture image.',
                    'width' => 1200,
                    'height' => 675,
                    'size_bytes' => 1,
                    'uploaded_by' => $editorInChief->id,
                    'metadata' => ['source' => 'newsroom_browser_test_fixture'],
                ],
            );

            $romina = Author::query()->updateOrCreate(
                ['slug' => 'romina-newsroom-test'],
                [
                    'name' => 'Romina Markovska',
                    'avatar_id' => $avatar->id,
                    'sex' => 'female',
                    'short_bio' => 'Testing-only author for authenticated newsroom browser coverage.',
                    'full_bio' => 'Testing-only author for deterministic editorial workflow browser coverage.',
                    'job_title' => 'Автор',
                    'email' => self::AUTHOR_EMAIL,
                    'show_email' => false,
                    'is_active' => true,
                    'sort_order' => 9001,
                    'seo_title' => 'Romina Newsroom Test',
                    'meta_description' => 'Testing-only newsroom author fixture.',
                ],
            );

            $managedByEditor = Author::query()->updateOrCreate(
                ['slug' => 'editor-managed-newsroom-test'],
                [
                    'name' => 'Editor Managed Author',
                    'sex' => 'female',
                    'short_bio' => 'Testing-only managed author.',
                    'full_bio' => 'Testing-only managed author for editor scope coverage.',
                    'job_title' => 'Соработник',
                    'show_email' => false,
                    'is_active' => true,
                    'sort_order' => 9002,
                ],
            );

            $editorInChiefMember = $this->upsertTeamMember(
                slug: 'elena-newsroom-test',
                name: 'Elena Newsroom Test',
                title: 'Главен и одговорен уредник',
                user: $editorInChief,
            );
            $editorMember = $this->upsertTeamMember(
                slug: 'editor-newsroom-test',
                name: 'Editor Newsroom Test',
                title: 'Уредник',
                user: $editor,
                manager: $editorInChiefMember,
            );
            $this->upsertTeamMember(
                slug: 'romina-newsroom-test',
                name: 'Romina Markovska',
                title: 'Автор',
                user: $authorUser,
                author: $romina,
                manager: $editorInChiefMember,
                profileImage: $avatar,
            );
            $this->upsertTeamMember(
                slug: 'editor-managed-author-newsroom-test',
                name: 'Editor Managed Author',
                title: 'Соработник',
                author: $managedByEditor,
                manager: $editorMember,
                profileImage: $avatar,
            );

            EditorialSupervision::query()->updateOrCreate(
                ['editor_id' => $editorInChief->id, 'author_id' => $romina->id],
                [
                    'is_primary' => true,
                    'starts_at' => now()->subMonth(),
                    'ends_at' => null,
                    'created_by' => $editorInChief->id,
                ],
            );
            EditorialSupervision::query()->updateOrCreate(
                ['editor_id' => $editor->id, 'author_id' => $managedByEditor->id],
                [
                    'is_primary' => true,
                    'starts_at' => now()->subMonth(),
                    'ends_at' => null,
                    'created_by' => $editorInChief->id,
                ],
            );

            $category = Category::query()->updateOrCreate(
                ['slug' => 'newsroom-browser-test'],
                [
                    'name' => 'Newsroom Browser Test',
                    'description' => 'Testing-only category for authenticated newsroom browser coverage.',
                    'is_active' => true,
                    'sort_order' => 9900,
                ],
            );

            $articles = $this->seedArticles(
                $romina,
                $managedByEditor,
                $category,
                $featuredImage,
                $editorInChief,
                $editor,
                $authorUser,
            );

            ArticleEditorialComment::query()->updateOrCreate(
                [
                    'article_id' => $articles['in_review']->id,
                    'body' => 'Newsroom browser fixture blocking comment.',
                ],
                [
                    'author_id' => $editorInChief->id,
                    'parent_id' => null,
                    'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
                    'is_blocking' => true,
                    'is_resolved' => false,
                    'resolved_by' => null,
                    'resolved_at' => null,
                ],
            );
            ArticleEditorialComment::query()->updateOrCreate(
                [
                    'article_id' => $articles['changes_requested']->id,
                    'body' => 'Newsroom browser fixture author-visible feedback.',
                ],
                [
                    'author_id' => $editorInChief->id,
                    'parent_id' => null,
                    'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
                    'is_blocking' => false,
                    'is_resolved' => false,
                    'resolved_by' => null,
                    'resolved_at' => null,
                ],
            );
            ArticleEditorialComment::query()->updateOrCreate(
                [
                    'article_id' => $articles['submitted']->id,
                    'body' => 'Newsroom browser fixture internal note.',
                ],
                [
                    'author_id' => $editorInChief->id,
                    'parent_id' => null,
                    'visibility' => ArticleEditorialComment::VISIBILITY_INTERNAL,
                    'is_blocking' => false,
                    'is_resolved' => false,
                    'resolved_by' => null,
                    'resolved_at' => null,
                ],
            );

            $this->seedActivity($articles, $editorInChief, $editor, $authorUser);
        });
    }

    private function upsertUser(string $email, string $name, string $roleName): User
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'locale' => 'mk',
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function upsertTeamMember(
        string $slug,
        string $name,
        string $title,
        ?User $user = null,
        ?Author $author = null,
        ?TeamMember $manager = null,
        ?MediaAsset $profileImage = null,
    ): TeamMember {
        return TeamMember::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'display_title' => $title,
                'short_bio' => 'Testing-only newsroom browser fixture.',
                'full_bio' => 'Testing-only newsroom browser fixture.',
                'profile_image_id' => $profileImage?->id,
                'sex' => 'female',
                'email' => $user?->email,
                'member_type' => 'human',
                'status' => 'active',
                'sort_order' => 9000,
                'joined_at' => now()->subYear()->toDateString(),
                'social_links' => [],
                'metadata' => ['source' => 'newsroom_browser_test_fixture'],
                'user_id' => $user?->id,
                'author_id' => $author?->id,
                'manager_team_member_id' => $manager?->id,
            ],
        );
    }

    /**
     * @return array<string, Article>
     */
    private function seedArticles(
        Author $romina,
        Author $managedByEditor,
        Category $category,
        MediaAsset $featuredImage,
        User $editorInChief,
        User $editor,
        User $authorUser,
    ): array {
        $base = [
            'excerpt' => 'Краток опис за детерминистичка проверка на редакцискиот работен простор.',
            'body_html' => '<p>Ова е детерминистичка тест содржина за редакцискиот работен простор. Текстот содржи доволно зборови за проверка на метриките за читање и преглед.</p>',
            'type' => 'editorial',
            'author_id' => $romina->id,
            'category_id' => $category->id,
            'featured_image_id' => $featuredImage->id,
            'is_sponsored' => false,
            'is_ai_generated' => false,
            'is_ai_assisted' => false,
            'created_by' => $authorUser->id,
            'updated_by' => $authorUser->id,
            'published_at' => null,
            'scheduled_at' => null,
            'supervising_editor_id' => $editorInChief->id,
            'assigned_editor_id' => $editorInChief->id,
            'submitted_at' => null,
            'submitted_by' => null,
            'review_started_at' => null,
            'reviewed_by' => null,
            'changes_requested_at' => null,
            'changes_requested_by' => null,
            'approved_at' => null,
            'approved_by' => null,
            'archived_at' => null,
            'archived_by' => null,
            'publication_failed_at' => null,
            'publication_failure_reason' => null,
            'last_editorial_activity_at' => now()->subHour(),
        ];

        $newsroomNow = now(config('newsroom.timezone', 'Europe/Skopje'));

        $definitions = [
            'draft' => [
                'slug' => 'newsroom-test-draft',
                'title' => 'Newsroom Test Draft',
                'status' => 'draft',
            ],
            'submitted' => [
                'slug' => 'newsroom-test-submitted',
                'title' => 'Newsroom Test Waiting Approval',
                'status' => 'submitted',
                'submitted_at' => now()->subHours(30),
                'submitted_by' => $authorUser->id,
                'last_editorial_activity_at' => now()->subHours(30),
            ],
            'in_review' => [
                'slug' => 'newsroom-test-in-review',
                'title' => 'Newsroom Test In Review',
                'status' => 'in_review',
                'submitted_at' => now()->subHours(8),
                'submitted_by' => $authorUser->id,
                'review_started_at' => now()->subHours(6),
                'reviewed_by' => $editorInChief->id,
                'last_editorial_activity_at' => now()->subHours(6),
            ],
            'changes_requested' => [
                'slug' => 'newsroom-test-changes-requested',
                'title' => 'Newsroom Test Changes Requested',
                'status' => 'changes_requested',
                'submitted_at' => now()->subDay(),
                'submitted_by' => $authorUser->id,
                'review_started_at' => now()->subHours(20),
                'reviewed_by' => $editorInChief->id,
                'changes_requested_at' => now()->subHours(18),
                'changes_requested_by' => $editorInChief->id,
                'last_editorial_activity_at' => now()->subHours(18),
            ],
            'approved' => [
                'slug' => 'newsroom-test-approved',
                'title' => 'Newsroom Test Approved Unscheduled',
                'status' => 'approved',
                'submitted_at' => now()->subHours(10),
                'submitted_by' => $authorUser->id,
                'review_started_at' => now()->subHours(9),
                'reviewed_by' => $editorInChief->id,
                'approved_at' => now()->subHours(2),
                'approved_by' => $editorInChief->id,
                'last_editorial_activity_at' => now()->subHours(2),
            ],
            'scheduled' => [
                'slug' => 'newsroom-test-scheduled',
                'title' => 'Newsroom Test Scheduled Today',
                'status' => 'scheduled',
                'submitted_at' => now()->subDay(),
                'submitted_by' => $authorUser->id,
                'review_started_at' => now()->subHours(20),
                'reviewed_by' => $editorInChief->id,
                'approved_at' => now()->subHours(12),
                'approved_by' => $editorInChief->id,
                'scheduled_at' => $newsroomNow->copy()->startOfDay()->addHours(21)->utc(),
                'last_editorial_activity_at' => now()->subHours(12),
            ],
            'published' => [
                'slug' => 'newsroom-test-published',
                'title' => 'Newsroom Test Published Today',
                'status' => 'published',
                'submitted_at' => now()->subDay(),
                'submitted_by' => $authorUser->id,
                'review_started_at' => now()->subHours(22),
                'reviewed_by' => $editorInChief->id,
                'approved_at' => now()->subHours(5),
                'approved_by' => $editorInChief->id,
                'published_at' => $newsroomNow->copy()->startOfDay()->addMinute()->utc(),
                'published_by' => $editorInChief->id,
                'last_editorial_activity_at' => now()->subHour(),
            ],
            'failed' => [
                'slug' => 'newsroom-test-failed',
                'title' => 'Newsroom Test Failed Publication',
                'status' => 'failed',
                'submitted_at' => now()->subDays(2),
                'submitted_by' => $authorUser->id,
                'publication_failed_at' => now()->subMinutes(45),
                'publication_failure_reason' => 'Testing-only scheduled publication failure.',
                'last_editorial_activity_at' => now()->subMinutes(45),
            ],
            'archived' => [
                'slug' => 'newsroom-test-archived',
                'title' => 'Newsroom Test Archived',
                'status' => 'archived',
                'archived_at' => now()->subWeek(),
                'archived_by' => $editorInChief->id,
                'last_editorial_activity_at' => now()->subWeek(),
            ],
            'long_title' => [
                'slug' => 'newsroom-test-long-editorial-title',
                'title' => 'Долг редакциски наслов што проверува читливо прикажување во два реда без кршење на зборовите',
                'status' => 'published',
                'published_at' => now()->subHours(2),
                'published_by' => $editorInChief->id,
                'last_editorial_activity_at' => now()->subHours(2),
            ],
            'editor_managed' => [
                'slug' => 'newsroom-test-editor-managed',
                'title' => 'Newsroom Test Editor Managed Submission',
                'status' => 'submitted',
                'author_id' => $managedByEditor->id,
                'created_by' => $editor->id,
                'updated_by' => $editor->id,
                'supervising_editor_id' => $editor->id,
                'assigned_editor_id' => $editor->id,
                'submitted_at' => now()->subHours(3),
                'submitted_by' => $editor->id,
                'last_editorial_activity_at' => now()->subHours(3),
            ],
        ];

        $articles = [];

        foreach ($definitions as $key => $definition) {
            $slug = $definition['slug'];
            unset($definition['slug']);

            $article = Article::query()->updateOrCreate(
                ['slug' => $slug],
                array_merge($base, $definition),
            );

            if ($key !== 'draft') {
                $article->seoMetadata()->updateOrCreate([], [
                    'seo_title' => $article->title,
                    'meta_description' => $article->excerpt,
                    'canonical_url' => '/'.$article->slug,
                    'og_title' => $article->title,
                    'og_description' => $article->excerpt,
                    'og_image_id' => $featuredImage->id,
                ]);
            } else {
                $article->seoMetadata()->delete();
            }

            $articles[$key] = $article;
        }

        return $articles;
    }

    /**
     * @param  array<string, Article>  $articles
     */
    private function seedActivity(array $articles, User $editorInChief, User $editor, User $author): void
    {
        $events = [
            ['article' => 'submitted', 'actor' => $author, 'action' => 'article.submitted', 'age' => 30],
            ['article' => 'in_review', 'actor' => $editorInChief, 'action' => 'article.review_started', 'age' => 6],
            ['article' => 'changes_requested', 'actor' => $editorInChief, 'action' => 'article.changes_requested', 'age' => 5],
            ['article' => 'approved', 'actor' => $editorInChief, 'action' => 'article.approved', 'age' => 2],
            ['article' => 'scheduled', 'actor' => $editorInChief, 'action' => 'article.scheduled', 'age' => 1],
            ['article' => 'published', 'actor' => $editorInChief, 'action' => 'article.published', 'age' => 0],
            ['article' => 'failed', 'actor' => $editor, 'action' => 'article.publication_failed', 'age' => 0],
        ];

        foreach ($events as $event) {
            $article = $articles[$event['article']];
            $log = AdminAuditLog::query()->updateOrCreate(
                [
                    'auditable_type' => Article::class,
                    'auditable_id' => $article->id,
                    'action' => $event['action'],
                ],
                [
                    'actor_id' => $event['actor']->id,
                    'before' => [],
                    'after' => ['status' => $article->status],
                    'metadata' => ['surface' => 'newsroom_browser_test_fixture'],
                ],
            );
            $log->forceFill([
                'created_at' => now()->subHours($event['age']),
                'updated_at' => now()->subHours($event['age']),
            ])->saveQuietly();
        }
    }
}
