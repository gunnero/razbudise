<?php

namespace Tests\Feature;

use App\Filament\Resources\AdCampaigns\AdCampaignResource;
use App\Filament\Resources\Authors\AuthorResource;
use App\Filament\Resources\HomepageSections\HomepageSectionResource;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use App\Filament\Resources\Redirects\RedirectResource;
use App\Filament\Resources\SiteSettings\SiteSettingResource;
use App\Filament\Resources\WordpressImportRecords\WordpressImportRecordResource;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentResourcePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_resources_are_grouped_by_seeded_permissions(): void
    {
        $this->seed();

        $editor = $this->userWithRole('editor');
        $adManager = $this->userWithRole('ad_manager');
        $importManager = $this->userWithRole('import_manager');

        $this->actingAs($editor);
        $this->assertFalse(AuthorResource::canAccess());
        $this->assertTrue(MediaAssetResource::canAccess());
        $this->assertFalse(NewsletterSubscriberResource::canAccess());
        $this->assertFalse(RedirectResource::canAccess());
        $this->assertFalse(AdCampaignResource::canAccess());
        $this->assertFalse(SiteSettingResource::canAccess());

        $this->actingAs($adManager);
        $this->assertTrue(AdCampaignResource::canAccess());
        $this->assertTrue(MediaAssetResource::canAccess());
        $this->assertFalse(AuthorResource::canAccess());
        $this->assertFalse(WordpressImportRecordResource::canAccess());

        $this->actingAs($importManager);
        $this->assertTrue(WordpressImportRecordResource::canAccess());
        $this->assertFalse(AdCampaignResource::canAccess());
        $this->assertFalse(HomepageSectionResource::canAccess());
    }

    public function test_seeded_admin_can_access_every_registered_filament_resource(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->actingAs($admin);

        $resources = Filament::getPanel('admin')->getResources();

        $this->assertGreaterThan(10, count($resources));

        foreach ($resources as $resource) {
            $this->assertTrue($resource::canAccess(), "{$resource} should be visible to the seeded admin.");
        }
    }

    public function test_admin_role_user_can_access_filament_panel_outside_local_environment(): void
    {
        $this->seed();
        config(['app.env' => 'staging']);

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this
            ->actingAs($admin)
            ->get('/backoffice')
            ->assertOk();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
