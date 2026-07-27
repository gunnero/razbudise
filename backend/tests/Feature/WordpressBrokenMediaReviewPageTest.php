<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\MediaAsset;
use App\Models\Role;
use App\Models\User;
use App\Models\WordpressImportRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordpressBrokenMediaReviewPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_managers_can_access_broken_media_review_page(): void
    {
        $this->seed();

        $pageClass = 'App\\Filament\\Pages\\WordpressBrokenMediaReview';
        $this->assertTrue(class_exists($pageClass), 'Broken media review page should exist.');

        $editor = $this->userWithRole('editor');
        $this->actingAs($editor);
        $this->assertFalse($pageClass::canAccess());

        $importManager = $this->userWithRole('import_manager');
        $this->actingAs($importManager);
        $this->assertTrue($pageClass::canAccess());
    }

    public function test_broken_media_review_page_filters_rows_and_replaces_selected_reference(): void
    {
        $pageClass = 'App\\Filament\\Pages\\WordpressBrokenMediaReview';
        $this->assertTrue(class_exists($pageClass), 'Broken media review page should exist.');

        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 7301,
            'slug' => 'broken-media-page-row',
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/06/broken-page.jpg"></p>',
        ]);
        $otherArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 7302,
            'slug' => 'pending-media-page-row',
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2025/12/pending-page.jpg"></p>',
        ]);
        $multiArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 7303,
            'slug' => 'multi-media-page-row',
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2024/04/multi-page-one.jpg"></p><p><img src="https://old.example.test/wp-content/uploads/2024/04/multi-page-two.jpg"></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 7301,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/broken-media-page-row/',
            'new_url' => '/broken-media-page-row',
            'status' => 'imported',
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 7302,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $otherArticle->id,
            'old_url' => 'https://old.example.test/pending-media-page-row/',
            'new_url' => '/pending-media-page-row',
            'status' => 'imported',
            'warnings' => [],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 7303,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $multiArticle->id,
            'old_url' => 'https://old.example.test/multi-media-page-row/',
            'new_url' => '/multi-media-page-row',
            'status' => 'imported',
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);
        $replacement = MediaAsset::factory()->create([
            'url' => '/storage/media/page-replacement.jpg',
        ]);

        $page = app($pageClass);
        $page->mount();

        $this->assertCount(4, $page->filteredGroupedRows());

        $page->statusFilter = 'old_link_repair_failed';
        $this->assertCount(3, $page->filteredGroupedRows());
        $this->assertSame('broken-media-page-row', $page->filteredDetailRows()[0]['slug']);

        $page->search = '7302';
        $this->assertCount(0, $page->filteredGroupedRows());

        $page->statusFilter = 'all';
        $this->assertCount(1, $page->filteredGroupedRows());
        $this->assertSame('pending-media-page-row', $page->filteredDetailRows()[0]['slug']);

        $page->search = '';
        $page->sourcePeriodFilter = '2024/04';
        $this->assertCount(2, $page->filteredGroupedRows());
        $this->assertSame('multi-media-page-row', $page->filteredDetailRows()[0]['slug']);

        $page->sourcePeriodFilter = 'all';
        $page->onlyMultipleReferences = true;
        $this->assertCount(2, $page->filteredGroupedRows());
        $this->assertSame('multi-media-page-row', $page->filteredDetailRows()[0]['slug']);

        $page->onlyMultipleReferences = false;
        $page->selectReference($article->id, 'https://old.example.test/wp-content/uploads/2026/06/broken-page.jpg');
        $this->assertSame($article->id, $page->articleId);
        $this->assertSame('https://old.example.test/wp-content/uploads/2026/06/broken-page.jpg', $page->sourceUrl);

        $page->mediaAssetId = $replacement->id;
        $this->assertSame('/storage/media/page-replacement.jpg', $page->selectedMediaPreviewUrl());
        $this->assertSame('http://localhost:3001/broken-media-page-row', $page->publicUrlForRow($page->filteredDetailRows()[0]));

        $page->articleId = $article->id;
        $page->sourceUrl = 'https://old.example.test/wp-content/uploads/2026/06/broken-page.jpg';
        $page->mediaAssetId = $replacement->id;
        $page->replaceReference();

        $this->assertStringContainsString('/storage/media/page-replacement.jpg', $article->fresh()->body_html);
        $this->assertStringNotContainsString('/wp-content/uploads/', $article->fresh()->body_html);
    }

    public function test_broken_media_review_page_removes_selected_reference(): void
    {
        $pageClass = 'App\\Filament\\Pages\\WordpressBrokenMediaReview';
        $this->assertTrue(class_exists($pageClass), 'Broken media review page should exist.');

        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 7401,
            'slug' => 'remove-media-page-row',
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/07/remove-page.jpg"></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 7401,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/remove-media-page-row/',
            'new_url' => '/remove-media-page-row',
            'status' => 'imported',
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);

        $page = app($pageClass);
        $page->mount();
        $page->selectReference($article->id, 'https://old.example.test/wp-content/uploads/2026/07/remove-page.jpg');
        $page->removeReference();

        $this->assertStringNotContainsString('/wp-content/uploads/', $article->fresh()->body_html);
        $this->assertSame([], $page->filteredDetailRows());
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
