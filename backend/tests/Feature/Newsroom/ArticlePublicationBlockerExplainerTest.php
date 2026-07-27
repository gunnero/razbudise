<?php

namespace Tests\Feature\Newsroom;

use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\Author;
use App\Models\User;
use App\Services\Articles\ArticlePublicationBlockerExplainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticlePublicationBlockerExplainerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_explains_each_supported_unpublished_state_and_ignores_live_articles(): void
    {
        $explainer = app(ArticlePublicationBlockerExplainer::class);

        $workingVersion = Article::factory()->create(['status' => 'draft']);
        $missingRequired = Article::factory()->create(['status' => 'draft', 'title' => '']);
        $changesRequested = Article::factory()->create(['status' => 'changes_requested']);
        $waitingForEditor = Article::factory()->create(['status' => 'submitted']);
        $notApproved = Article::factory()->create(['status' => 'rejected']);
        $scheduled = Article::factory()->create([
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);
        $failed = Article::factory()->create([
            'status' => 'failed',
            'publication_failed_at' => now(),
        ]);
        $disabledAuthor = Author::factory()->create(['is_active' => false]);
        $disabled = Article::factory()->create([
            'author_id' => $disabledAuthor->id,
            'status' => 'submitted',
        ]);
        $blocked = Article::factory()->create(['status' => 'submitted']);
        ArticleEditorialComment::factory()->create([
            'article_id' => $blocked->id,
            'author_id' => User::factory(),
            'is_blocking' => true,
            'is_resolved' => false,
        ]);
        $published = Article::factory()->published()->create();

        $this->assertStringContainsString('сè уште не е испратена', (string) $explainer->explain($workingVersion));
        $this->assertStringContainsString('Недостигаат задолжителни податоци', (string) $explainer->explain($missingRequired));
        $this->assertStringContainsString('Потребна е доработка', (string) $explainer->explain($changesRequested));
        $this->assertStringContainsString('Чека преглед', (string) $explainer->explain($waitingForEditor));
        $this->assertStringContainsString('сè уште не е одобрена', (string) $explainer->explain($notApproved));
        $this->assertStringContainsString('Закажана е за идно објавување', (string) $explainer->explain($scheduled));
        $this->assertStringContainsString('обид за објавување не успеал', (string) $explainer->explain($failed));
        $this->assertStringContainsString('Авторскиот профил е оневозможен', (string) $explainer->explain($disabled));
        $this->assertStringContainsString('нерешени блокирачки коментари', (string) $explainer->explain($blocked));
        $this->assertNull($explainer->explain($published));
    }
}
