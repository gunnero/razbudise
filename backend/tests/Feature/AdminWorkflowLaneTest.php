<?php

namespace Tests\Feature;

use App\Filament\Resources\AdCampaigns\AdCampaignResource;
use App\Filament\Resources\AdCreatives\AdCreativeResource;
use App\Filament\Resources\AdZones\Pages\EditAdZone;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Authors\AuthorResource;
use App\Filament\Resources\Authors\Pages\CreateAuthor;
use App\Filament\Resources\Authors\Pages\ListAuthors;
use App\Filament\Resources\Authors\Pages\ViewAuthor;
use App\Filament\Resources\HomepageSections\HomepageSectionResource;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Resources\Redirects\RedirectResource;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\WordpressImportRecords\Pages\ListWordpressImportRecords;
use App\Filament\Resources\WordpressImportRecords\WordpressImportRecordResource;
use App\Filament\Tables\MediaAssetGalleryTable;
use App\Filament\Widgets\AdPerformanceOverview;
use App\Filament\Widgets\CmsHealthOverview;
use App\Filament\Widgets\EditorialWorkflowOverview;
use App\Filament\Widgets\MediaQualityOverview;
use App\Filament\Widgets\PublishingStatsOverview;
use App\Filament\Widgets\SeoHealthOverview;
use App\Filament\Widgets\WordpressImportOverview;
use App\Models\AdZone;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Role;
use App\Models\User;
use App\Models\WordpressImportRecord;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

class AdminWorkflowLaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_navigation_is_grouped_by_editorial_workflows(): void
    {
        $this->assertSame('Content', ArticleResource::getNavigationGroup());
        $this->assertSame('Content', AuthorResource::getNavigationGroup());
        $this->assertSame('Media Library', MediaAssetResource::getNavigationGroup());
        $this->assertSame('Advertising', AdCampaignResource::getNavigationGroup());
        $this->assertSame('Advertising', AdCreativeResource::getNavigationGroup());
        $this->assertSame('Migration', WordpressImportRecordResource::getNavigationGroup());
        $this->assertSame('Site', HomepageSectionResource::getNavigationGroup());
        $this->assertSame('SEO & Operations', RedirectResource::getNavigationGroup());

        $this->assertNotSame(Heroicon::OutlinedRectangleStack, ArticleResource::getNavigationIcon());
        $this->assertNotSame(Heroicon::OutlinedRectangleStack, AuthorResource::getNavigationIcon());
        $this->assertNotSame(Heroicon::OutlinedRectangleStack, AdCampaignResource::getNavigationIcon());
    }

    public function test_editor_dashboard_shows_only_actionable_editorial_overview(): void
    {
        $this->seed();
        $this->actingAs($this->userWithRole('editor'));

        Article::factory()->create(['status' => 'draft']);
        Article::factory()->create(['status' => 'review']);
        Article::factory()->create(['status' => 'published', 'published_at' => now()]);

        $this->assertTrue(EditorialWorkflowOverview::canView());
        $this->assertFalse(CmsHealthOverview::canView());
        $this->assertFalse(PublishingStatsOverview::canView());
        $this->assertFalse(AdPerformanceOverview::canView());
        $this->assertFalse(MediaQualityOverview::canView());
        $this->assertFalse(SeoHealthOverview::canView());
        $this->assertFalse(WordpressImportOverview::canView());

        $stats = $this->statsFrom(EditorialWorkflowOverview::class);

        $this->assertCount(4, $stats);
        $this->assertSame([
            'New article',
            'Working versions',
            'Waiting for review',
            'Published',
        ], collect($stats)->map(fn (Stat $stat): string => $stat->getLabel())->all());
    }

    public function test_article_list_supports_editorial_filters_and_status_actions(): void
    {
        $this->seed();

        $admin = $this->seededAdmin();
        $this->actingAs($admin);

        $primaryAuthor = Author::factory()->create(['name' => 'Primary Author']);
        $otherAuthor = Author::factory()->create(['name' => 'Other Author']);
        $culture = Category::factory()->create(['name' => 'Culture']);
        $events = Category::factory()->create(['name' => 'Events']);

        $draft = Article::factory()->create([
            'title' => 'Draft editorial',
            'author_id' => $primaryAuthor->id,
            'category_id' => $culture->id,
            'status' => 'draft',
            'type' => 'editorial',
            'featured_image_id' => null,
        ]);

        $scheduled = Article::factory()->create([
            'title' => 'Scheduled event',
            'excerpt' => 'Approved event ready for scheduling.',
            'body_html' => '<p>Approved event content.</p>',
            'author_id' => $primaryAuthor->id,
            'category_id' => $events->id,
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $admin->id,
            'type' => 'event',
        ]);

        $imported = Article::factory()->create([
            'title' => 'Imported post',
            'author_id' => $otherAuthor->id,
            'category_id' => $culture->id,
            'status' => 'published',
            'type' => 'news',
            'published_at' => now()->subHour(),
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 991,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $imported->id,
            'old_url' => '/old-imported-post',
            'new_url' => '/imported-post',
            'status' => 'imported',
            'imported_at' => now(),
        ]);

        Livewire::test(ListArticles::class)
            ->assertTableFilterExists('status')
            ->assertTableFilterExists('type')
            ->assertTableFilterExists('author_id')
            ->assertTableFilterExists('category_id')
            ->assertTableFilterExists('featured_image')
            ->assertTableFilterExists('sponsorship')
            ->assertTableFilterExists('import_status')
            ->filterTable('author_id', $primaryAuthor)
            ->assertCanSeeTableRecords([$draft, $scheduled])
            ->assertCanNotSeeTableRecords([$imported])
            ->resetTableFilters()
            ->filterTable('category_id', $culture)
            ->assertCanSeeTableRecords([$draft, $imported])
            ->assertCanNotSeeTableRecords([$scheduled])
            ->resetTableFilters()
            ->filterTable('import_status', 'imported')
            ->assertCanSeeTableRecords([$imported])
            ->assertCanNotSeeTableRecords([$draft, $scheduled])
            ->resetTableFilters()
            ->callAction(TestAction::make('publish')->table($draft))
            ->callAction(TestAction::make('schedule')->table($scheduled), [
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->callAction(TestAction::make('archive')->table($imported));

        $this->assertSame('published', $draft->fresh()->status);
        $this->assertNotNull($draft->fresh()->published_at);
        $this->assertSame('scheduled', $scheduled->fresh()->status);
        $this->assertNotNull($scheduled->fresh()->scheduled_at);
        $this->assertSame('archived', $imported->fresh()->status);
    }

    public function test_sponsored_article_validation_enforces_public_disclosure_labels_and_sponsor_details(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        $author = Author::factory()->create();
        $category = Category::factory()->create();

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'title' => 'Paid city guide',
                'slug' => 'paid-city-guide',
                'status' => 'draft',
                'type' => 'sponsored',
                'body_html' => '<p>Sponsored article body.</p>',
                'author_id' => $author->id,
                'category_id' => $category->id,
                'is_sponsored' => true,
                'sponsoredContentDetail' => [
                    'sponsor_name' => 'Local Sponsor',
                    'disclosure_label' => 'Advertisement',
                ],
            ])
            ->call('create')
            ->assertHasFormErrors([
                'sponsoredContentDetail.disclosure_label' => 'in',
            ]);

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'title' => 'Paid city guide valid',
                'slug' => 'paid-city-guide-valid',
                'status' => 'draft',
                'type' => 'sponsored',
                'body_html' => '<p>Sponsored article body.</p>',
                'author_id' => $author->id,
                'category_id' => $category->id,
                'is_sponsored' => true,
                'sponsoredContentDetail' => [
                    'sponsor_name' => 'Local Sponsor',
                    'disclosure_label' => 'Спонзорирана објава',
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('sponsored_content_details', [
            'sponsor_name' => 'Local Sponsor',
            'disclosure_label' => 'Спонзорирана објава',
        ]);
    }

    public function test_article_form_uses_media_gallery_for_featured_and_open_graph_images(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        Livewire::test(CreateArticle::class)
            ->assertFormFieldExists('featured_image_upload', fn (FileUpload $field): bool => true)
            ->assertFormFieldExists('featured_image_id', fn (ModalTableSelect $field): bool => $this->mediaSelectUsesGallery($field))
            ->assertFormFieldExists('seoMetadata.og_image_id', fn (ModalTableSelect $field): bool => $this->mediaSelectUsesGallery($field));

        Livewire::test(CreateAuthor::class)
            ->assertFormFieldExists('avatar_id', fn (ModalTableSelect $field): bool => $this->mediaSelectUsesGallery($field));
    }

    public function test_regular_article_can_be_created_without_empty_sponsor_details(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        $author = Author::factory()->create();
        $category = Category::factory()->create();
        $libraryImage = MediaAsset::factory()->create();

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'title' => 'Едноставна нова објава',
                'body_html' => '<p>Ова е содржината на објавата.</p>',
                'author_id' => $author->id,
                'category_id' => $category->id,
                'featured_image_id' => $libraryImage->id,
                'type' => 'editorial',
                'status' => 'draft',
                'is_sponsored' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = Article::query()->where('title', 'Едноставна нова објава')->firstOrFail();

        $this->assertSame('draft', $article->status);
        $this->assertNull($article->published_at);
        $this->assertSame('ednostavna-nova-objava', $article->slug);
        $this->assertSame($libraryImage->id, $article->featured_image_id);
        $this->assertDatabaseMissing('sponsored_content_details', ['article_id' => $article->id]);
    }

    public function test_author_admin_surfaces_article_counts_and_article_list(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        $author = Author::factory()->create(['name' => 'Visible Author']);
        Article::factory()->count(2)->create(['author_id' => $author->id]);

        Livewire::test(ListAuthors::class)
            ->assertTableColumnExists('articles_count');

        Livewire::test(ViewAuthor::class, ['record' => $author->id])
            ->assertSchemaComponentExists('articles_count')
            ->assertSee('2');
    }

    public function test_wordpress_import_admin_has_status_type_and_warning_filters(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        $failedPost = WordpressImportRecord::query()->create([
            'wordpress_id' => 1201,
            'wordpress_type' => 'post',
            'status' => 'failed',
            'old_url' => '/failed-post',
            'warnings' => ['missing_featured_image'],
            'error_message' => 'Missing image',
        ]);

        $importedPage = WordpressImportRecord::query()->create([
            'wordpress_id' => 1202,
            'wordpress_type' => 'page',
            'status' => 'imported',
            'old_url' => '/imported-page',
            'warnings' => [],
            'imported_at' => now(),
        ]);

        Livewire::test(ListWordpressImportRecords::class)
            ->assertTableFilterExists('status')
            ->assertTableFilterExists('wordpress_type')
            ->assertTableFilterExists('has_warnings')
            ->filterTable('wordpress_type', 'post')
            ->assertCanSeeTableRecords([$failedPost])
            ->assertCanNotSeeTableRecords([$importedPage])
            ->resetTableFilters()
            ->filterTable('has_warnings')
            ->assertCanSeeTableRecords([$failedPost])
            ->assertCanNotSeeTableRecords([$importedPage]);
    }

    public function test_dashboard_stats_are_shortcuts_to_core_admin_workflows(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        $urls = collect($this->statsFrom(PublishingStatsOverview::class))
            ->map(fn (Stat $stat): ?string => $stat->getUrl())
            ->filter()
            ->values()
            ->all();

        $this->assertContains(ArticleResource::getUrl(), $urls);
        $this->assertContains(TeamMemberResource::getUrl(), $urls);
        $this->assertNotContains(AuthorResource::getUrl(), $urls);
        $this->assertContains(AdCampaignResource::getUrl(), $urls);
        $this->assertContains(WordpressImportRecordResource::getUrl(), $urls);
    }

    public function test_admin_permissions_login_flow_and_immutable_ad_zone_keys_are_preserved(): void
    {
        $this->seed();

        $admin = $this->seededAdmin();
        $article = Article::factory()->create();
        $zone = AdZone::query()->where('key', 'home_top_billboard')->firstOrFail();

        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
        $this->actingAs($admin);

        $this->assertTrue(ArticleResource::canCreate());
        $this->assertTrue(ArticleResource::canEdit($article));
        $this->assertTrue($admin->can('publish', $article));
        $this->assertTrue(AuthorResource::canCreate());
        $this->assertTrue(AdCampaignResource::canCreate());
        $this->assertTrue(AdCreativeResource::canCreate());

        Livewire::test(EditAdZone::class, ['record' => $zone->id])
            ->fillForm(['key' => 'changed-key'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('home_top_billboard', $zone->fresh()->key);

        auth()->logout();

        $this->get('/backoffice')
            ->assertRedirect('/backoffice/login');

        $this->get('/backoffice/login')
            ->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'admin@example.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin);

        $this->actingAs($admin)
            ->get('/backoffice')
            ->assertOk();
    }

    private function seededAdmin(): User
    {
        return User::query()->where('email', 'admin@example.test')->firstOrFail();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }

    private function mediaSelectUsesGallery(ModalTableSelect $field): bool
    {
        return $field->getTableConfiguration() === MediaAssetGalleryTable::class
            && $field->hasOptionLabelFromRecordUsingCallback();
    }

    /**
     * @param  class-string  $widgetClass
     * @return array<int, Stat>
     */
    private function statsFrom(string $widgetClass): array
    {
        $widget = app($widgetClass);
        $method = (new ReflectionClass($widget))->getMethod('getStats');
        $method->setAccessible(true);

        return $method->invoke($widget);
    }
}
