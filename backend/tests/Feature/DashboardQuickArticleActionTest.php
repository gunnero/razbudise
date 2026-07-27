<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\Author;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardQuickArticleActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_with_linked_profile_sees_quick_article_action(): void
    {
        $this->seed();
        $author = $this->userWithRole('author');
        TeamMember::factory()->create([
            'user_id' => $author->id,
            'author_id' => Author::factory()->create()->id,
        ]);

        $this->actingAs($author);

        Livewire::test(Dashboard::class)
            ->assertActionExists('createArticle');
    }

    public function test_user_without_article_create_permission_does_not_see_quick_article_action(): void
    {
        $this->seed();
        $user = $this->userWithRole('ad_manager');

        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertActionHidden('createArticle');
    }

    public function test_unlinked_author_does_not_see_broken_quick_article_action(): void
    {
        $this->seed();
        $author = $this->userWithRole('author');

        $this->actingAs($author);

        Livewire::test(Dashboard::class)
            ->assertActionHidden('createArticle');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
