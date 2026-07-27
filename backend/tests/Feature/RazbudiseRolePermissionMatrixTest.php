<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use Database\Seeders\RazbudiseRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RazbudiseRolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permission_sync_is_idempotent_and_creates_required_roles(): void
    {
        $this->seed(RazbudiseRolePermissionSeeder::class);
        $firstRoleIds = Role::query()->pluck('id', 'name')->all();
        $firstPermissionIds = Permission::query()->pluck('id', 'name')->all();

        $this->seed(RazbudiseRolePermissionSeeder::class);

        $this->assertSame($firstRoleIds, Role::query()->pluck('id', 'name')->all());
        $this->assertSame($firstPermissionIds, Permission::query()->pluck('id', 'name')->all());

        foreach (['admin', 'editor_in_chief', 'editor', 'author', 'ad_manager', 'accountant'] as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role]);
        }
    }

    public function test_administrator_bypasses_registered_permissions_only_when_active(): void
    {
        $this->seed(RazbudiseRolePermissionSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $inactiveAdmin = User::factory()->create(['status' => 'suspended']);
        $inactiveAdmin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $this->assertTrue($admin->hasPermission('future.module.manage'));
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($inactiveAdmin->hasPermission('articles.view'));
        $this->assertFalse($inactiveAdmin->isAdmin());
    }

    public function test_editor_in_chief_and_editor_have_identical_editorial_permissions(): void
    {
        $this->seed(RazbudiseRolePermissionSeeder::class);

        $editor = Role::query()->where('name', 'editor')->firstOrFail();
        $editorInChief = Role::query()->where('name', 'editor_in_chief')->firstOrFail();
        $editorialPermissions = collect(app(RazbudiseRolePermissionMatrix::class)->editorialPermissionBundle())->sort()->values()->all();

        $this->assertSame(
            $editorialPermissions,
            $editor->permissions()->whereIn('permissions.name', $editorialPermissions)->pluck('permissions.name')->sort()->values()->all(),
        );
        $this->assertSame(
            $editorialPermissions,
            $editorInChief->permissions()->whereIn('permissions.name', $editorialPermissions)->pluck('permissions.name')->sort()->values()->all(),
        );
        $this->assertFalse($editor->permissions()->where('permissions.name', 'editorial.users.manage')->exists());
        $this->assertTrue($editorInChief->permissions()->where('permissions.name', 'editorial.users.manage')->exists());
    }

    public function test_separation_between_editorial_advertising_and_accounting_roles(): void
    {
        $this->seed(RazbudiseRolePermissionSeeder::class);

        $author = $this->userWithRole('author');
        $adManager = $this->userWithRole('ad_manager');
        $accountant = $this->userWithRole('accountant');

        $this->assertFalse($author->hasPermission('articles.publish'));
        $this->assertFalse($author->hasPermission('ads.manage'));
        $this->assertFalse($adManager->hasPermission('articles.publish'));
        $this->assertTrue($adManager->hasPermission('ads.manage'));
        $this->assertTrue($accountant->hasPermission('finance.manage'));
        $this->assertFalse($accountant->hasPermission('articles.view'));
        $this->assertFalse($accountant->hasPermission('ads.manage'));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
