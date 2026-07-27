<?php

namespace Tests\Feature;

use App\Models\TeamDepartment;
use App\Models\TeamMember;
use Database\Seeders\RazbudiseTeamDepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RazbudiseTeamDepartmentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_departments_are_created_with_active_hierarchy(): void
    {
        $this->seed(RazbudiseTeamDepartmentSeeder::class);

        $expected = [
            'editorial' => ['Редакција', null, 10],
            'news' => ['Вести', 'editorial', 20],
            'bitola-region' => ['Битола и регион', 'editorial', 30],
            'culture-events' => ['Култура и настани', 'editorial', 40],
            'lifestyle' => ['Живот и стил', 'editorial', 50],
            'business' => ['Бизнис и економија', 'editorial', 60],
            'media-production' => ['Фото и видео продукција', null, 70],
            'audience-social' => ['Социјални медиуми и публика', null, 80],
            'sales-advertising' => ['Продажба и рекламирање', null, 90],
            'technology' => ['Технологија и развој', null, 100],
        ];

        $departments = TeamDepartment::query()
            ->whereIn('group_key', array_keys($expected))
            ->get()
            ->keyBy('group_key');

        $this->assertCount(10, $departments);

        foreach ($expected as $groupKey => [$name, $parentGroupKey, $sortOrder]) {
            $department = $departments->get($groupKey);

            $this->assertNotNull($department, "Missing {$groupKey}");
            $this->assertSame($name, $department->name);
            $this->assertSame('active', $department->status);
            $this->assertSame($sortOrder, $department->sort_order);
            $this->assertSame($groupKey, $department->slug);

            if ($parentGroupKey === null) {
                $this->assertNull($department->parent_department_id);
            } else {
                $this->assertSame($departments[$parentGroupKey]->id, $department->parent_department_id);
            }
        }
    }

    public function test_seeder_is_idempotent_and_does_not_create_duplicates(): void
    {
        $this->seed(RazbudiseTeamDepartmentSeeder::class);
        $firstIds = TeamDepartment::query()
            ->whereNotNull('group_key')
            ->pluck('id', 'group_key')
            ->all();

        $this->seed(RazbudiseTeamDepartmentSeeder::class);

        $secondIds = TeamDepartment::query()
            ->whereNotNull('group_key')
            ->pluck('id', 'group_key')
            ->all();

        $this->assertSame($firstIds, $secondIds);
        $this->assertSame(10, TeamDepartment::query()->whereIn('group_key', array_keys($firstIds))->count());
        $this->assertSame(
            [],
            TeamDepartment::query()
                ->selectRaw('group_key, COUNT(*) as aggregate')
                ->whereNotNull('group_key')
                ->groupBy('group_key')
                ->having('aggregate', '>', 1)
                ->pluck('group_key')
                ->all()
        );
    }

    public function test_existing_managers_members_and_unrelated_departments_are_preserved(): void
    {
        $manager = TeamMember::factory()->create(['name' => 'Existing Manager']);
        $existingEditorial = TeamDepartment::factory()->create([
            'name' => 'Old Editorial Name',
            'slug' => 'editorial',
            'group_key' => 'editorial',
            'status' => 'hidden',
            'sort_order' => 999,
            'manager_team_member_id' => $manager->id,
        ]);
        $member = TeamMember::factory()
            ->for($existingEditorial, 'primaryDepartment')
            ->create(['name' => 'Existing Member']);
        $unrelated = TeamDepartment::factory()->create([
            'name' => 'Unrelated Department',
            'slug' => 'unrelated-department',
            'group_key' => 'unrelated-department',
            'status' => 'hidden',
            'sort_order' => 777,
        ]);

        $this->seed(RazbudiseTeamDepartmentSeeder::class);

        $existingEditorial->refresh();
        $member->refresh();
        $unrelated->refresh();

        $this->assertSame('Редакција', $existingEditorial->name);
        $this->assertSame('active', $existingEditorial->status);
        $this->assertSame(10, $existingEditorial->sort_order);
        $this->assertSame($manager->id, $existingEditorial->manager_team_member_id);
        $this->assertSame($existingEditorial->id, $member->primary_department_id);
        $this->assertSame('Unrelated Department', $unrelated->name);
        $this->assertSame('hidden', $unrelated->status);
        $this->assertSame(777, $unrelated->sort_order);
    }

    public function test_existing_matching_slug_without_group_key_is_reused_safely(): void
    {
        $legacy = TeamDepartment::factory()->create([
            'name' => 'Legacy News',
            'slug' => 'news',
            'group_key' => null,
            'status' => 'inactive',
        ]);

        $this->seed(RazbudiseTeamDepartmentSeeder::class);

        $legacy->refresh();

        $this->assertSame('news', $legacy->group_key);
        $this->assertSame('Вести', $legacy->name);
        $this->assertSame('active', $legacy->status);
        $this->assertSame(20, $legacy->sort_order);
        $this->assertSame(
            TeamDepartment::query()->where('group_key', 'editorial')->value('id'),
            $legacy->parent_department_id
        );
    }
}
