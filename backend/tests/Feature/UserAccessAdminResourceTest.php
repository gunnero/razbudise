<?php

namespace Tests\Feature;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccessAdminResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_user_and_role_management(): void
    {
        $admin = User::factory()->create();
        $adminRole = Role::query()->create(['name' => 'admin', 'label' => 'Administrator']);
        $admin->roles()->attach($adminRole);

        $this->actingAs($admin);

        $this->assertTrue(UserResource::canAccess());
        $this->assertTrue(RoleResource::canAccess());
        $this->get(UserResource::getUrl('index'))->assertOk();
        $this->get(UserResource::getUrl('create'))->assertOk();
        $this->get(UserResource::getUrl('edit', ['record' => $admin]))->assertOk();
        $this->get(RoleResource::getUrl('index'))->assertOk();
        $this->get(RoleResource::getUrl('edit', ['record' => $adminRole]))->assertOk();
    }

    public function test_editor_cannot_access_user_or_role_management(): void
    {
        $editor = User::factory()->create();
        $permission = Permission::query()->create(['name' => 'articles.update', 'label' => 'Update articles']);
        $editorRole = Role::query()->create(['name' => 'editor', 'label' => 'Editor']);
        $editorRole->permissions()->attach($permission);
        $editor->roles()->attach($editorRole);

        $this->actingAs($editor);

        $this->assertFalse(UserResource::canAccess());
        $this->assertFalse(RoleResource::canAccess());
        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(RoleResource::getUrl('index'))->assertForbidden();
    }

    public function test_users_manage_permission_grants_access_without_admin_role(): void
    {
        $manager = User::factory()->create();
        $permission = Permission::query()->create(['name' => 'users.manage', 'label' => 'Manage users']);
        $role = Role::query()->create(['name' => 'access_manager', 'label' => 'Access manager']);
        $role->permissions()->attach($permission);
        $manager->roles()->attach($role);

        $this->actingAs($manager);

        $this->assertTrue(UserResource::canAccess());
        $this->assertTrue(RoleResource::canAccess());
    }
}
