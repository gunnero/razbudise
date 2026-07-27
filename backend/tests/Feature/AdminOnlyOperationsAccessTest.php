<?php

namespace Tests\Feature;

use App\Filament\Pages\RedirectQa;
use App\Filament\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use App\Filament\Resources\Redirects\RedirectResource;
use App\Filament\Widgets\SeoHealthOverview;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOnlyOperationsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_editors_cannot_see_or_open_seo_operations_and_newsletter_subscribers(): void
    {
        $this->seed();
        $this->actingAs($this->userWithRole('editor'));

        $this->assertFalse(RedirectResource::canAccess());
        $this->assertFalse(RedirectQa::canAccess());
        $this->assertFalse(SeoHealthOverview::canView());
        $this->assertFalse(NewsletterSubscriberResource::canAccess());

        $this->get(RedirectResource::getUrl('index'))->assertForbidden();
        $this->get(RedirectQa::getUrl())->assertForbidden();
        $this->get(NewsletterSubscriberResource::getUrl('index'))->assertForbidden();
    }

    public function test_admins_can_access_seo_operations_and_newsletter_subscribers(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->actingAs($admin);

        $this->assertTrue(RedirectResource::canAccess());
        $this->assertTrue(RedirectQa::canAccess());
        $this->assertTrue(SeoHealthOverview::canView());
        $this->assertTrue(NewsletterSubscriberResource::canAccess());
    }

    public function test_editor_receives_article_seo_but_not_newsletter_management(): void
    {
        $this->seed();
        $editor = $this->userWithRole('editor');

        $this->assertTrue($editor->hasPermission('seo.manage'));
        $this->assertFalse($editor->hasPermission('newsletter.manage'));
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
