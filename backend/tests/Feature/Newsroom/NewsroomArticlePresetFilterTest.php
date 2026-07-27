<?php

namespace Tests\Feature\Newsroom;

use App\Filament\Navigation\NewsroomNavigation;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Models\Article;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class NewsroomArticlePresetFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(RazbudiseRolePermissionMatrix::class)->sync();
        Carbon::setTestNow(Carbon::parse('2026-07-21 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sidebar_presets_hydrate_filament_url_filters_and_scope_article_records(): void
    {
        $editorInChief = $this->userWithRole('editor_in_chief');
        $this->actingAs($editorInChief);

        $draft = Article::factory()->create(['title' => 'Preset Draft', 'status' => 'draft']);
        $submitted = Article::factory()->create(['title' => 'Preset Submitted', 'status' => 'submitted']);
        $review = Article::factory()->create(['title' => 'Preset Review', 'status' => 'review']);
        $inReview = Article::factory()->create(['title' => 'Preset In Review', 'status' => 'in_review']);
        $changesRequested = Article::factory()->create(['title' => 'Preset Changes Requested', 'status' => 'changes_requested']);
        $approved = Article::factory()->create(['title' => 'Preset Approved', 'status' => 'approved']);
        $scheduled = Article::factory()->create(['title' => 'Preset Scheduled', 'status' => 'scheduled']);
        $publishedToday = Article::factory()->create([
            'title' => 'Preset Published Today',
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-21 09:00:00', 'Europe/Skopje')->utc(),
        ]);
        $publishedYesterday = Article::factory()->create([
            'title' => 'Preset Published Yesterday',
            'status' => 'published',
            'published_at' => Carbon::parse('2026-07-20 09:00:00', 'Europe/Skopje')->utc(),
        ]);
        $archived = Article::factory()->create(['title' => 'Preset Archived', 'status' => 'archived']);

        $allRecords = collect([
            $draft,
            $submitted,
            $review,
            $inReview,
            $changesRequested,
            $approved,
            $scheduled,
            $publishedToday,
            $publishedYesterday,
            $archived,
        ]);

        Livewire::test(ListArticles::class)
            ->filterTable('status', 'draft')
            ->assertCanSeeTableRecords([$draft]);

        foreach ([
            ['На преглед', 'editorial_queue', 'in_review', [$review, $inReview]],
            ['Потребна доработка', 'editorial_queue', 'changes_requested', [$changesRequested]],
            ['Одобрени', 'editorial_queue', 'approved', [$approved]],
            ['Закажани', 'editorial_queue', 'scheduled', [$scheduled]],
            ['Објавени денес', 'editorial_queue', 'published_today', [$publishedToday]],
            ['Архива', 'status', 'archived', [$archived]],
        ] as [$label, $filter, $value, $expected]) {
            $query = $this->queryForNavigationItem($label);

            $this->assertArrayNotHasKey('tableFilters', $query);
            $this->assertSame($value, data_get($query, "filters.{$filter}.value"));

            $expectedIds = collect($expected)->pluck('id');
            $notExpected = $allRecords
                ->reject(fn (Article $article): bool => $expectedIds->contains($article->id))
                ->values()
                ->all();

            Livewire::withQueryParams($query)
                ->test(ListArticles::class)
                ->assertSet("tableFilters.{$filter}.value", $value)
                ->assertCanSeeTableRecords($expected)
                ->assertCanNotSeeTableRecords($notExpected);
        }

        $allArticlesQuery = $this->queryForNavigationItem('Сите објави');

        $this->assertArrayNotHasKey('tableFilters', $allArticlesQuery);
        $this->assertSame('1', data_get($allArticlesQuery, 'resetFilters'));
        $this->assertSame('', data_get($allArticlesQuery, 'filters.editorial_queue.value'));

        session()->put('tables.'.md5(ListArticles::class).'_filters', [
            'status' => ['value' => 'draft'],
        ]);

        Livewire::withQueryParams($allArticlesQuery)
            ->test(ListArticles::class)
            ->assertCanSeeTableRecords($allRecords->all());
    }

    /** @return array<string, mixed> */
    private function queryForNavigationItem(string $label): array
    {
        $item = collect(NewsroomNavigation::items())
            ->filter(fn (NavigationItem $item): bool => $item->isVisible())
            ->first(fn (NavigationItem $item): bool => $item->getLabel() === $label);

        $this->assertInstanceOf(NavigationItem::class, $item);

        parse_str((string) parse_url((string) $item->getUrl(), PHP_URL_QUERY), $query);

        return $query;
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
