<?php

namespace Tests\Feature;

use App\Filament\Pages\EditorialAnalytics;
use App\Models\Article;
use App\Models\ArticleView;
use App\Models\Author;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Analytics\EditorialAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorialAnalyticsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_sees_only_articles_linked_to_their_crew_profile(): void
    {
        $this->seed();
        $user = $this->userWithRole('author');
        $author = Author::factory()->create();
        $otherAuthor = Author::factory()->create();
        TeamMember::factory()->create(['user_id' => $user->id, 'author_id' => $author->id]);

        $ownArticle = Article::factory()->create(['author_id' => $author->id, 'status' => 'published', 'published_at' => now()->subDay()]);
        $otherArticle = Article::factory()->create(['author_id' => $otherAuthor->id, 'status' => 'published', 'published_at' => now()->subDay()]);
        $this->recordView($ownArticle, 'own-reader');
        $this->recordView($otherArticle, 'other-reader');

        $this->actingAs($user);
        $report = app(EditorialAnalyticsService::class)->summary($user);

        $this->assertTrue(EditorialAnalytics::canAccess());
        $this->assertSame('author', $report['scope']);
        $this->assertSame(1, $report['views_total']);
        $this->assertSame([$ownArticle->id], $report['top_articles']->pluck('id')->all());
        $this->get(EditorialAnalytics::getUrl())
            ->assertOk()
            ->assertSee('Најуспешни објави')
            ->assertSee($ownArticle->title)
            ->assertDontSee($otherArticle->title);
    }

    public function test_editor_and_admin_receive_editorial_analytics_access(): void
    {
        $this->seed();
        $editor = $this->userWithRole('editor');
        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->actingAs($editor);
        $this->assertTrue(EditorialAnalytics::canAccess());

        $this->actingAs($admin);
        $this->assertTrue(EditorialAnalytics::canAccess());
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }

    private function recordView(Article $article, string $visitor): void
    {
        ArticleView::query()->create([
            'article_id' => $article->id,
            'visitor_hash' => hash('sha256', $visitor),
            'viewed_on' => today(),
            'occurred_at' => now(),
            'device' => 'desktop',
        ]);
    }
}
