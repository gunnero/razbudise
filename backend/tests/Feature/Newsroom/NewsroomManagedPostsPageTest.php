<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Pages\NewsroomManagedAuthors;
use App\Filament\Pages\NewsroomManagedPosts;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\EditorialSupervision;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NewsroomManagedPostsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(RazbudiseRolePermissionMatrix::class)->sync();
    }

    public function test_editor_managed_posts_are_paginated_without_hiding_the_total_queue(): void
    {
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create(['name' => 'Ромина Марковска']);
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $author->id,
            'is_primary' => true,
            'ends_at' => null,
        ]);

        foreach (range(1, 45) as $index) {
            Article::factory()->create([
                'author_id' => $author->id,
                'title' => sprintf('Управувана објава %02d', $index),
                'status' => $index === 45 ? 'archived' : 'published',
                'updated_at' => now()->subMinutes(45 - $index),
            ]);
        }

        $this->actingAs($editor);

        $component = Livewire::test(NewsroomManagedPosts::class)
            ->assertOk()
            ->assertSee('45 објави во избраниот преглед')
            ->assertSee('Чекаат одобрување')
            ->assertSee('Архивирани')
            ->assertSee('Управувана објава 45')
            ->assertDontSee('Управувана објава 01');

        $component
            ->call('gotoPage', 2)
            ->assertSee('45 објави во избраниот преглед')
            ->assertSee('Управувана објава 01');
    }

    public function test_author_gets_personal_title_and_only_own_posts(): void
    {
        $authorUser = $this->userWithRole('author');
        $ownAuthor = Author::factory()->create(['name' => 'Мој профил']);
        $otherAuthor = Author::factory()->create(['name' => 'Друг профил']);
        TeamMember::factory()->create([
            'user_id' => $authorUser->id,
            'author_id' => $ownAuthor->id,
        ]);

        $own = Article::factory()->create([
            'author_id' => $ownAuthor->id,
            'title' => 'Моја објава',
            'status' => 'draft',
        ]);
        $other = Article::factory()->create([
            'author_id' => $otherAuthor->id,
            'title' => 'Туѓа објава',
            'status' => 'draft',
        ]);

        $this->actingAs($authorUser);

        Livewire::test(NewsroomManagedPosts::class)
            ->assertOk()
            ->assertSee('Мои објави')
            ->assertSee($own->title)
            ->assertDontSee($other->title)
            ->assertSee('Испратени')
            ->assertDontSee('Чекаат одобрување');
    }

    public function test_all_five_author_counters_map_to_the_expected_post_filters_and_preserve_context(): void
    {
        $editor = $this->userWithRole('editor');
        $this->actingAs($editor);

        $expected = [
            'working_versions' => 'draft',
            'waiting' => 'needs_approval',
            'changes' => 'changes_requested',
            'scheduled' => 'scheduled',
            'this_month' => 'published_this_month',
        ];
        $context = [
            'q' => 'Ромина Марковска',
            'status' => 'active',
            'account' => 'active',
            'editor' => '17',
            'department' => '4',
            'redirect' => 'https://example.test',
        ];

        foreach ($expected as $metric => $status) {
            $url = NewsroomManagedPosts::authorDrilldownUrl(42, $metric, $context);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            parse_str($query['from'], $returnContext);

            $this->assertSame('42', $query['author']);
            $this->assertSame($status, $query['status']);
            $this->assertSame(collect($context)->except('redirect')->all(), $returnContext);
        }

        $directoryUrl = NewsroomManagedPosts::authorDirectoryUrl(
            'q='.urlencode('Ромина Марковска').'&status=active&redirect='.urlencode('https://example.test'),
        );
        parse_str((string) parse_url($directoryUrl, PHP_URL_QUERY), $directoryQuery);

        $this->assertSame(NewsroomManagedAuthors::getUrl(), strtok($directoryUrl, '?'));
        $this->assertSame(['q' => 'Ромина Марковска', 'status' => 'active'], $directoryQuery);
    }

    public function test_editor_cannot_leak_an_unmanaged_authors_posts_through_drilldown_parameters(): void
    {
        $editor = $this->userWithRole('editor');
        $managedAuthor = Author::factory()->create();
        $unmanagedAuthor = Author::factory()->create();
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $managedAuthor->id,
            'is_primary' => true,
            'ends_at' => null,
        ]);
        $managed = Article::factory()->create([
            'author_id' => $managedAuthor->id,
            'title' => 'Дозволена работна верзија',
            'status' => 'draft',
        ]);
        $unmanaged = Article::factory()->create([
            'author_id' => $unmanagedAuthor->id,
            'title' => 'Недозволена работна верзија',
            'status' => 'draft',
        ]);

        $this->actingAs($editor);

        Livewire::withQueryParams(['author' => $unmanagedAuthor->id, 'status' => 'draft'])
            ->test(NewsroomManagedPosts::class)
            ->assertOk()
            ->assertDontSee($managed->title)
            ->assertDontSee($unmanaged->title)
            ->assertSee('0 објави во избраниот преглед');
    }

    public function test_filtered_drilldown_shows_requested_metadata_reason_and_contextual_back_link(): void
    {
        $editor = $this->userWithRole('editor');
        $author = Author::factory()->create(['name' => 'Ромина Марковска']);
        $category = Category::factory()->create(['name' => 'Култура']);
        EditorialSupervision::factory()->create([
            'editor_id' => $editor->id,
            'author_id' => $author->id,
            'is_primary' => true,
            'ends_at' => null,
        ]);
        $article = Article::factory()->create([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'supervising_editor_id' => $editor->id,
            'title' => 'Работна верзија со детали',
            'status' => 'draft',
            'type' => 'editorial',
            'submitted_at' => now()->subHours(4),
            'scheduled_at' => now()->addDay(),
            'published_at' => now()->subHour(),
        ]);
        $context = http_build_query(['q' => 'Ромина', 'status' => 'active']);
        $backUrl = NewsroomManagedAuthors::getUrl().'?'.$context;

        $this->actingAs($editor);

        Livewire::withQueryParams([
            'author' => $author->id,
            'status' => 'draft',
            'from' => $context,
        ])->test(NewsroomManagedPosts::class)
            ->assertOk()
            ->assertSee($article->title)
            ->assertSee('Работна верзија')
            ->assertSee('Култура')
            ->assertSee('Тип: Редакциска')
            ->assertSee('Уредник: '.$editor->name)
            ->assertSee('Испратена:')
            ->assertSee('Закажана:')
            ->assertSee('Објавена:')
            ->assertSee('Нерешени коментари: 0')
            ->assertSee('Зошто не е објавена?')
            ->assertSee('Работната верзија сè уште не е испратена на преглед.')
            ->assertSeeHtml('href="'.e($backUrl).'"');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
