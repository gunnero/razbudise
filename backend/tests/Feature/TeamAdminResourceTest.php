<?php

namespace Tests\Feature;

use App\Filament\Resources\Authors\AuthorResource;
use App\Filament\Resources\TeamDepartments\TeamDepartmentResource;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\TeamRoles\TeamRoleResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAdminResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_and_legacy_author_resources_are_admin_only(): void
    {
        $this->seed();

        $editor = $this->userWithRole('editor');

        $this->actingAs($editor);
        $this->assertFalse(AuthorResource::canAccess());
        $this->assertFalse(TeamDepartmentResource::canAccess());
        $this->assertFalse(TeamMemberResource::canAccess());
        $this->assertFalse(TeamRoleResource::canAccess());

        $this->get(AuthorResource::getUrl('index'))->assertForbidden();
        $this->get(TeamDepartmentResource::getUrl('index'))->assertForbidden();
        $this->get(TeamMemberResource::getUrl('index'))->assertForbidden();
        $this->get(TeamRoleResource::getUrl('index'))->assertForbidden();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->actingAs($admin);
        $this->assertTrue(AuthorResource::canAccess());
        $this->assertTrue(TeamDepartmentResource::canAccess());
        $this->assertTrue(TeamMemberResource::canAccess());
        $this->assertTrue(TeamRoleResource::canAccess());
    }

    public function test_team_navigation_uses_crew_language_and_hides_legacy_authors(): void
    {
        $this->assertFalse(AuthorResource::shouldRegisterNavigation());
        $this->assertSame('Тим / Crew', TeamMemberResource::getNavigationGroup());
        $this->assertSame('Луѓе / People', TeamMemberResource::getNavigationLabel());
        $this->assertSame('Оддели / Departments', TeamDepartmentResource::getNavigationLabel());
        $this->assertSame('Позиции / Positions', TeamRoleResource::getNavigationLabel());
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
