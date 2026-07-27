<?php

namespace Tests\Feature;

use App\Filament\Resources\TeamRoles\TeamRoleResource;
use App\Models\Author;
use App\Models\Menu;
use App\Models\TeamMember;
use App\Models\TeamRole;
use App\Models\User;
use Database\Seeders\RazbudiseTeamDirectorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RazbudiseTeamDirectorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_positions_are_seeded_and_idempotent(): void
    {
        $this->seed(RazbudiseTeamDirectorySeeder::class);
        $firstIds = TeamRole::query()->pluck('id', 'slug')->all();

        $this->seed(RazbudiseTeamDirectorySeeder::class);

        $this->assertSame($firstIds, TeamRole::query()->pluck('id', 'slug')->all());
        $this->assertSame(9, TeamRole::query()->whereIn('slug', [
            'osnovac',
            'glaven-urednik',
            'urednik',
            'avtor',
            'novinar',
            'foto-video',
            'socijalni-mediumi',
            'prodazba-reklamiranje',
            'tehnologija-razvoj',
        ])->count());
        $this->assertSame('Позиции / Positions', TeamRoleResource::getNavigationLabel());
    }

    public function test_existing_users_are_linked_to_team_members_and_author_profiles(): void
    {
        $aleksandar = User::factory()->create(['name' => 'Founder', 'email' => 'founder@example.test']);
        $elena = User::factory()->create(['name' => 'Editor', 'email' => 'editor@example.test']);
        $jane = User::factory()->create(['name' => 'Author One', 'email' => 'author-one@example.test']);
        $romina = User::factory()->create(['name' => 'Author Two', 'email' => 'author-two@example.test']);

        $aleksandarAuthor = Author::factory()->create(['name' => 'Founder', 'slug' => 'founder', 'email' => 'founder@example.test', 'job_title' => 'CTO']);
        $elenaAuthor = Author::factory()->create(['name' => 'Editor', 'slug' => 'editor', 'email' => null, 'job_title' => 'Автор']);
        $janeAuthor = Author::factory()->create(['name' => 'Author One', 'slug' => 'author-one', 'email' => null, 'job_title' => 'Автор']);
        $rominaAuthor = Author::factory()->create(['name' => 'Author Two', 'slug' => 'author-two', 'email' => null, 'job_title' => 'Автор']);

        $this->seed(RazbudiseTeamDirectorySeeder::class);

        foreach ([
            [$aleksandar, $aleksandarAuthor, 'technology'],
            [$elena, $elenaAuthor, 'editorial'],
            [$jane, $janeAuthor, 'editorial'],
            [$romina, $rominaAuthor, 'editorial'],
        ] as [$user, $author, $departmentGroupKey]) {
            $member = TeamMember::query()->where('user_id', $user->id)->firstOrFail();

            $this->assertSame($author->id, $member->author_id);
            $this->assertSame($departmentGroupKey, $member->primaryDepartment->group_key);
            $this->assertNotEmpty($member->roles()->pluck('team_roles.slug')->all());
        }

        $this->assertSame('editor@example.test', $elenaAuthor->refresh()->email);
        $this->assertSame('author-one@example.test', $janeAuthor->refresh()->email);
        $this->assertSame('author-two@example.test', $rominaAuthor->refresh()->email);
    }

    public function test_existing_team_member_content_and_unrelated_menu_items_are_preserved(): void
    {
        $user = User::factory()->create(['name' => 'Editor', 'email' => 'editor@example.test']);
        $author = Author::factory()->create(['name' => 'Editor', 'slug' => 'editor']);
        $member = TeamMember::factory()->create([
            'name' => 'Елена Наумовска',
            'slug' => 'editor',
            'email' => 'editor@example.test',
            'user_id' => $user->id,
            'author_id' => null,
            'display_title' => 'Manual title',
            'short_bio' => 'Manual bio',
        ]);
        $existingRole = TeamRole::factory()->create(['name' => 'Existing', 'slug' => 'existing']);
        $member->roles()->attach($existingRole);
        $menu = Menu::query()->create(['name' => 'Footer', 'location' => 'footer', 'is_active' => true]);
        $authorMenu = $menu->items()->create(['title' => 'Автори', 'url' => '/avtor', 'link_type' => 'custom', 'sort_order' => 10, 'is_active' => true]);
        $otherMenu = $menu->items()->create(['title' => 'Автори', 'url' => '/something-else', 'link_type' => 'custom', 'sort_order' => 20, 'is_active' => true]);

        $this->seed(RazbudiseTeamDirectorySeeder::class);

        $member->refresh();

        $this->assertSame($author->id, $member->author_id);
        $this->assertSame('Manual title', $member->display_title);
        $this->assertSame('Manual bio', $member->short_bio);
        $this->assertContains('existing', $member->roles()->pluck('team_roles.slug')->all());
        $this->assertSame('Тим', $authorMenu->refresh()->title);
        $this->assertSame('/tim', $authorMenu->url);
        $this->assertSame('Автори', $otherMenu->refresh()->title);
    }
}
