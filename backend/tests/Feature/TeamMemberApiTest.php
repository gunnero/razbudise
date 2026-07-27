<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\TeamDepartment;
use App\Models\TeamMember;
use App\Models\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamMemberApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_members_can_have_roles_and_optional_author_links(): void
    {
        $author = Author::factory()->create([
            'name' => 'Linked Author',
            'slug' => 'linked-author',
        ]);

        $member = TeamMember::factory()
            ->for($author, 'author')
            ->create([
                'name' => 'Linked Team Member',
                'slug' => 'linked-team-member',
            ]);

        $role = TeamRole::factory()->create([
            'name' => 'Editor',
            'slug' => 'editor',
            'group_key' => 'editorial',
        ]);

        $member->roles()->attach($role);

        $this->assertTrue($member->author->is($author));
        $this->assertTrue($member->roles()->firstOrFail()->is($role));
        $this->assertTrue($role->members()->firstOrFail()->is($member));
        $this->assertTrue($author->teamMembers()->firstOrFail()->is($member));
    }

    public function test_team_members_api_returns_active_members_in_public_role_hierarchy(): void
    {
        $ownerRole = TeamRole::factory()->create([
            'name' => 'Основач',
            'slug' => 'osnovac',
            'group_key' => 'owners',
            'sort_order' => 10,
        ]);
        $editorRole = TeamRole::factory()->create([
            'name' => 'Уредник',
            'slug' => 'urednik',
            'group_key' => 'editorial',
            'sort_order' => 30,
        ]);
        $authorRole = TeamRole::factory()->create([
            'name' => 'Автор',
            'slug' => 'avtor',
            'group_key' => 'editorial',
            'sort_order' => 40,
        ]);

        $author = Author::factory()->create([
            'name' => 'Elena Author',
            'slug' => 'elena-author',
        ]);

        $jane = TeamMember::factory()->create([
            'name' => 'Jane',
            'slug' => 'jane',
            'status' => 'active',
            'sort_order' => 5,
        ]);
        $jane->roles()->attach($ownerRole);

        $elena = TeamMember::factory()
            ->for($author, 'author')
            ->create([
                'name' => 'Elena',
                'slug' => 'elena',
                'display_title' => 'Co-owner / Editor / Author',
                'status' => 'active',
                'sort_order' => 10,
            ]);
        $elena->roles()->attach([$editorRole->id, $authorRole->id]);

        $romina = TeamMember::factory()->create([
            'name' => 'Romina Markovska',
            'slug' => 'romina-markovska',
            'member_type' => 'human',
            'status' => 'active',
            'sort_order' => 20,
        ]);
        $romina->roles()->attach($authorRole);

        $hidden = TeamMember::factory()->create([
            'name' => 'Hidden Member',
            'slug' => 'hidden-member',
            'status' => 'hidden',
        ]);
        $hidden->roles()->attach($ownerRole);

        $inactive = TeamMember::factory()->create([
            'name' => 'Inactive Member',
            'slug' => 'inactive-member',
            'status' => 'inactive',
        ]);
        $inactive->roles()->attach($authorRole);

        $sections = $this->getJson('/api/team-members')
            ->assertOk()
            ->json('data.sections');

        $founders = collect($sections)->firstWhere('key', 'founders');
        $editors = collect($sections)->firstWhere('key', 'editors');
        $authors = collect($sections)->firstWhere('key', 'authors');

        $this->assertSame(['jane'], collect($founders['members'])->pluck('slug')->all());
        $this->assertSame(['elena'], collect($editors['members'])->pluck('slug')->all());
        $this->assertSame('elena-author', $editors['members'][0]['author']['slug']);
        $this->assertSame('/profil/elena-author', $editors['members'][0]['author']['url']);
        $this->assertSame(['urednik', 'avtor'], collect($editors['members'][0]['roles'])->pluck('slug')->all());

        $this->assertSame(['romina-markovska'], collect($authors['members'])->pluck('slug')->all());
        $this->assertSame('Автори', $authors['title']);

        $publicSlugs = collect($sections)
            ->flatMap(fn (array $section) => collect($section['members'])->pluck('slug'))
            ->all();

        $this->assertNotContains('hidden-member', $publicSlugs);
        $this->assertNotContains('inactive-member', $publicSlugs);
    }

    public function test_team_members_api_returns_department_sections_without_a_manager(): void
    {
        $department = TeamDepartment::factory()->create([
            'name' => 'Редакција',
            'slug' => 'redakcija',
            'status' => 'active',
            'sort_order' => 10,
            'manager_team_member_id' => null,
        ]);

        TeamMember::factory()
            ->for($department, 'primaryDepartment')
            ->create([
                'name' => 'Елена Наумовски',
                'slug' => 'elena',
                'status' => 'active',
            ]);

        $sections = $this->getJson('/api/team-members')
            ->assertOk()
            ->json('data.department_sections');

        $this->assertSame('redakcija', $sections[0]['key']);
        $this->assertNull($sections[0]['manager']);
        $this->assertSame(['elena'], collect($sections[0]['members'])->pluck('slug')->all());
    }
}
