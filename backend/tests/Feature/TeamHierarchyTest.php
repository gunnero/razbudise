<?php

namespace Tests\Feature;

use App\Models\TeamDepartment;
use App\Models\TeamMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_and_reporting_relationships_work(): void
    {
        $leadership = TeamDepartment::factory()->create([
            'name' => 'Leadership',
            'slug' => 'leadership',
        ]);
        $editorial = TeamDepartment::factory()
            ->for($leadership, 'parentDepartment')
            ->create([
                'name' => 'Editorial',
                'slug' => 'editorial',
            ]);

        $manager = TeamMember::factory()
            ->for($leadership, 'primaryDepartment')
            ->create([
                'name' => 'Aleksandar Dimovski',
                'slug' => 'aleksandar-dimovski',
            ]);

        $member = TeamMember::factory()
            ->for($editorial, 'primaryDepartment')
            ->for($manager, 'manager')
            ->create([
                'name' => 'Elena',
                'slug' => 'elena',
            ]);

        $editorial->update(['manager_team_member_id' => $member->id]);

        $this->assertTrue($editorial->parentDepartment->is($leadership));
        $this->assertTrue($leadership->childDepartments()->firstOrFail()->is($editorial));
        $this->assertTrue($member->primaryDepartment->is($editorial));
        $this->assertTrue($member->manager->is($manager));
        $this->assertTrue($manager->directReports()->firstOrFail()->is($member));
        $this->assertTrue($member->managedDepartments()->firstOrFail()->is($editorial));
    }

    public function test_org_chart_api_returns_active_hierarchy_and_excludes_hidden_people(): void
    {
        $leadership = TeamDepartment::factory()->create([
            'name' => 'Leadership',
            'slug' => 'leadership',
            'sort_order' => 10,
        ]);
        $editorial = TeamDepartment::factory()
            ->for($leadership, 'parentDepartment')
            ->create([
                'name' => 'Editorial',
                'slug' => 'editorial',
                'sort_order' => 20,
            ]);
        $hiddenDepartment = TeamDepartment::factory()->create([
            'name' => 'Hidden',
            'slug' => 'hidden',
            'status' => 'hidden',
        ]);

        $aleksandar = TeamMember::factory()
            ->for($leadership, 'primaryDepartment')
            ->create([
                'name' => 'Aleksandar Dimovski',
                'slug' => 'aleksandar-dimovski',
                'sort_order' => 10,
            ]);
        $elena = TeamMember::factory()
            ->for($editorial, 'primaryDepartment')
            ->for($aleksandar, 'manager')
            ->create([
                'name' => 'Elena',
                'slug' => 'elena',
                'sort_order' => 20,
            ]);
        TeamMember::factory()
            ->for($editorial, 'primaryDepartment')
            ->for($elena, 'manager')
            ->create([
                'name' => 'Hidden Member',
                'slug' => 'hidden-member',
                'status' => 'hidden',
            ]);
        TeamMember::factory()
            ->for($hiddenDepartment, 'primaryDepartment')
            ->create([
                'name' => 'Inactive Department Member',
                'slug' => 'inactive-department-member',
            ]);

        $leadership->update(['manager_team_member_id' => $aleksandar->id]);
        $editorial->update(['manager_team_member_id' => $elena->id]);

        $departments = $this->getJson('/api/team/org-chart')
            ->assertOk()
            ->json('data.departments');

        $this->assertSame(['leadership'], collect($departments)->pluck('slug')->all());
        $this->assertSame('aleksandar-dimovski', $departments[0]['manager']['slug']);
        $this->assertSame([], collect($departments[0]['members'])->pluck('slug')->all());

        $child = $departments[0]['child_departments'][0];

        $this->assertSame('editorial', $child['slug']);
        $this->assertSame('elena', $child['manager']['slug']);
        $this->assertSame([], collect($child['members'])->pluck('slug')->all());

        $allSlugs = collect($departments)
            ->flatMap(fn (array $department): array => $this->departmentSlugs($department))
            ->all();

        $this->assertNotContains('hidden', $allSlugs);
        $encodedDepartments = json_encode($departments, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('hidden-member', $encodedDepartments);
        $this->assertStringNotContainsString('inactive-department-member', $encodedDepartments);
    }

    private function departmentSlugs(array $department): array
    {
        return [
            $department['slug'],
            ...collect($department['child_departments'])
                ->flatMap(fn (array $child): array => $this->departmentSlugs($child))
                ->all(),
        ];
    }
}
