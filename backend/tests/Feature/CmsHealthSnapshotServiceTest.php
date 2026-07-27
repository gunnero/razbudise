<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use App\Models\WordpressImportRecord;
use App\Services\Admin\CmsHealthSnapshotService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CmsHealthSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_admin_can_see_every_filament_resource_in_cms_health_snapshot(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->actingAs($admin);

        $seededPublishedArticles = Article::query()->published()->count();
        Article::factory()->published()->count(2)->create();
        WordpressImportRecord::query()->create([
            'wordpress_id' => 1001,
            'wordpress_type' => 'post',
            'status' => 'imported',
            'new_url' => '/imported-post',
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 1002,
            'wordpress_type' => 'post',
            'status' => 'failed',
            'error_message' => 'Example failed import',
        ]);

        $snapshot = app(CmsHealthSnapshotService::class)->snapshot();
        $registeredResources = count(Filament::getPanel('admin')->getResources());

        $this->assertGreaterThan(10, $registeredResources);
        $this->assertSame($registeredResources, $snapshot['navigation']['registered_resources']);
        $this->assertSame($registeredResources, $snapshot['navigation']['accessible_resources']);
        $this->assertSame($seededPublishedArticles + 2, $snapshot['content']['published_articles']);
        $this->assertSame(2, $snapshot['migration']['post_records']);
        $this->assertSame(1, $snapshot['migration']['failed_imports']);
        $this->assertSame(1, $snapshot['admin']['admin_users']);
    }
}
