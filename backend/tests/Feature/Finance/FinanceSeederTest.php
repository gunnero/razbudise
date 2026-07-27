<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceCategory;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_seed_data_is_repeatable(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(1, FinanceCategory::query()->where('slug', 'xosting')->count());
        $this->assertSame(1, FinanceCategory::query()->where('slug', 'reklamiranje')->count());
        $this->assertDatabaseHas('finance_categories', [
            'name' => 'Хостинг',
            'slug' => 'xosting',
            'type' => 'expense',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('finance_categories', [
            'name' => 'Рекламирање',
            'slug' => 'reklamiranje',
            'type' => 'income',
            'is_active' => true,
        ]);
    }

    public function test_admin_receives_finance_permissions(): void
    {
        $this->seed();

        $admin = Role::query()->where('name', 'admin')->firstOrFail();

        $this->assertTrue($admin->permissions()->where('name', 'finance.view')->exists());
        $this->assertTrue($admin->permissions()->where('name', 'finance.manage')->exists());
        $this->assertTrue($admin->permissions()->where('name', 'finance.export')->exists());
        $this->assertSame(1, Permission::query()->where('name', 'finance.manage')->count());
    }
}
