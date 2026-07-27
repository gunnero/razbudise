<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PermissionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_check_roles_and_permissions(): void
    {
        $permission = Permission::query()->create([
            'name' => 'articles.publish',
            'label' => 'Publish articles',
        ]);

        $role = Role::query()->create([
            'name' => 'editor',
            'label' => 'Editor',
        ]);
        $role->permissions()->attach($permission);

        $user = User::factory()->create();
        $user->roles()->attach($role);

        $this->assertTrue($user->hasRole('editor'));
        $this->assertTrue($user->hasPermission('articles.publish'));
        $this->assertFalse($user->hasPermission('ads.manage'));
    }

    public function test_initial_seeder_creates_repeatable_admin_roles_and_permissions(): void
    {
        $this->seed();
        $this->seed();

        $admin = Role::query()->where('name', 'admin')->firstOrFail();
        $editor = Role::query()->where('name', 'editor')->firstOrFail();
        $author = Role::query()->where('name', 'author')->firstOrFail();
        $adManager = Role::query()->where('name', 'ad_manager')->firstOrFail();
        $importManager = Role::query()->where('name', 'import_manager')->firstOrFail();

        $this->assertTrue($admin->permissions()->where('name', 'articles.publish')->exists());
        $this->assertTrue($admin->permissions()->where('name', 'ads.manage')->exists());
        $this->assertTrue($admin->permissions()->where('name', 'imports.manage')->exists());
        $this->assertTrue($admin->permissions()->where('name', 'finance.view')->exists());
        $this->assertTrue($admin->permissions()->where('name', 'finance.manage')->exists());
        $this->assertTrue($admin->permissions()->where('name', 'finance.export')->exists());
        $this->assertTrue($editor->permissions()->where('name', 'articles.publish')->exists());
        $this->assertTrue($editor->permissions()->where('name', 'analytics.view')->exists());
        $this->assertTrue($author->permissions()->where('name', 'analytics.view')->exists());
        $this->assertTrue($author->permissions()->where('name', 'articles.create')->exists());
        $this->assertTrue($author->permissions()->where('name', 'articles.update')->exists());
        $this->assertFalse($author->permissions()->where('name', 'articles.publish')->exists());
        $this->assertTrue($adManager->permissions()->where('name', 'ads.manage')->exists());
        $this->assertFalse($adManager->permissions()->where('name', 'finance.view')->exists());
        $this->assertFalse($adManager->permissions()->where('name', 'finance.manage')->exists());
        $this->assertFalse($adManager->permissions()->where('name', 'finance.export')->exists());
        $this->assertTrue($importManager->permissions()->where('name', 'imports.manage')->exists());
        $this->assertSame(1, Role::query()->where('name', 'admin')->count());
        $this->assertSame(1, Permission::query()->where('name', 'articles.publish')->count());
    }

    public function test_initial_seeder_assigns_local_admin_user_to_admin_role(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');

        $this->seed();
        $this->seed();

        $adminUser = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertSame('Local Admin', $adminUser->name);
        $this->assertTrue(Hash::check('password', $adminUser->password));
        $this->assertTrue($adminUser->hasRole('admin'));
        $this->assertTrue($adminUser->hasPermission('ads.manage'));
        $this->assertSame(1, User::query()->where('email', 'admin@example.test')->count());
    }

    public function test_initial_seeder_assigns_staging_admin_user_to_admin_role(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');

        $this->seed();
        $this->seed();

        $adminUser = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertSame('Local Admin', $adminUser->name);
        $this->assertTrue(Hash::check('password', $adminUser->password));
        $this->assertTrue($adminUser->hasRole('admin'));
        $this->assertTrue($adminUser->hasPermission('ads.manage'));
        $this->assertSame(1, User::query()->where('email', 'admin@example.test')->count());
    }
}
