<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Redirect;
use App\Models\Tag;
use App\Models\WordpressImportMapping;
use App\Models\WordpressImportRecord;
use App\Services\Wordpress\WordpressContentReconciliationService;
use App\Services\Wordpress\WordpressContentSanitizer;
use App\Services\Wordpress\WordpressFrozenContentDecisionProvenance;
use App\Services\Wordpress\WordpressImportRetryService;
use App\Services\Wordpress\WordpressRestImporter;
use App\Services\Wordpress\WordpressUnavailableMediaManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WordpressContentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $bundleDirectories = [];

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.wordpress.base_url', 'https://old.example.test');
        config()->set('services.wordpress.media_hosts', []);
        config()->set('app.frontend_url', 'https://new.example.test');
        Storage::fake('public');
        Http::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->bundleDirectories as $directory) {
            @chmod($directory, 0700);

            foreach ((array) glob($directory.'/*') as $path) {
                @chmod($path, 0600);
                @unlink($path);
            }

            @rmdir($directory);
        }

        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_apply_repairs_full_materialized_parity_imports_drafts_and_is_idempotent(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $secondCategory = $this->categoryPayload(21, 'Битола', 'bitola', parent: 20);
        $tag = $this->tagPayload(30, 'Настани', 'nastani');
        $secondTag = $this->tagPayload(31, 'Интервју', 'intervju');
        $author = $this->authorPayload();
        $unreferencedAuthor = $this->authorPayload(11, 'archive-only-author', 'Archive Only Author');
        $published = $this->postPayload(39880, 'status-mismatch', 'publish', [
            'categories' => [$category, $secondCategory],
            'tags' => [$tag, $secondTag],
            'featured_media' => $this->featuredMedia(700, 'published.png'),
            'yoast_head_json' => [
                'title' => 'Frozen SEO title',
                'description' => 'Frozen SEO description',
                'og_title' => 'Frozen OG title',
                'og_description' => 'Frozen OG description',
            ],
        ]);
        $draft = $this->postPayload(50000, 'editorial-draft', 'draft', [
            'title' => ['rendered' => 'Непубликуван наслов'],
            'excerpt' => ['rendered' => '<p>Непубликуван извадок.</p>'],
            'content' => ['rendered' => '<p>Непубликуван текст.</p><script>alert(1)</script>'],
            'categories' => [$secondCategory],
            'tags' => [$tag],
            'featured_media' => $this->featuredMedia(701, 'draft.png'),
            'date_gmt' => '2025-01-02T03:04:05',
            'modified_gmt' => '2025-02-03T04:05:06',
            'link' => 'https://old.example.test/?p=50000&preview=true',
        ]);
        $bundle = $this->writeBundle([
            'published_posts' => [$published],
            'nonpublic_posts' => [['payload' => $draft, 'target_status' => 'draft']],
            'auto_drafts' => [[
                'wordpress_id' => 60000,
                'source_status' => 'auto-draft',
                'source_slug' => '',
                'source_title' => 'Auto Draft',
                'source_date_gmt' => '2025-03-01T00:00:00',
                'source_modified_gmt' => '2025-03-01T00:00:00',
                'disposition' => 'retain_in_verified_wordpress_backup',
            ]],
            'categories' => [$category, $secondCategory],
            'tags' => [$tag, $secondTag],
            'authors' => [$author, $unreferencedAuthor],
        ], [
            'publish' => 1,
            'draft' => 1,
            'pending' => 0,
            'future' => 0,
            'private' => 0,
            'auto-draft' => 1,
        ]);

        $importer = app(WordpressRestImporter::class);
        $importer->importStoredPostPayload($published, 'https://old.example.test');
        $article = Article::query()->where('old_wordpress_id', 39880)->firstOrFail();
        $wrongAuthor = Author::factory()->create();
        $wrongCategory = Category::factory()->create();
        $wrongMedia = MediaAsset::factory()->create();

        $article->forceFill([
            'slug' => 'corrupt-slug',
            'body_html' => '<p>Corrupt body</p>',
            'status' => 'review',
            'author_id' => $wrongAuthor->id,
            'category_id' => $wrongCategory->id,
            'featured_image_id' => $wrongMedia->id,
        ])->save();
        $article->categories()->sync([$wrongCategory->id]);
        $article->tags()->sync([]);
        $article->seoMetadata()->update([
            'seo_title' => 'Corrupt SEO',
            'canonical_url' => 'https://wrong.example.test/',
        ]);
        WordpressImportRecord::query()
            ->where('wordpress_type', 'author')
            ->where('wordpress_id', 10)
            ->update(['old_url' => null, 'new_url' => null]);
        $categoryRecord = WordpressImportRecord::query()
            ->where('wordpress_type', 'category')
            ->where('wordpress_id', 20)
            ->firstOrFail();
        Category::query()->whereKey($categoryRecord->importable_id)->update([
            'description' => 'Corrupt description',
            'is_active' => false,
        ]);
        $categoryRecord->forceFill([
            'old_url' => null,
            'new_url' => null,
            'source_payload' => ['id' => 20, 'slug' => 'corrupt'],
        ])->save();
        Redirect::query()->create([
            'source_url' => $draft['link'],
            'target_url' => '/preview/editorial-draft',
            'status_code' => 301,
            'is_active' => true,
        ]);
        Redirect::query()->create([
            'source_url' => 'https://old.example.test/',
            'target_url' => '/homepage-legacy',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $service = app(WordpressContentReconciliationService::class);
        $dryRun = $service->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertTrue($dryRun['valid'], json_encode($dryRun, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($dryRun['safe_to_apply'], json_encode($dryRun, JSON_UNESCAPED_SLASHES));
        $this->assertSame([39880, 50000], array_merge(
            $dryRun['plan']['published_posts']['ids'],
            $dryRun['plan']['nonpublic_posts']['ids'],
        ));
        $this->assertSame([39880], data_get($dryRun, 'parity_before.issues.slug_mismatch.ids'));
        $this->assertSame([39880], data_get($dryRun, 'parity_before.issues.content_body_mismatch.ids'));
        $this->assertSame([39880], data_get($dryRun, 'parity_before.issues.association_mismatch.ids'));
        $this->assertSame([39880], data_get($dryRun, 'parity_before.issues.featured_media_mismatch.ids'));
        $this->assertSame([39880], data_get($dryRun, 'parity_before.issues.seo_mismatch.ids'));
        $this->assertContains(20, data_get($dryRun, 'parity_before.issues.category_mismatch_ids.ids'));
        $this->assertContains(11, data_get($dryRun, 'parity_before.issues.author_mismatch_ids.ids'));
        $this->assertSame([50000], $dryRun['content_review']['nonpublic']['manual_review_ids']);
        $this->assertSame(0, $dryRun['content_review']['published']['manual_review_articles']);

        $applied = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($applied['reconciliation_complete'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($applied['launch_ready'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertSame(0, $applied['apply']['failed']);
        $this->assertTrue($applied['parity_after']['exact']);
        $this->assertSame([
            'wordpress_id' => 50000,
            'cms_article_id' => Article::query()->where('old_wordpress_id', 50000)->value('id'),
            'source_status' => 'draft',
            'target_status' => 'draft',
            'exact' => true,
        ], $applied['parity_after']['status_evidence']['nonpublic_posts'][0]);

        $publishedArticle = Article::query()->where('old_wordpress_id', 39880)->firstOrFail();
        $draftArticle = Article::query()->where('old_wordpress_id', 50000)->firstOrFail();
        $mappedAuthor = WordpressImportRecord::query()
            ->where('wordpress_type', 'author')
            ->where('wordpress_id', 10)
            ->firstOrFail();
        $mappedCategories = WordpressImportRecord::query()
            ->where('wordpress_type', 'category')
            ->whereIn('wordpress_id', [20, 21])
            ->pluck('importable_id', 'wordpress_id');
        $mappedTags = WordpressImportRecord::query()
            ->where('wordpress_type', 'tag')
            ->whereIn('wordpress_id', [30, 31])
            ->pluck('importable_id', 'wordpress_id');

        $this->assertSame('published', $publishedArticle->status);
        $this->assertSame('status-mismatch', $publishedArticle->slug);
        $this->assertSame((int) $mappedAuthor->importable_id, (int) $publishedArticle->author_id);
        $this->assertSame((int) $mappedCategories[20], (int) $publishedArticle->category_id);
        $this->assertEqualsCanonicalizing($mappedCategories->values()->map(fn ($id): int => (int) $id)->all(), $publishedArticle->categories()->pluck('categories.id')->all());
        $this->assertEqualsCanonicalizing($mappedTags->values()->map(fn ($id): int => (int) $id)->all(), $publishedArticle->tags()->pluck('tags.id')->all());
        $this->assertSame(700, (int) data_get($publishedArticle->featuredImage?->metadata, 'wordpress_id'));
        $this->assertSame('Frozen SEO title', $publishedArticle->seoMetadata?->seo_title);
        $this->assertSame($publishedArticle->publicUrl(), $publishedArticle->seoMetadata?->canonical_url);

        $this->assertSame('draft', $draftArticle->status);
        $this->assertNull($draftArticle->published_at);
        $this->assertNull($draftArticle->scheduled_at);
        $this->assertSame('Непубликуван наслов', $draftArticle->title);
        $this->assertSame('editorial-draft', $draftArticle->slug);
        $this->assertSame('Непубликуван извадок.', $draftArticle->excerpt);
        $this->assertSame('<p>Непубликуван текст.</p>', $draftArticle->body_html);
        $this->assertSame('2025-01-02 03:04:05', $draftArticle->created_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2025-02-03 04:05:06', $draftArticle->updated_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame((int) $mappedAuthor->importable_id, (int) $draftArticle->author_id);
        $this->assertSame((int) $mappedCategories[21], (int) $draftArticle->category_id);
        $this->assertSame([30], WordpressImportRecord::query()
            ->where('wordpress_type', 'tag')
            ->whereIn('importable_id', $draftArticle->tags()->pluck('tags.id'))
            ->pluck('wordpress_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(701, (int) data_get($draftArticle->featuredImage?->metadata, 'wordpress_id'));

        $draftRecord = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 50000)
            ->firstOrFail();
        $this->assertNull($draftRecord->new_url);
        $this->assertSame($draft['link'], $draftRecord->old_url);
        $this->assertSame(WordpressRestImporter::CONTENT_PIPELINE_VERSION, data_get($draftRecord->source_payload, '_razbudise.content_pipeline.version'));
        $this->assertSame(hash('sha256', $draftArticle->body_html), data_get($draftRecord->source_payload, '_razbudise.content_pipeline.persisted_body_sha256'));
        $this->assertFalse(Redirect::query()->where('source_url', $draft['link'])->where('is_active', true)->exists());
        $this->assertTrue(Redirect::query()->where('source_url', 'https://old.example.test/')->where('is_active', true)->exists());
        $this->assertDatabaseMissing(Article::class, ['old_wordpress_id' => 60000]);
        $this->assertDatabaseMissing(WordpressImportRecord::class, ['wordpress_type' => 'post', 'wordpress_id' => 60000]);
        $this->assertSame($author['old_url'], $mappedAuthor->fresh()->old_url);
        $this->assertSame('/profil/source-author', $mappedAuthor->fresh()->new_url);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_type' => 'author',
            'wordpress_id' => 11,
            'status' => 'imported',
            'old_url' => $unreferencedAuthor['old_url'],
            'new_url' => '/profil/archive-only-author',
        ]);
        $this->assertDatabaseHas(Author::class, [
            'slug' => 'archive-only-author',
            'name' => 'Archive Only Author',
        ]);
        $this->assertDatabaseHas(Category::class, [
            'id' => $categoryRecord->importable_id,
            'description' => $category['description'],
            'is_active' => true,
        ]);

        $rerun = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($rerun['reconciliation_complete']);
        $this->assertSame(0, $rerun['plan']['total_writes']);
        $this->assertSame(0, $rerun['apply']['attempted']);
        $this->assertSame(0, $rerun['apply']['failed']);
    }

    public function test_mapped_category_preserves_canonical_target_and_repairs_redirect_idempotently(): void
    {
        $source = $this->categoryPayload(3363, 'Инвестиции и финансии', 'investicii-i-finansii');
        $canonical = Category::factory()->create([
            'name' => 'Финансии',
            'slug' => 'finansii',
            'description' => 'Canonical finance description.',
            'is_active' => true,
        ]);
        $collidingSourceCategory = Category::factory()->create([
            'name' => 'Инвестиции и финансии',
            'slug' => 'investicii-i-finansii',
            'is_active' => false,
        ]);
        WordpressImportMapping::query()->create([
            'source_type' => 'category',
            'wordpress_id' => 3363,
            'source_slug' => 'investicii-i-finansii',
            'target_type' => Category::class,
            'target_id' => $canonical->id,
            'notes' => 'Merge into canonical finance category.',
        ]);
        $bundle = $this->writeBundle([
            'categories' => [$source],
        ]);

        $service = app(WordpressContentReconciliationService::class);
        $applied = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($applied['reconciliation_complete'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertSame(0, $applied['apply']['failed'], json_encode($applied['apply'], JSON_UNESCAPED_SLASHES));
        $this->assertDatabaseHas(Category::class, [
            'id' => $canonical->id,
            'name' => 'Финансии',
            'slug' => 'finansii',
            'description' => 'Canonical finance description.',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas(Category::class, [
            'id' => $collidingSourceCategory->id,
            'slug' => 'investicii-i-finansii',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_type' => 'category',
            'wordpress_id' => 3363,
            'importable_type' => Category::class,
            'importable_id' => $canonical->id,
            'old_url' => $source['link'],
            'new_url' => '/category/finansii',
            'status' => 'imported',
        ]);
        $this->assertDatabaseHas(Redirect::class, [
            'source_url' => $source['link'],
            'target_url' => '/category/finansii',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $rerun = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($rerun['reconciliation_complete'], json_encode($rerun, JSON_UNESCAPED_SLASHES));
        $this->assertSame(0, $rerun['plan']['total_writes']);
        $this->assertSame(0, $rerun['apply']['attempted']);
        $this->assertSame(0, $rerun['apply']['failed']);
    }

    public function test_untitled_nonpublic_post_is_preserved_with_a_deterministic_private_label(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $draft = $this->postPayload(3667, '', 'draft', [
            'title' => ['rendered' => ''],
            'link' => '',
            'categories' => [$category],
            'featured_media' => $this->featuredMedia(703, 'untitled.png'),
        ]);
        $bundle = $this->writeBundle([
            'published_posts' => [],
            'nonpublic_posts' => [['payload' => $draft, 'target_status' => 'draft']],
            'auto_drafts' => [],
            'categories' => [$category],
            'tags' => [],
            'authors' => [$this->authorPayload()],
        ]);
        $importer = app(WordpressRestImporter::class);

        $this->assertSame('', $importer->materializedPostTitle($draft));
        $this->assertSame(
            'Untitled WordPress draft #3667',
            $importer->materializedPostTitle($draft, allowUntitledNonpublic: true),
        );

        $forgedDraft = $draft;
        $forgedDraft['_razbudise']['frozen_reconciliation_provenance'] = [
            'source' => 'validated_reconciliation_payload',
            'post_id' => 3667,
            'manifest_sha256' => str_repeat('a', 64),
            'source_payload_sha256' => str_repeat('b', 64),
            'signature' => str_repeat('c', 64),
        ];

        try {
            $importer->importStoredPostPayload(
                $forgedDraft,
                'https://old.example.test',
                allowUntitledNonpublic: true,
            );
            $this->fail('A forged reconciliation payload imported an untitled draft.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('WordPress post is missing an ID or title.', $exception->getMessage());
        }

        $failedRecord = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 3667)
            ->firstOrFail();
        $this->assertSame('failed', $failedRecord->status);
        $this->assertNull(data_get(
            $failedRecord->source_payload,
            '_razbudise.frozen_reconciliation_provenance',
        ));
        $this->assertDatabaseMissing(Article::class, ['old_wordpress_id' => 3667]);

        $service = app(WordpressContentReconciliationService::class);
        $dryRun = $service->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertTrue($dryRun['valid'], json_encode($dryRun, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($dryRun['safe_to_apply'], json_encode($dryRun, JSON_UNESCAPED_SLASHES));
        $this->assertSame([3667], $dryRun['plan']['nonpublic_posts']['ids']);
        $this->assertSame(0, $dryRun['content_review']['nonpublic']['manual_review_articles']);
        $this->assertSame([
            'articles' => 1,
            'ids' => [3667],
        ], $dryRun['content_review']['nonpublic']['warnings'][WordpressRestImporter::UNTITLED_NONPUBLIC_WARNING]);

        $applied = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($applied['reconciliation_complete'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($applied['parity_after']['exact']);
        $article = Article::query()->where('old_wordpress_id', 3667)->firstOrFail();
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 3667)
            ->firstOrFail();

        $this->assertSame('draft', $article->status);
        $this->assertSame('Untitled WordPress draft #3667', $article->title);
        $this->assertSame('wordpress-3667', $article->slug);
        $this->assertSame('', data_get($record->source_payload, 'title.rendered'));
        $this->assertSame('', data_get($record->source_payload, 'slug'));
        $this->assertContains(WordpressRestImporter::UNTITLED_NONPUBLIC_WARNING, $record->warnings);
        $this->assertNull($record->new_url);
        $this->assertTrue($importer->hasFrozenReconciliationProvenance($record->source_payload));

        $article->forceFill(['title' => 'Corrupt title'])->save();
        $failingSanitizer = Mockery::mock(WordpressContentSanitizer::class);
        $failingSanitizer->shouldReceive('setMediaSourceBase')->twice();
        $failingSanitizer->shouldReceive('downloadMedia')
            ->once()
            ->andReturn($article->featuredImage);
        $failingSanitizer->shouldReceive('preview')
            ->once()
            ->andThrow(new RuntimeException('Transient first-attempt failure.'));
        $failingImporter = new WordpressRestImporter(
            $failingSanitizer,
            app(WordpressFrozenContentDecisionProvenance::class),
        );

        try {
            $failingImporter->importStoredPostPayload(
                $record->source_payload,
                'https://old.example.test',
                allowUntitledNonpublic: true,
            );
            $this->fail('The transient importer fixture unexpectedly succeeded.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Transient first-attempt failure.', $exception->getMessage());
        }

        $failedRecord = $record->fresh();
        $this->assertSame('failed', $failedRecord->status);
        $this->assertSame([], $failedRecord->warnings);
        $this->assertTrue($importer->hasFrozenReconciliationProvenance($failedRecord->source_payload));

        $retried = app(WordpressImportRetryService::class)->retry($failedRecord);
        $this->assertSame('Untitled WordPress draft #3667', $retried->title);
        $this->assertContains(
            WordpressRestImporter::UNTITLED_NONPUBLIC_WARNING,
            $record->fresh()->warnings,
        );

        $rerun = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($rerun['reconciliation_complete']);
        $this->assertSame(0, $rerun['plan']['total_writes']);
        $this->assertSame(0, $rerun['apply']['attempted']);
    }

    public function test_untitled_published_post_remains_invalid(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $published = $this->postPayload(3668, 'published-without-title', 'publish', [
            'title' => ['rendered' => ''],
            'categories' => [$category],
        ]);
        $bundle = $this->writeBundle($this->singlePublishedSections($published, [$category]));

        $result = app(WordpressContentReconciliationService::class)
            ->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertFalse($result['valid']);
        $this->assertContains('published_posts:1:editorial_payload_incomplete', $result['validation_errors']);
    }

    public function test_transliterated_tag_slug_collisions_preserve_source_identities_urls_and_relationships(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $cyrillicSlug = rawurlencode('битола');
        $cyrillicTag = $this->tagPayload(30, 'битола', $cyrillicSlug);
        $latinTag = $this->tagPayload(31, 'bitola', 'bitola');
        $published = $this->postPayload(39881, 'tag-collision', 'publish', [
            'categories' => [$category],
            'tags' => [$cyrillicTag, $latinTag],
        ]);
        $bundle = $this->writeBundle([
            'published_posts' => [$published],
            'nonpublic_posts' => [],
            'auto_drafts' => [],
            'categories' => [$category],
            'tags' => [$cyrillicTag, $latinTag],
            'authors' => [$this->authorPayload()],
        ]);

        app(WordpressRestImporter::class)->importStoredPostPayload($published, 'https://old.example.test');

        $this->assertSame(1, Tag::query()->where('slug', 'bitola')->count());
        $this->assertSame(1, Article::query()->where('old_wordpress_id', 39881)->firstOrFail()->tags()->count());

        $service = app(WordpressContentReconciliationService::class);
        $dryRun = $service->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertTrue($dryRun['valid'], json_encode($dryRun, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($dryRun['safe_to_apply'], json_encode($dryRun, JSON_UNESCAPED_SLASHES));
        $this->assertSame(1, data_get($dryRun, 'manifest.tag_slug_collisions.groups'));
        $this->assertSame(2, data_get($dryRun, 'manifest.tag_slug_collisions.terms'));
        $this->assertSame([30, 31], $dryRun['plan']['tags']['ids']);
        $this->assertSame([39881], $dryRun['plan']['published_posts']['ids']);
        $this->assertSame([30, 31], data_get($dryRun, 'parity_before.issues.duplicate_tag_mapping_ids.ids'));

        $applied = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($applied['reconciliation_complete'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($applied['launch_ready'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertSame(0, $applied['apply']['failed']);
        $this->assertDatabaseHas(Tag::class, ['name' => 'битола', 'slug' => 'bitola-wp-30']);
        $this->assertDatabaseHas(Tag::class, ['name' => 'bitola', 'slug' => 'bitola']);

        $mappedTags = WordpressImportRecord::query()
            ->where('wordpress_type', 'tag')
            ->whereIn('wordpress_id', [30, 31])
            ->pluck('importable_id', 'wordpress_id');
        $article = Article::query()->where('old_wordpress_id', 39881)->firstOrFail();

        $this->assertNotSame((int) $mappedTags[30], (int) $mappedTags[31]);
        $this->assertEqualsCanonicalizing(
            $mappedTags->values()->map(fn (mixed $id): int => (int) $id)->all(),
            $article->tags()->pluck('tags.id')->map(fn (mixed $id): int => (int) $id)->all(),
        );
        $this->assertDatabaseHas(Redirect::class, [
            'source_url' => $cyrillicTag['link'],
            'target_url' => '/tag/bitola-wp-30',
            'status_code' => 301,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing(Redirect::class, [
            'source_url' => $latinTag['link'],
            'is_active' => true,
        ]);

        $rerun = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($rerun['reconciliation_complete']);
        $this->assertSame(0, $rerun['plan']['total_writes']);
        $this->assertSame(0, $rerun['apply']['attempted']);
    }

    public function test_manifest_accepts_literal_unicode_media_paths_only_on_the_configured_host(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $unicodeMedia = $this->featuredMedia(7921, 'битола-агим-4.jpg');
        $payload = $this->postPayload(39882, 'unicode-featured-media', 'publish', [
            'categories' => [$category],
            'featured_media' => $unicodeMedia,
        ]);
        $bundle = $this->writeBundle($this->singlePublishedSections($payload, [$category]));

        $valid = app(WordpressContentReconciliationService::class)
            ->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertTrue($valid['valid'], json_encode($valid, JSON_UNESCAPED_SLASHES));

        $unicodeMedia['source_url'] = 'https://attacker@old.example.test/wp-content/uploads/битола-агим-4.jpg';
        $unsafePayload = $this->postPayload(39883, 'unsafe-unicode-featured-media', 'publish', [
            'categories' => [$category],
            'featured_media' => $unicodeMedia,
        ]);
        $unsafeBundle = $this->writeBundle($this->singlePublishedSections($unsafePayload, [$category]));
        $invalid = app(WordpressContentReconciliationService::class)
            ->reconcile($unsafeBundle['path'], $unsafeBundle['sha256']);

        $this->assertFalse($invalid['valid']);
        $this->assertContains('published_posts:1:featured_media_evidence_invalid', $invalid['validation_errors']);
    }

    public function test_manifest_rejects_taxonomy_array_drift_wrong_origin_and_non_exact_dates(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $secondCategory = $this->categoryPayload(21, 'Битола', 'bitola');
        $payload = $this->postPayload(100, 'taxonomy-drift', 'publish', [
            'categories' => [$category, $secondCategory],
            'declared_category_ids' => [20],
        ]);
        $taxonomyBundle = $this->writeBundle($this->singlePublishedSections($payload, [$category, $secondCategory]));

        $taxonomyResult = app(WordpressContentReconciliationService::class)
            ->reconcile($taxonomyBundle['path'], $taxonomyBundle['sha256']);

        $this->assertFalse($taxonomyResult['valid']);
        $this->assertContains('published_posts:1:taxonomy_id_evidence_mismatch', $taxonomyResult['validation_errors']);
        $this->assertSame(0, Article::query()->count());

        $wrongOriginPayload = $this->postPayload(101, 'wrong-origin', 'publish', [
            'categories' => [$category],
            'link' => 'https://attacker.example/wrong-origin/',
        ]);
        $originBundle = $this->writeBundle($this->singlePublishedSections($wrongOriginPayload, [$category]));
        $originResult = app(WordpressContentReconciliationService::class)
            ->reconcile($originBundle['path'], $originBundle['sha256']);

        $this->assertFalse($originResult['valid']);
        $this->assertContains('published_posts:1:source_url_invalid', $originResult['validation_errors']);

        $badDatePayload = $this->postPayload(102, 'bad-date', 'publish', [
            'categories' => [$category],
            'date_gmt' => 'tomorrow',
        ]);
        $dateBundle = $this->writeBundle($this->singlePublishedSections($badDatePayload, [$category]));
        $dateResult = app(WordpressContentReconciliationService::class)
            ->reconcile($dateBundle['path'], $dateBundle['sha256']);

        $this->assertFalse($dateResult['valid']);
        $this->assertContains('published_posts:1:editorial_payload_incomplete', $dateResult['validation_errors']);

        config()->set('services.wordpress.base_url', 'https://different.example.test');
        $configuredOriginResult = app(WordpressContentReconciliationService::class)
            ->reconcile($originBundle['path'], $originBundle['sha256']);

        $this->assertFalse($configuredOriginResult['valid']);
        $this->assertContains('source_base_url_invalid', $configuredOriginResult['validation_errors']);
    }

    public function test_apply_reports_partial_failure_and_same_bundle_can_be_rerun(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $payload = $this->postPayload(200, 'rerunnable-failure', 'publish', ['categories' => [$category]]);
        $bundle = $this->writeBundle($this->singlePublishedSections($payload, [$category]));
        $realService = app(WordpressContentReconciliationService::class);

        $firstApply = $realService->reconcile($bundle['path'], $bundle['sha256'], apply: true);
        $this->assertTrue($firstApply['reconciliation_complete']);

        Article::query()->where('old_wordpress_id', 200)->delete();
        WordpressImportRecord::query()->where('wordpress_type', 'post')->where('wordpress_id', 200)->delete();

        $failingImporter = Mockery::mock(WordpressRestImporter::class);
        $failingImporter->shouldReceive('setFrozenLinkMap')->once();
        $failingImporter->shouldReceive('clearFrozenLinkMap')->once();
        $failingImporter->shouldReceive('importStoredPostPayload')
            ->once()
            ->andThrow(new RuntimeException('fixture failure'));
        $failingService = new WordpressContentReconciliationService(
            $failingImporter,
            app(WordpressContentSanitizer::class),
            app(WordpressFrozenContentDecisionProvenance::class),
            app(WordpressUnavailableMediaManifestService::class),
        );
        $failed = $failingService->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertFalse($failed['reconciliation_complete']);
        $this->assertSame(1, $failed['apply']['failed']);
        $this->assertSame([
            'source_type' => 'published_post',
            'wordpress_id' => 200,
            'reason' => 'import_failed',
            'exception_class' => RuntimeException::class,
            'exception_message' => 'fixture failure',
        ], $failed['apply']['failures'][0]);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_type' => 'post',
            'wordpress_id' => 200,
            'status' => 'failed',
            'error_message' => 'content_reconciliation_import_failed',
        ]);

        $retryService = app(WordpressContentReconciliationService::class);
        $retried = $retryService->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($retried['reconciliation_complete']);
        $this->assertSame(0, $retried['apply']['failed']);
        $this->assertDatabaseHas(Article::class, ['old_wordpress_id' => 200, 'status' => 'published']);
    }

    public function test_content_pipeline_version_change_refreshes_once_even_when_raw_payload_hash_matches(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $payload = $this->postPayload(250, 'pipeline-refresh', 'publish', ['categories' => [$category]]);
        $bundle = $this->writeBundle($this->singlePublishedSections($payload, [$category]));
        $service = app(WordpressContentReconciliationService::class);

        $firstApply = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);
        $this->assertTrue($firstApply['reconciliation_complete']);

        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 250)
            ->firstOrFail();
        $sourcePayload = $record->source_payload;
        data_set($sourcePayload, '_razbudise.content_pipeline.version', 'old-pipeline');
        $record->forceFill(['source_payload' => $sourcePayload])->save();

        $refresh = $service->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertSame([250], data_get($refresh, 'parity_before.issues.content_pipeline_mismatch.ids'));
        $this->assertNull(data_get($refresh, 'parity_before.issues.source_evidence_mismatch'));

        $repaired = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);
        $this->assertTrue($repaired['reconciliation_complete']);
        $this->assertSame(1, $repaired['apply']['succeeded']);

        $rerun = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);
        $this->assertSame(0, $rerun['plan']['total_writes']);
        $this->assertSame(0, $rerun['apply']['attempted']);
    }

    public function test_streaming_dry_run_stays_within_record_and_byte_bounds_and_checks_cardinality(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $rows = [];

        for ($id = 1000; $id < 1251; $id++) {
            $rows[] = [
                'payload' => $this->postPayload($id, "draft-{$id}", 'draft', ['categories' => [$category]]),
                'target_status' => 'draft',
            ];
        }

        $sections = [
            'published_posts' => [],
            'nonpublic_posts' => $rows,
            'auto_drafts' => [],
            'categories' => [$category],
            'tags' => [],
            'authors' => [$this->authorPayload()],
        ];
        $bundle = $this->writeBundle($sections, [
            'publish' => 0,
            'draft' => 251,
            'pending' => 0,
            'future' => 0,
            'private' => 0,
            'auto-draft' => 0,
        ]);
        $oldMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '256M');

        try {
            $result = app(WordpressContentReconciliationService::class)
                ->reconcile($bundle['path'], $bundle['sha256']);
        } finally {
            ini_set('memory_limit', (string) $oldMemoryLimit);
        }

        $this->assertTrue($result['valid']);
        $this->assertSame(251, $result['content_review']['articles_scanned']);
        $this->assertLessThanOrEqual(50, $result['streaming']['max_chunk_records_observed']);
        $this->assertLessThanOrEqual(8_388_608, $result['streaming']['max_chunk_bytes_observed']);
        $this->assertSame(251, $result['plan']['nonpublic_posts']['upsert_count']);

        $badBundle = $this->writeBundle($sections, [
            'publish' => 0,
            'draft' => 250,
            'pending' => 0,
            'future' => 0,
            'private' => 0,
            'auto-draft' => 0,
        ]);
        $invalid = app(WordpressContentReconciliationService::class)
            ->reconcile($badBundle['path'], $badBundle['sha256']);

        $this->assertFalse($invalid['valid']);
        $this->assertContains('post_status_cardinality_mismatch', $invalid['validation_errors']);
    }

    public function test_command_blocks_published_manual_review_and_rejects_broad_file_modes(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $unsafe = $this->postPayload(300, 'unsafe-published', 'publish', [
            'categories' => [$category],
            'content' => ['rendered' => '<p>Text</p><script>alert(1)</script>'],
        ]);
        $bundle = $this->writeBundle($this->singlePublishedSections($unsafe, [$category]));

        $this->artisan('wordpress:reconcile-content', [
            'manifest' => $bundle['path'],
            '--expected-sha256' => $bundle['sha256'],
        ])
            ->expectsOutput('Published manual review: 1')
            ->assertFailed();

        $this->artisan('wordpress:reconcile-content', [
            'manifest' => $bundle['path'],
            '--expected-sha256' => $bundle['sha256'],
            '--unavailable-media-manifest' => $bundle['path'],
        ])
            ->expectsOutput('Validation errors: unavailable_media_manifest_options_incomplete')
            ->assertFailed();

        chmod(dirname($bundle['path']).'/published-posts.ndjson', 0644);
        $invalid = app(WordpressContentReconciliationService::class)
            ->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertFalse($invalid['valid']);
        $this->assertContains('published_posts_permissions_too_broad', $invalid['validation_errors']);
    }

    public function test_apply_performs_zero_writes_for_frozen_published_manual_review_or_missing_featured_image(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $unsafe = $this->postPayload(310, 'unsafe-apply', 'publish', [
            'categories' => [$category],
            'content' => ['rendered' => '<p>Text</p><script>alert(1)</script>'],
        ]);
        $unsafeBundle = $this->writeBundle($this->singlePublishedSections($unsafe, [$category]));
        $service = app(WordpressContentReconciliationService::class);

        $unsafeResult = $service->reconcile($unsafeBundle['path'], $unsafeBundle['sha256'], apply: true);

        $this->assertTrue($unsafeResult['valid']);
        $this->assertFalse($unsafeResult['safe_to_apply']);
        $this->assertNull($unsafeResult['apply']);
        $this->assertSame([310], $unsafeResult['content_review']['published']['manual_review_ids']);
        $this->assertSame(0, Article::query()->count());
        $this->assertSame(0, WordpressImportRecord::query()->count());

        $missingFeatured = $this->postPayload(311, 'missing-featured', 'publish', [
            'categories' => [$category],
            'featured_media' => null,
        ]);
        $missingBundle = $this->writeBundle($this->singlePublishedSections($missingFeatured, [$category]));
        $missingResult = $service->reconcile($missingBundle['path'], $missingBundle['sha256'], apply: true);

        $this->assertTrue($missingResult['valid']);
        $this->assertFalse($missingResult['safe_to_apply']);
        $this->assertNull($missingResult['apply']);
        $this->assertSame([311], data_get(
            $missingResult,
            'content_review.published.warnings.missing_featured_image.ids',
        ));
        $this->assertSame(0, Article::query()->count());
        $this->assertSame(0, WordpressImportRecord::query()->count());
    }

    public function test_approved_inline_attachment_featured_fallback_is_safe_applied_and_idempotent(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $media = [
            ...$this->featuredMedia(2795, 'multitasking.jpg'),
            'post' => 2782,
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
        ];
        $payload = $this->postPayload(2782, 'mutitasking-prednost-ili-besmislica', 'publish', [
            'categories' => [$category],
            'featured_media' => null,
            'content' => [
                'rendered' => '<p><img class="aligncenter wp-image-2795" src="'.$media['source_url'].'" alt="Multitasking"></p>',
            ],
        ]);
        $payload['_razbudise']['featured_image_fallback'] = [
            'strategy' => 'explicit_inline_attachment',
            'post_id' => 2782,
            'attachment_id' => 2795,
            'decision_sha256' => $this->contentMigrationDecisionSha256(),
            'media' => $media,
        ];
        $bundle = $this->writeBundle($this->singlePublishedSections($payload, [$category]));
        $service = app(WordpressContentReconciliationService::class);

        $dryRun = $service->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertTrue($dryRun['valid'], json_encode($dryRun, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($dryRun['safe_to_apply'], json_encode($dryRun, JSON_UNESCAPED_SLASHES));
        $this->assertSame(0, $dryRun['content_review']['published']['manual_review_articles']);
        $this->assertSame([2782], data_get(
            $dryRun,
            'content_review.published.warnings.trusted_featured_image_fallback.ids',
        ));
        $this->assertNull(data_get(
            $dryRun,
            'content_review.published.warnings.missing_featured_image',
        ));

        $applied = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);
        $article = Article::query()->where('old_wordpress_id', 2782)->with('featuredImage')->firstOrFail();
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 2782)
            ->firstOrFail();

        $this->assertTrue($applied['reconciliation_complete'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($applied['launch_ready']);
        $this->assertSame(2795, (int) data_get($article->featuredImage?->metadata, 'wordpress_id'));
        $this->assertSame('wordpress_attachment', data_get($article->featuredImage?->metadata, 'source_identity'));
        $this->assertContains('wordpress_featured_fallback', data_get($article->featuredImage?->metadata, 'sources'));
        $this->assertContains('featured_fallback', data_get($article->featuredImage?->metadata, 'roles'));
        $this->assertSame(['trusted_featured_image_fallback'], $record->warnings);
        $this->assertSame(1, (int) data_get(
            $record->source_payload,
            '_razbudise.normalization_counts.trusted_featured_image_fallback',
        ));

        $secondApply = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($secondApply['reconciliation_complete']);
        $this->assertTrue($secondApply['parity_after']['exact']);
        $this->assertSame(1, Article::query()->where('old_wordpress_id', 2782)->count());
    }

    public function test_bound_unavailable_media_manifest_is_injected_applied_and_terminally_audited(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $inlineUrl = 'https://old.example.test/wp-content/uploads/2024/01/missing-inline.png';
        $payload = $this->postPayload(340, 'approved-unavailable-media', 'publish', [
            'categories' => [$category],
            'content' => [
                'rendered' => '<p>Before.</p><figure><img src="'.$inlineUrl.'" alt="Missing"><figcaption>Caption stays.</figcaption></figure><p>After.</p>',
            ],
        ]);
        $bundle = $this->writeBundle(
            $this->singlePublishedSections($payload, [$category]),
            cacheFeaturedMedia: false,
        );
        $unavailableMedia = $this->writeUnavailableMediaManifest(
            $bundle['sha256'],
            $payload,
            inline: [[
                'source_url' => $inlineUrl,
                'occurrences' => 1,
                'role' => 'inline',
                'treatment' => 'remove_element',
            ]],
        );
        $service = app(WordpressContentReconciliationService::class);

        $dryRun = $service->reconcile(
            $bundle['path'],
            $bundle['sha256'],
            unavailableMediaManifestPath: $unavailableMedia['path'],
            expectedUnavailableMediaSha256: $unavailableMedia['sha256'],
        );

        $this->assertTrue($dryRun['valid'], json_encode($dryRun, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($dryRun['safe_to_apply'], json_encode($dryRun, JSON_UNESCAPED_SLASHES));
        $this->assertSame($unavailableMedia['sha256'], data_get(
            $dryRun,
            'unavailable_media_manifest.sha256',
        ));
        $this->assertSame($bundle['sha256'], data_get(
            $dryRun,
            'unavailable_media_manifest.reconciliation_sha256',
        ));
        $this->assertSame([
            'posts' => 1,
            'featured' => 1,
            'inline' => 1,
            'inline_occurrences' => 1,
        ], data_get($dryRun, 'unavailable_media_manifest.totals'));
        $this->assertSame([340], data_get(
            $dryRun,
            'parity_before.issues.approved_unavailable_media_ids.ids',
        ));
        $this->assertSame([340], data_get(
            $dryRun,
            'content_review.published.warnings.trusted_unavailable_featured_media.ids',
        ));
        $this->assertSame([340], data_get(
            $dryRun,
            'content_review.published.warnings.trusted_unavailable_inline_media.ids',
        ));
        $this->assertSame(1, data_get(
            $dryRun,
            'content_review.published.normalizations.trusted_unavailable_featured_media',
        ));
        $this->assertSame(1, data_get(
            $dryRun,
            'content_review.published.normalizations.trusted_unavailable_inline_media',
        ));

        $applied = $service->reconcile(
            $bundle['path'],
            $bundle['sha256'],
            apply: true,
            unavailableMediaManifestPath: $unavailableMedia['path'],
            expectedUnavailableMediaSha256: $unavailableMedia['sha256'],
        );
        $article = Article::query()->where('old_wordpress_id', 340)->firstOrFail();
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 340)
            ->firstOrFail();

        $this->assertTrue($applied['reconciliation_complete'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($applied['launch_ready']);
        $this->assertNull($article->featured_image_id);
        $this->assertStringNotContainsString($inlineUrl, (string) $article->body_html);
        $this->assertStringContainsString('Caption stays.', (string) $article->body_html);
        $this->assertContains('trusted_unavailable_featured_media', $record->warnings);
        $this->assertContains('trusted_unavailable_inline_media', $record->warnings);
        $this->assertNotContains('manual_review', $record->warnings);
        $this->assertSame($unavailableMedia['sha256'], data_get(
            $record->source_payload,
            '_razbudise.unavailable_media.manifest_sha256',
        ));
        $this->assertTrue(app(WordpressRestImporter::class)->hasFrozenReconciliationProvenance(
            $record->source_payload,
        ));
        $validSourcePayload = $record->source_payload;
        $validWarnings = $record->warnings;
        $validBody = (string) $article->body_html;
        $validNormalizationCounts = data_get(
            $validSourcePayload,
            '_razbudise.normalization_counts',
            [],
        );
        $this->assertTrue(app(WordpressFrozenContentDecisionProvenance::class)->hasValidMaterialization(
            $validSourcePayload,
            $validBody,
            $validWarnings,
            $validNormalizationCounts,
        ));
        $this->assertSame([340], data_get(
            $applied,
            'parity_after.issues.approved_unavailable_media_ids.ids',
        ));

        $audit = fn (): array => $service->reconcile(
            $bundle['path'],
            $bundle['sha256'],
            unavailableMediaManifestPath: $unavailableMedia['path'],
            expectedUnavailableMediaSha256: $unavailableMedia['sha256'],
        );
        $missingReconciliationProvenance = $validSourcePayload;
        unset($missingReconciliationProvenance['_razbudise']['frozen_reconciliation_provenance']);
        $record->forceFill([
            'source_payload' => $missingReconciliationProvenance,
        ])->save();
        $missingSignature = $audit();

        $this->assertSame([340], data_get(
            $missingSignature,
            'parity_before.issues.unavailable_media_provenance_mismatch.ids',
        ));
        $this->assertSame([340], data_get(
            $missingSignature,
            'parity_before.issues.reconciliation_provenance_mismatch.ids',
        ));
        $this->assertSame([340], $missingSignature['plan']['published_posts']['ids']);

        $tamperedReconciliationProvenance = $validSourcePayload;
        data_set(
            $tamperedReconciliationProvenance,
            '_razbudise.frozen_reconciliation_provenance.signature',
            str_repeat('0', 64),
        );
        $record->forceFill([
            'source_payload' => $tamperedReconciliationProvenance,
        ])->save();
        $tamperedSignature = $audit();

        $this->assertSame([340], data_get(
            $tamperedSignature,
            'parity_before.issues.unavailable_media_provenance_mismatch.ids',
        ));
        $this->assertSame([340], data_get(
            $tamperedSignature,
            'parity_before.issues.reconciliation_provenance_mismatch.ids',
        ));

        $record->forceFill([
            'source_payload' => $validSourcePayload,
            'warnings' => ['trusted_unavailable_featured_media'],
        ])->save();
        $warningDrift = $audit();

        $this->assertSame([340], data_get(
            $warningDrift,
            'parity_before.issues.unavailable_media_evidence_mismatch.ids',
        ));
        $this->assertSame([340], data_get(
            $warningDrift,
            'parity_before.issues.unavailable_media_materialization_mismatch.ids',
        ));

        $tamperedCountsPayload = $validSourcePayload;
        data_set(
            $tamperedCountsPayload,
            '_razbudise.normalization_counts.trusted_unavailable_inline_media',
            99,
        );
        $this->assertTrue(app(WordpressFrozenContentDecisionProvenance::class)
            ->hasValidReconciliationPayload($tamperedCountsPayload));
        $record->forceFill([
            'source_payload' => $tamperedCountsPayload,
            'warnings' => $validWarnings,
        ])->save();
        $countDrift = $audit();

        $this->assertSame([340], data_get(
            $countDrift,
            'parity_before.issues.unavailable_media_evidence_mismatch.ids',
        ));
        $this->assertSame([340], data_get(
            $countDrift,
            'parity_before.issues.unavailable_media_materialization_mismatch.ids',
        ));

        $tamperedBody = '<p>Self-consistent but unauthorized replacement body.</p><script>alert(1)</script>';
        $tamperedBodyPayload = $validSourcePayload;
        data_set(
            $tamperedBodyPayload,
            '_razbudise.content_pipeline.persisted_body_sha256',
            hash('sha256', $tamperedBody),
        );
        $this->assertTrue(app(WordpressFrozenContentDecisionProvenance::class)
            ->hasValidReconciliationPayload($tamperedBodyPayload));
        $record->forceFill([
            'source_payload' => $tamperedBodyPayload,
            'warnings' => $validWarnings,
        ])->save();
        DB::table('articles')->where('id', $article->id)->update(['body_html' => $tamperedBody]);
        $bodyDrift = $audit();

        $this->assertSame([], data_get(
            $bodyDrift,
            'parity_before.issues.content_body_mismatch.ids',
            [],
        ));
        $this->assertSame([340], data_get(
            $bodyDrift,
            'parity_before.issues.unavailable_media_materialization_mismatch.ids',
        ));
        $this->assertSame([340], data_get(
            $bodyDrift,
            'parity_before.issues.materialization_provenance_mismatch.ids',
        ));
        $this->assertSame([340], $bodyDrift['plan']['published_posts']['ids']);

        $this->artisan('wordpress:reconcile-content', [
            'manifest' => $bundle['path'],
            '--expected-sha256' => $bundle['sha256'],
            '--unavailable-media-manifest' => $unavailableMedia['path'],
            '--expected-unavailable-media-sha256' => $unavailableMedia['sha256'],
            '--apply' => true,
        ])->assertSuccessful();

        $noop = $service->reconcile(
            $bundle['path'],
            $bundle['sha256'],
            apply: true,
            unavailableMediaManifestPath: $unavailableMedia['path'],
            expectedUnavailableMediaSha256: $unavailableMedia['sha256'],
        );

        $this->assertTrue($noop['reconciliation_complete'], json_encode($noop, JSON_UNESCAPED_SLASHES));
        $this->assertSame(0, $noop['plan']['total_writes']);
        $this->assertSame(0, $noop['apply']['attempted']);
    }

    public function test_featured_fallback_rejects_attachment_evidence_that_does_not_belong_to_post(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $media = [
            ...$this->featuredMedia(2795, 'multitasking.jpg'),
            'post' => 9999,
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
        ];
        $payload = $this->postPayload(2782, 'mutitasking-prednost-ili-besmislica', 'publish', [
            'categories' => [$category],
            'featured_media' => null,
            'content' => [
                'rendered' => '<p><img class="wp-image-2795" src="'.$media['source_url'].'" alt="Multitasking"></p>',
            ],
        ]);
        $payload['_razbudise']['featured_image_fallback'] = [
            'strategy' => 'explicit_inline_attachment',
            'post_id' => 2782,
            'attachment_id' => 2795,
            'decision_sha256' => $this->contentMigrationDecisionSha256(),
            'media' => $media,
        ];
        $bundle = $this->writeBundle($this->singlePublishedSections($payload, [$category]));

        $result = app(WordpressContentReconciliationService::class)
            ->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'published_posts:1:featured_image_fallback_evidence_invalid',
            $result['validation_errors'],
        );
        $this->assertSame(0, Article::query()->count());
    }

    public function test_featured_fallback_rejects_wp_image_token_outside_an_actual_matching_image(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $media = [
            ...$this->featuredMedia(2795, 'multitasking.jpg'),
            'post' => 2782,
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
        ];
        $payload = $this->postPayload(2782, 'mutitasking-prednost-ili-besmislica', 'publish', [
            'categories' => [$category],
            'featured_media' => null,
            'content' => [
                'rendered' => '<p class="wp-image-2795">The token is not image evidence.</p><img class="wp-image-9999" src="'.$media['source_url'].'">',
            ],
        ]);
        $payload['_razbudise']['featured_image_fallback'] = [
            'strategy' => 'explicit_inline_attachment',
            'post_id' => 2782,
            'attachment_id' => 2795,
            'decision_sha256' => $this->contentMigrationDecisionSha256(),
            'media' => $media,
        ];
        $bundle = $this->writeBundle($this->singlePublishedSections($payload, [$category]));

        $result = app(WordpressContentReconciliationService::class)
            ->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'published_posts:1:featured_image_fallback_evidence_invalid',
            $result['validation_errors'],
        );
        $this->assertSame(0, Article::query()->count());
    }

    public function test_featured_fallback_promotes_preexisting_inline_asset_without_redownload_or_data_loss(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $media = [
            ...$this->featuredMedia(2795, 'multitasking.jpg'),
            'post' => 2782,
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
        ];
        $payload = $this->postPayload(2782, 'mutitasking-prednost-ili-besmislica', 'publish', [
            'categories' => [$category],
            'featured_media' => null,
            'content' => [
                'rendered' => '<p><img class="wp-image-2795" src="'.$media['source_url'].'" alt="Multitasking"></p>',
            ],
        ]);
        $payload['_razbudise']['featured_image_fallback'] = [
            'strategy' => 'explicit_inline_attachment',
            'post_id' => 2782,
            'attachment_id' => 2795,
            'decision_sha256' => $this->contentMigrationDecisionSha256(),
            'media' => $media,
        ];
        $path = 'wordpress-inline/existing-2795.jpg';
        $bytes = 'existing-inline-image-bytes';
        Storage::disk('public')->put($path, $bytes);
        $existing = MediaAsset::query()->create([
            'disk' => 'public',
            'path' => $path,
            'url' => '/storage/'.$path,
            'mime_type' => 'image/jpeg',
            'alt_text' => 'Original inline alt',
            'width' => 1920,
            'height' => 1278,
            'size_bytes' => strlen($bytes),
            'metadata' => [
                'source' => 'wordpress_inline_image',
                'source_url' => $media['source_url'],
                'responsive_variants' => [
                    'medium' => ['url' => '/storage/wordpress-inline/existing-2795-medium.jpg'],
                ],
            ],
        ]);
        $bundle = $this->writeBundle(
            $this->singlePublishedSections($payload, [$category]),
            cacheFeaturedMedia: false,
        );
        $service = app(WordpressContentReconciliationService::class);

        $applied = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);
        $article = Article::query()->where('old_wordpress_id', 2782)->with('featuredImage')->firstOrFail();
        $promoted = $article->featuredImage;

        $this->assertTrue($applied['reconciliation_complete'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertSame($existing->id, $promoted?->id);
        $this->assertSame($path, $promoted?->path);
        $this->assertSame('/storage/'.$path, $promoted?->url);
        $this->assertSame($bytes, Storage::disk('public')->get($path));
        $this->assertSame('Original inline alt', $promoted?->alt_text);
        $this->assertSame(2795, (int) data_get($promoted?->metadata, 'wordpress_id'));
        $this->assertSame('wordpress_inline_image', data_get($promoted?->metadata, 'source'));
        $this->assertSame('wordpress_attachment', data_get($promoted?->metadata, 'source_identity'));
        $this->assertEqualsCanonicalizing(
            ['wordpress_featured_fallback', 'wordpress_inline_image'],
            data_get($promoted?->metadata, 'sources'),
        );
        $this->assertEqualsCanonicalizing(
            ['featured', 'featured_fallback'],
            data_get($promoted?->metadata, 'roles'),
        );
        $this->assertSame(
            '/storage/wordpress-inline/existing-2795-medium.jpg',
            data_get($promoted?->metadata, 'responsive_variants.medium.url'),
        );
        Http::assertNothingSent();

        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 2782)
            ->firstOrFail();
        $tamperedSource = $record->source_payload;
        data_set($tamperedSource, '_razbudise.featured_image_fallback.attachment_id', 9999);
        $record->forceFill(['source_payload' => $tamperedSource])->save();

        $tampered = $service->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertSame([2782], data_get(
            $tampered,
            'parity_before.issues.source_evidence_mismatch.ids',
        ));
    }

    public function test_shared_attachment_keeps_normal_and_fallback_featured_roles_with_exact_idempotent_parity(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $media = [
            ...$this->featuredMedia(2795, 'multitasking.jpg'),
            'post' => 2782,
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
        ];
        $fallback = $this->postPayload(2782, 'mutitasking-prednost-ili-besmislica', 'publish', [
            'categories' => [$category],
            'featured_media' => null,
            'content' => [
                'rendered' => '<p><img class="aligncenter wp-image-2795" src="'.$media['source_url'].'" alt="Multitasking"></p>',
            ],
        ]);
        $fallback['_razbudise']['featured_image_fallback'] = [
            'strategy' => 'explicit_inline_attachment',
            'post_id' => 2782,
            'attachment_id' => 2795,
            'decision_sha256' => $this->contentMigrationDecisionSha256(),
            'media' => $media,
        ];
        $normal = $this->postPayload(40000, 'shared-normal-featured', 'publish', [
            'categories' => [$category],
            'featured_media' => $media,
        ]);
        $bundle = $this->writeBundle([
            'published_posts' => [$fallback, $normal],
            'nonpublic_posts' => [],
            'auto_drafts' => [],
            'categories' => [$category],
            'tags' => [],
            'authors' => [$this->authorPayload()],
        ]);
        $service = app(WordpressContentReconciliationService::class);

        $applied = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($applied['reconciliation_complete'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($applied['parity_after']['exact']);
        $articles = Article::query()
            ->whereIn('old_wordpress_id', [2782, 40000])
            ->orderBy('old_wordpress_id')
            ->get();
        $this->assertCount(2, $articles);
        $this->assertSame($articles[0]->featured_image_id, $articles[1]->featured_image_id);
        $this->assertSame(1, MediaAsset::query()->count());
        $metadata = $articles[0]->featuredImage?->metadata;
        $this->assertSame('wordpress_attachment', data_get($metadata, 'source_identity'));
        $this->assertEqualsCanonicalizing(
            ['wordpress_featured_fallback', 'wordpress_rest'],
            data_get($metadata, 'sources'),
        );
        $this->assertEqualsCanonicalizing(
            ['featured', 'featured_fallback'],
            data_get($metadata, 'roles'),
        );
        $fallbackRecord = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 2782)
            ->firstOrFail();
        $this->assertSame(2795, (int) data_get(
            $fallbackRecord->source_payload,
            '_razbudise.featured_image_fallback.attachment_id',
        ));
        $this->assertContains('trusted_featured_image_fallback', $fallbackRecord->warnings);

        $second = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($second['reconciliation_complete'], json_encode($second, JSON_UNESCAPED_SLASHES));
        $this->assertSame(0, $second['plan']['total_writes']);
        $this->assertSame(1, MediaAsset::query()->count());
    }

    public function test_direct_import_rejects_unversioned_or_unmapped_featured_fallback_payload(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $media = [
            ...$this->featuredMedia(8888, 'untrusted-fallback.jpg'),
            'post' => 9999,
            'media_type' => 'image',
        ];
        $payload = $this->postPayload(9999, 'untrusted-fallback', 'publish', [
            'categories' => [$category],
            'featured_media' => null,
            'content' => ['rendered' => '<p class="wp-image-8888">Text without an image.</p>'],
        ]);
        $payload['_razbudise']['featured_image_fallback'] = [
            'strategy' => 'explicit_inline_attachment',
            'post_id' => 9999,
            'attachment_id' => 8888,
            'decision_sha256' => $this->contentMigrationDecisionSha256(),
            'media' => $media,
        ];

        $article = app(WordpressRestImporter::class)
            ->importStoredPostPayload($payload, 'https://old.example.test');
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 9999)
            ->firstOrFail();

        $this->assertNull($article->featured_image_id);
        $this->assertNotContains('trusted_featured_image_fallback', $record->warnings);
        $this->assertSame(0, MediaAsset::query()->count());
        Http::assertNothingSent();
    }

    public function test_versioned_10145_blob_decision_preserves_text_captions_and_nine_valid_images_idempotently(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $blobImages = collect(range(1, 13))
            ->map(fn (int $index): string => '<img src="blob:https://old.example.test/'.$index.'" alt="Broken '.$index.'">')
            ->implode('');
        $validImages = collect(range(1, 9))
            ->map(fn (int $index): string => '<img src="/media/valid-'.$index.'.jpg" alt="Valid '.$index.'">')
            ->implode('');
        $sourceBody = '<article><p>Текст пред сликите.</p><figure>'.$blobImages
            .'<figcaption>Овој опис мора да остане.</figcaption></figure><section>'.$validImages
            .'</section><p>Текст по сликите.</p></article>';
        $rawPostContentSha256 = hash('sha256', '<!-- frozen raw WordPress post_content for 10145 -->');
        $decisionJson = json_encode([
            'schema_version' => 1,
            'featured_image_fallbacks' => ['2782' => 2795],
            'blob_image_removals' => [
                '10145' => [
                    'raw_post_content_sha256' => $rawPostContentSha256,
                    'expected_blob_image_count' => 13,
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $decisionPath = tempnam(sys_get_temp_dir(), 'razbudise-content-decisions-');
        $this->assertIsString($decisionPath);
        $this->temporaryFiles[] = $decisionPath;
        file_put_contents($decisionPath, $decisionJson);
        config()->set('services.wordpress.content_migration_decisions_path', $decisionPath);
        $decisionSha256 = hash('sha256', $decisionJson);
        $payload = $this->postPayload(10145, 'validated-blob-cleanup', 'publish', [
            'categories' => [$category],
            'content' => ['rendered' => $sourceBody],
        ]);
        $payload['_razbudise']['blob_image_removal'] = [
            'strategy' => 'remove_nonpersistent_blob_images',
            'post_id' => 10145,
            'raw_post_content_sha256' => $rawPostContentSha256,
            'expected_blob_image_count' => 13,
            'decision_sha256' => $decisionSha256,
        ];
        $bundle = $this->writeBundle($this->singlePublishedSections($payload, [$category]));
        $service = app(WordpressContentReconciliationService::class);

        app(WordpressRestImporter::class)
            ->importStoredPostPayload($payload, 'https://old.example.test');
        $stalePublicRecord = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 10145)
            ->firstOrFail();
        $this->assertContains('unsafe_html_removed', $stalePublicRecord->warnings);
        $this->assertContains('manual_review', $stalePublicRecord->warnings);

        $dryRun = $service->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertTrue($dryRun['safe_to_apply'], json_encode($dryRun, JSON_UNESCAPED_SLASHES));
        $this->assertSame([10145], data_get(
            $dryRun,
            'parity_before.issues.published_import_superseded_frozen_decision_ids.ids',
        ));
        $this->assertSame([], data_get(
            $dryRun,
            'parity_before.issues.published_import_blocking_manual_review_ids.ids',
        ));

        $staleWarnings = $stalePublicRecord->warnings;
        $stalePublicRecord->forceFill([
            'warnings' => [...$staleWarnings, 'unsupported_shortcode'],
        ])->save();
        $blockedByUnrelatedWarning = $service->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertFalse($blockedByUnrelatedWarning['safe_to_apply']);
        $this->assertSame([10145], data_get(
            $blockedByUnrelatedWarning,
            'parity_before.issues.published_import_blocking_manual_review_ids.ids',
        ));
        $this->assertSame([], data_get(
            $blockedByUnrelatedWarning,
            'parity_before.issues.published_import_superseded_frozen_decision_ids.ids',
        ));
        $stalePublicRecord->forceFill(['warnings' => $staleWarnings])->save();

        $applied = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($applied['reconciliation_complete'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($applied['launch_ready']);
        $this->assertSame([10145], data_get(
            $applied,
            'content_review.published.warnings.trusted_blob_image_removed.ids',
        ));
        $article = Article::query()->where('old_wordpress_id', 10145)->firstOrFail();
        $this->assertSame(9, substr_count((string) $article->body_html, '<img'));
        $this->assertStringNotContainsString('blob:', strtolower((string) $article->body_html));
        $this->assertStringContainsString('Текст пред сликите.', (string) $article->body_html);
        $this->assertStringContainsString('Овој опис мора да остане.', (string) $article->body_html);
        $this->assertStringContainsString('Текст по сликите.', (string) $article->body_html);
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 10145)
            ->firstOrFail();
        $this->assertSame($sourceBody, data_get($record->source_payload, 'content.rendered'));
        $this->assertSame(13, (int) data_get(
            $record->source_payload,
            '_razbudise.normalization_counts.trusted_blob_image_removed',
        ));
        $this->assertContains('trusted_blob_image_removed', $record->warnings);
        $this->assertNotContains('manual_review', $record->warnings);
        $this->assertSame('validated_reconciliation_bundle', data_get(
            $record->source_payload,
            '_razbudise.frozen_content_decision_provenance.source',
        ));
        $this->assertSame(10145, data_get(
            $record->source_payload,
            '_razbudise.frozen_content_decision_provenance.post_id',
        ));

        $record->forceFill([
            'status' => 'failed',
            'error_message' => 'Simulated retryable post-apply failure.',
            'imported_at' => null,
        ])->save();
        $article->forceFill(['body_html' => '<p>Corrupt before retry.</p>'])->save();
        $retried = app(WordpressImportRetryService::class)->retry($record->fresh());
        $record->refresh();

        $this->assertStringContainsString('Текст пред сликите.', (string) $retried->body_html);
        $this->assertStringNotContainsString('blob:', strtolower((string) $retried->body_html));
        $this->assertContains('trusted_blob_image_removed', $record->warnings);
        $this->assertNotContains('manual_review', $record->warnings);
        $this->assertSame('imported', $record->status);

        $second = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($second['reconciliation_complete'], json_encode($second, JSON_UNESCAPED_SLASHES));
        $this->assertSame(0, $second['plan']['total_writes']);
        $this->assertSame(1, Article::query()->where('old_wordpress_id', 10145)->count());

        $deliveryVariantPayload = $payload;
        data_set(
            $deliveryVariantPayload,
            'content.rendered',
            str_replace(
                '<img ',
                '<img loading="lazy" fetchpriority="low" decoding="async" ',
                $sourceBody,
            ),
        );
        $deliveryVariantBundle = $this->writeBundle(
            $this->singlePublishedSections($deliveryVariantPayload, [$category]),
        );
        $deliveryVariant = $service->reconcile(
            $deliveryVariantBundle['path'],
            $deliveryVariantBundle['sha256'],
            apply: true,
        );

        $this->assertTrue(
            $deliveryVariant['reconciliation_complete'],
            json_encode($deliveryVariant, JSON_UNESCAPED_SLASHES),
        );
        $this->assertSame([10145], data_get(
            $deliveryVariant,
            'content_review.published.warnings.trusted_blob_image_removed.ids',
        ));
        $this->assertSame(9, substr_count((string) $article->fresh()?->body_html, '<img'));

        $tamperedPayload = $payload;
        data_set(
            $tamperedPayload,
            'content.rendered',
            preg_replace('/<img src="blob:[^"]+" alt="Broken 13">/', '', $sourceBody, 1),
        );
        $tamperedBundle = $this->writeBundle($this->singlePublishedSections($tamperedPayload, [$category]));
        $tampered = $service->reconcile($tamperedBundle['path'], $tamperedBundle['sha256']);

        $this->assertFalse($tampered['valid']);
        $this->assertContains(
            'published_posts:1:blob_image_removal_evidence_invalid',
            $tampered['validation_errors'],
        );

        $tamperedEvidencePayload = $payload;
        data_set(
            $tamperedEvidencePayload,
            '_razbudise.blob_image_removal.raw_post_content_sha256',
            str_repeat('0', 64),
        );
        $tamperedEvidenceBundle = $this->writeBundle(
            $this->singlePublishedSections($tamperedEvidencePayload, [$category]),
        );
        $tamperedEvidence = $service->reconcile(
            $tamperedEvidenceBundle['path'],
            $tamperedEvidenceBundle['sha256'],
        );

        $this->assertFalse($tamperedEvidence['valid']);
        $this->assertContains(
            'published_posts:1:blob_image_removal_evidence_invalid',
            $tamperedEvidence['validation_errors'],
        );

        $signedPayloadDrift = $record->fresh()->source_payload;
        data_set(
            $signedPayloadDrift,
            'content.rendered',
            (string) data_get($signedPayloadDrift, 'content.rendered').'<p>Signed payload drift.</p>',
        );
        $record->forceFill([
            'status' => 'failed',
            'source_payload' => $signedPayloadDrift,
            'error_message' => 'Tampered signed payload.',
            'imported_at' => null,
        ])->save();

        app(WordpressImportRetryService::class)->retry($record->fresh());
        $record->refresh();

        $this->assertNull(data_get(
            $record->source_payload,
            '_razbudise.frozen_content_decision_provenance',
        ));
        $this->assertNull(data_get($record->source_payload, '_razbudise.blob_image_removal'));
        $this->assertNotContains('trusted_blob_image_removed', $record->warnings);
        $this->assertContains('manual_review', $record->warnings);
    }

    public function test_direct_import_keeps_unmapped_future_blob_images_in_manual_review(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $sourceBody = '<figure><img src="blob:https://old.example.test/future"><figcaption>Keep me.</figcaption></figure>';
        $payload = $this->postPayload(10146, 'future-blob', 'publish', [
            'categories' => [$category],
            'content' => ['rendered' => $sourceBody],
        ]);
        $payload['_razbudise']['blob_image_removal'] = [
            'strategy' => 'remove_nonpersistent_blob_images',
            'post_id' => 10146,
            'raw_post_content_sha256' => hash('sha256', $sourceBody),
            'expected_blob_image_count' => 1,
            'decision_sha256' => $this->contentMigrationDecisionSha256(),
        ];

        app(WordpressRestImporter::class)->importStoredPostPayload($payload, 'https://old.example.test');
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 10146)
            ->firstOrFail();

        $this->assertNotContains('trusted_blob_image_removed', $record->warnings);
        $this->assertContains('unsafe_html_removed', $record->warnings);
        $this->assertContains('manual_review', $record->warnings);
        $this->assertSame($sourceBody, data_get($record->source_payload, 'content.rendered'));
        $this->assertSame([], data_get($record->source_payload, '_razbudise.normalization_counts'));
    }

    public function test_public_import_cannot_derive_the_private_raw_10145_blob_evidence(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $sourceBody = '<p>Before.</p>'.collect(range(1, 13))
            ->map(fn (int $index): string => '<img src="blob:https://old.example.test/'.$index.'">')
            ->implode('').'<p>After.</p>';
        $rawPostContentSha256 = hash('sha256', '<!-- frozen raw WordPress post_content for 10145 -->');
        $decisionJson = json_encode([
            'schema_version' => 1,
            'featured_image_fallbacks' => ['2782' => 2795],
            'blob_image_removals' => [
                '10145' => [
                    'raw_post_content_sha256' => $rawPostContentSha256,
                    'expected_blob_image_count' => 13,
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $decisionPath = tempnam(sys_get_temp_dir(), 'razbudise-content-decisions-');
        $this->assertIsString($decisionPath);
        $this->temporaryFiles[] = $decisionPath;
        file_put_contents($decisionPath, $decisionJson);
        config()->set('services.wordpress.content_migration_decisions_path', $decisionPath);
        $payload = $this->postPayload(10145, 'public-blob-decision', 'publish', [
            'categories' => [$category],
            'featured_media' => null,
            'content' => ['rendered' => $sourceBody],
        ]);

        $article = app(WordpressRestImporter::class)
            ->importStoredPostPayload($payload, 'https://old.example.test');
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 10145)
            ->firstOrFail();

        $this->assertStringContainsString('Before.', (string) $article->body_html);
        $this->assertStringContainsString('After.', (string) $article->body_html);
        $this->assertNotContains('trusted_blob_image_removed', $record->warnings);
        $this->assertContains('unsafe_html_removed', $record->warnings);
        $this->assertContains('manual_review', $record->warnings);
        $this->assertSame($sourceBody, data_get($record->source_payload, 'content.rendered'));
        $this->assertNull(data_get($record->source_payload, '_razbudise.blob_image_removal'));
    }

    public function test_retryable_inline_media_warning_can_fail_then_succeed_from_same_frozen_bundle(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $inlineUrl = 'https://old.example.test/wp-content/uploads/inline-retry-body.png';
        $payload = $this->postPayload(320, 'inline-retry', 'publish', [
            'categories' => [$category],
            'content' => ['rendered' => '<figure><iframe class="wp-embed" frameborder="0" src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed" title="Видео" width="560" height="315" allowfullscreen></iframe></figure><p><img src="'.$inlineUrl.'" alt="Inline"></p>'],
        ]);
        $bundle = $this->writeBundle($this->singlePublishedSections($payload, [$category]));

        app(WordpressRestImporter::class)->importStoredPostPayload($payload, 'https://old.example.test');
        $persistedWarning = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 320)
            ->firstOrFail();
        $this->assertContains('inline_image_download_failed', $persistedWarning->warnings);
        $this->assertContains('trusted_iframe_hardened', $persistedWarning->warnings);
        $this->assertContains('manual_review', $persistedWarning->warnings);

        $service = app(WordpressContentReconciliationService::class);
        $dryRun = $service->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertTrue($dryRun['safe_to_apply']);
        $this->assertSame([320], data_get(
            $dryRun,
            'parity_before.issues.published_import_retryable_media_ids.ids',
        ));
        $this->assertSame([320], data_get(
            $dryRun,
            'parity_before.issues.published_import_retryable_inline_media_ids.ids',
        ));
        $this->assertSame([], data_get(
            $dryRun,
            'parity_before.issues.published_import_blocking_manual_review_ids.ids',
        ));
        $this->assertSame([320], data_get(
            $dryRun,
            'parity_before.issues.retryable_media_warning.ids',
        ));

        $this->artisan('wordpress:reconcile-content', [
            'manifest' => $bundle['path'],
            '--expected-sha256' => $bundle['sha256'],
        ])->assertFailed();

        $failed = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertFalse($failed['reconciliation_complete']);
        $this->assertFalse($failed['launch_ready']);
        $this->assertSame(1, $failed['apply']['failed']);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_type' => 'post',
            'wordpress_id' => 320,
            'status' => 'failed',
        ]);

        $path = 'wordpress/inline-retry.png';
        Storage::disk('public')->put($path, 'cached-inline-image');
        $asset = MediaAsset::query()->create([
            'disk' => 'public',
            'path' => $path,
            'url' => '/storage/'.$path,
            'mime_type' => 'image/png',
            'alt_text' => 'Inline',
            'width' => 1,
            'height' => 1,
            'size_bytes' => 19,
            'metadata' => [
                'source' => 'wordpress_inline_image',
                'source_url' => $inlineUrl,
            ],
        ]);

        $this->artisan('wordpress:reconcile-content', [
            'manifest' => $bundle['path'],
            '--expected-sha256' => $bundle['sha256'],
            '--apply' => true,
        ])->assertSuccessful();

        $retried = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $retriedArticle = Article::query()->where('old_wordpress_id', 320)->with('featuredImage')->firstOrFail();
        $this->assertTrue($retried['reconciliation_complete'], json_encode([
            'result' => $retried,
            'featured_image_id' => $retriedArticle->featured_image_id,
            'featured_metadata' => $retriedArticle->featuredImage?->metadata,
        ], JSON_UNESCAPED_SLASHES));
        $this->assertTrue($retried['launch_ready']);
        $this->assertSame(0, $retried['apply']['failed']);
        $this->assertStringContainsString($asset->url, (string) Article::query()
            ->where('old_wordpress_id', 320)
            ->value('body_html'));
        $this->assertSame(['trusted_iframe_hardened'], WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 320)
            ->firstOrFail()
            ->warnings);
    }

    public function test_retryable_featured_media_failure_can_succeed_from_same_frozen_bundle(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $payload = $this->postPayload(330, 'featured-retry', 'publish', [
            'categories' => [$category],
            'content' => ['rendered' => '<blockquote class="instagram-media"><a href="https://www.instagram.com/p/AbCdEf123/">Instagram fallback</a></blockquote><script async src="//www.instagram.com/embed.js"></script><figure><iframe class="wp-embed" frameborder="0" src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed" title="Видео" width="560" height="315" allowfullscreen></iframe></figure>'],
        ]);
        $bundle = $this->writeBundle(
            $this->singlePublishedSections($payload, [$category]),
            cacheFeaturedMedia: false,
        );
        $featuredUrl = (string) data_get($payload, '_embedded.wp:featuredmedia.0.source_url');

        app(WordpressRestImporter::class)->importStoredPostPayload($payload, 'https://old.example.test');
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 330)
            ->firstOrFail();
        $this->assertContains('featured_image_download_failed', $record->warnings);
        $this->assertContains('trusted_embed_script_removed', $record->warnings);
        $this->assertContains('trusted_iframe_hardened', $record->warnings);
        $this->assertContains('manual_review', $record->warnings);
        $this->assertSame('external', Article::query()
            ->where('old_wordpress_id', 330)
            ->firstOrFail()
            ->featuredImage?->disk);

        $service = app(WordpressContentReconciliationService::class);
        $dryRun = $service->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertTrue($dryRun['safe_to_apply']);
        $this->assertSame([330], data_get(
            $dryRun,
            'parity_before.issues.published_import_retryable_media_ids.ids',
        ));
        $this->assertSame([330], data_get(
            $dryRun,
            'parity_before.issues.published_import_retryable_featured_media_ids.ids',
        ));
        $this->assertSame([330], data_get($dryRun, 'parity_before.issues.featured_media_mismatch.ids'));

        $failed = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertFalse($failed['reconciliation_complete']);
        $this->assertFalse($failed['launch_ready']);
        $this->assertSame(1, $failed['apply']['failed']);

        $path = 'wordpress/featured-retry.png';
        Storage::disk('public')->put($path, 'cached-featured-image');
        $featuredAsset = MediaAsset::query()
            ->get()
            ->first(fn (MediaAsset $asset): bool => data_get($asset->metadata, 'source_url') === $featuredUrl);
        $this->assertInstanceOf(MediaAsset::class, $featuredAsset);
        $featuredAsset->forceFill([
            'disk' => 'public',
            'path' => $path,
            'url' => '/storage/'.$path,
            'metadata' => [
                'wordpress_id' => (int) $payload['featured_media'],
                'source' => 'wordpress_rest',
                'source_url' => $featuredUrl,
            ],
        ])->save();

        $retried = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($retried['reconciliation_complete'], json_encode($retried, JSON_UNESCAPED_SLASHES));
        $this->assertTrue($retried['launch_ready']);
        $this->assertSame(0, $retried['apply']['failed']);
        $article = Article::query()->where('old_wordpress_id', 330)->firstOrFail();
        $this->assertSame('public', $article->featuredImage?->disk);
        $this->assertTrue(Storage::disk('public')->exists((string) $article->featuredImage?->path));
        $this->assertSame([
            'trusted_embed_script_removed',
            'trusted_iframe_hardened',
        ], WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 330)
            ->firstOrFail()
            ->warnings);
    }

    public function test_forward_internal_links_and_public_redirects_are_order_independent_and_idempotent(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $second = $this->postPayload(401, 'second-story', 'publish', ['categories' => [$category]]);
        $first = $this->postPayload(400, 'first-story', 'publish', [
            'categories' => [$category],
            'link' => 'https://old.example.test/legacy-first-story/',
            'content' => ['rendered' => '<p><a href="'.$second['link'].'">Next</a></p>'],
        ]);
        $bundle = $this->writeBundle([
            'published_posts' => [$first, $second],
            'nonpublic_posts' => [],
            'auto_drafts' => [],
            'categories' => [$category],
            'tags' => [],
            'authors' => [$this->authorPayload()],
        ]);
        Redirect::query()->create([
            'source_url' => $first['link'],
            'target_url' => 'https://evil.example.test/first-story',
            'status_code' => 301,
            'is_active' => true,
        ]);
        $author = $this->authorPayload();
        Redirect::query()->create([
            'source_url' => $author['old_url'],
            'target_url' => 'http://new.example.test/profil/source-author',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $service = app(WordpressContentReconciliationService::class);
        $dryRun = $service->reconcile($bundle['path'], $bundle['sha256']);

        $this->assertSame([400], $dryRun['plan']['published_posts']['redirect_ids']);
        $this->assertSame([10], $dryRun['plan']['authors']['redirect_ids']);

        $applied = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($applied['reconciliation_complete'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertSame('<p><a href="/second-story">Next</a></p>', Article::query()
            ->where('old_wordpress_id', 400)
            ->value('body_html'));
        $this->assertDatabaseHas(Redirect::class, [
            'source_url' => $first['link'],
            'target_url' => '/first-story',
            'status_code' => 301,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas(Redirect::class, [
            'source_url' => $author['old_url'],
            'target_url' => '/profil/source-author',
            'status_code' => 301,
            'is_active' => true,
        ]);

        Redirect::query()->where('source_url', $first['link'])->update([
            'target_url' => 'https://new.example.test/first-story',
        ]);
        $approvedOrigin = $service->reconcile($bundle['path'], $bundle['sha256']);
        $this->assertSame([], $approvedOrigin['plan']['published_posts']['redirect_ids']);

        $rerun = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);
        $this->assertTrue($rerun['reconciliation_complete']);
        $this->assertSame(0, $rerun['plan']['total_writes']);
        $this->assertSame(0, $rerun['apply']['attempted']);
    }

    public function test_nonpublic_apply_deactivates_current_and_legacy_import_record_urls(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $payload = $this->postPayload(500, 'draft-current', 'draft', [
            'categories' => [$category],
            'link' => 'https://old.example.test/?p=500&preview=true',
        ]);
        $bundle = $this->writeBundle([
            'published_posts' => [],
            'nonpublic_posts' => [['payload' => $payload, 'target_status' => 'draft']],
            'auto_drafts' => [],
            'categories' => [$category],
            'tags' => [],
            'authors' => [$this->authorPayload()],
        ]);
        app(WordpressRestImporter::class)->importStoredPostPayload($payload, 'https://old.example.test');
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 500)
            ->firstOrFail();
        $legacyUrl = 'https://old.example.test/legacy-draft-500/';
        $legacyPayload = $record->source_payload;
        $legacyPayload['link'] = $legacyUrl;
        $record->forceFill(['old_url' => $legacyUrl, 'source_payload' => $legacyPayload])->save();
        Redirect::query()->create([
            'source_url' => $legacyUrl,
            'target_url' => '/draft-current',
            'status_code' => 301,
            'is_active' => true,
        ]);
        Redirect::query()->create([
            'source_url' => $payload['link'],
            'target_url' => '/draft-current',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $service = app(WordpressContentReconciliationService::class);
        $dryRun = $service->reconcile($bundle['path'], $bundle['sha256']);
        $this->assertSame([500], $dryRun['plan']['nonpublic_posts']['redirect_ids']);

        $applied = $service->reconcile($bundle['path'], $bundle['sha256'], apply: true);

        $this->assertTrue($applied['reconciliation_complete'], json_encode($applied, JSON_UNESCAPED_SLASHES));
        $this->assertFalse(Redirect::query()->where('source_url', $legacyUrl)->where('is_active', true)->exists());
        $this->assertFalse(Redirect::query()->where('source_url', $payload['link'])->where('is_active', true)->exists());
        $this->assertNull(WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 500)
            ->value('new_url'));
    }

    public function test_validation_rejects_category_cycles_and_password_protected_posts_without_writes(): void
    {
        $first = $this->categoryPayload(20, 'First', 'first', parent: 21);
        $second = $this->categoryPayload(21, 'Second', 'second', parent: 20);
        $cyclePost = $this->postPayload(510, 'category-cycle', 'publish', ['categories' => [$first]]);
        $cycleBundle = $this->writeBundle($this->singlePublishedSections($cyclePost, [$first, $second]));

        $cycleResult = app(WordpressContentReconciliationService::class)
            ->reconcile($cycleBundle['path'], $cycleBundle['sha256']);

        $this->assertFalse($cycleResult['valid']);
        $this->assertContains('category_parent_cycle_detected', $cycleResult['validation_errors']);

        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $passwordPost = $this->postPayload(511, 'password-post', 'publish', [
            'categories' => [$category],
            'password' => 'secret',
        ]);
        $passwordBundle = $this->writeBundle($this->singlePublishedSections($passwordPost, [$category]));
        $passwordResult = app(WordpressContentReconciliationService::class)
            ->reconcile($passwordBundle['path'], $passwordBundle['sha256']);

        $this->assertFalse($passwordResult['valid']);
        $this->assertContains('published_posts:1:password_protected_post_unsupported', $passwordResult['validation_errors']);

        $safePost = $this->postPayload(512, 'password-manifest', 'publish', ['categories' => [$category]]);
        $manifestBundle = $this->writeBundle($this->singlePublishedSections($safePost, [$category]));
        $manifestDirectory = dirname($manifestBundle['path']);
        chmod($manifestDirectory, 0700);
        chmod($manifestBundle['path'], 0600);
        $metadata = json_decode((string) file_get_contents($manifestBundle['path']), true, flags: JSON_THROW_ON_ERROR);
        $metadata['password_protected_posts'] = [
            'records' => 1,
            'ids_sha256' => hash('sha256', "512\n"),
        ];
        $metadataJson = json_encode(
            $metadata,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
        file_put_contents($manifestBundle['path'], $metadataJson);
        chmod($manifestBundle['path'], 0440);
        chmod($manifestDirectory, 0550);
        $manifestResult = app(WordpressContentReconciliationService::class)->reconcile(
            $manifestBundle['path'],
            hash('sha256', $metadataJson),
        );

        $this->assertFalse($manifestResult['valid']);
        $this->assertContains('password_protected_posts_invalid', $manifestResult['validation_errors']);
        $this->assertSame(0, Article::query()->count());
        $this->assertSame(0, WordpressImportRecord::query()->count());
    }

    public function test_apply_rejects_owner_writable_bundles_and_detects_mode_change_after_load(): void
    {
        $category = $this->categoryPayload(20, 'Култура', 'kultura');
        $payload = $this->postPayload(520, 'immutable-bundle', 'publish', ['categories' => [$category]]);
        $writableDirectoryBundle = $this->writeBundle($this->singlePublishedSections($payload, [$category]));
        chmod(dirname($writableDirectoryBundle['path']), 0750);

        $directoryResult = app(WordpressContentReconciliationService::class)->reconcile(
            $writableDirectoryBundle['path'],
            $writableDirectoryBundle['sha256'],
            apply: true,
        );

        $this->assertFalse($directoryResult['valid']);
        $this->assertContains('bundle_directory_not_immutable', $directoryResult['validation_errors']);
        $this->assertSame(0, Article::query()->count());

        chmod(dirname($writableDirectoryBundle['path']), 0550);
        chmod(dirname($writableDirectoryBundle['path']).'/published-posts.ndjson', 0640);
        $fileResult = app(WordpressContentReconciliationService::class)->reconcile(
            $writableDirectoryBundle['path'],
            $writableDirectoryBundle['sha256'],
            apply: true,
        );

        $this->assertFalse($fileResult['valid']);
        $this->assertContains('published_posts_not_immutable', $fileResult['validation_errors']);
        $this->assertSame(0, Article::query()->count());

        $postLoadBundle = $this->writeBundle($this->singlePublishedSections($payload, [$category]));
        $realService = app(WordpressContentReconciliationService::class);
        $this->assertTrue($realService->reconcile(
            $postLoadBundle['path'],
            $postLoadBundle['sha256'],
            apply: true,
        )['reconciliation_complete']);
        Article::query()->where('old_wordpress_id', 520)->update(['title' => 'Corrupt title']);

        $modeChangingImporter = Mockery::mock(WordpressRestImporter::class);
        $modeChangingImporter->shouldReceive('setFrozenLinkMap')->once();
        $modeChangingImporter->shouldReceive('clearFrozenLinkMap')->once();
        $modeChangingImporter->shouldReceive('materializedPostTitle')
            ->once()
            ->andReturn('Post 520');
        $modeChangingImporter->shouldReceive('importStoredPostPayload')
            ->once()
            ->andReturnUsing(function () use ($postLoadBundle): Article {
                chmod(dirname($postLoadBundle['path']).'/published-posts.ndjson', 0640);

                return Article::query()->where('old_wordpress_id', 520)->firstOrFail();
            });
        $modeChangingService = new WordpressContentReconciliationService(
            $modeChangingImporter,
            app(WordpressContentSanitizer::class),
            app(WordpressFrozenContentDecisionProvenance::class),
            app(WordpressUnavailableMediaManifestService::class),
        );
        $postLoadResult = $modeChangingService->reconcile(
            $postLoadBundle['path'],
            $postLoadBundle['sha256'],
            apply: true,
        );

        $this->assertFalse($postLoadResult['valid']);
        $this->assertContains('published_posts_not_immutable', $postLoadResult['validation_errors']);
        $this->assertSame(1, $postLoadResult['apply']['attempted']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function postPayload(int $id, string $slug, string $status, array $overrides = []): array
    {
        $categories = $overrides['categories'] ?? [$this->categoryPayload(20, 'Култура', 'kultura')];
        $tags = $overrides['tags'] ?? [];
        $featuredMedia = array_key_exists('featured_media', $overrides)
            ? $overrides['featured_media']
            : $this->featuredMedia(100000 + $id, "{$slug}.png");
        $declaredCategoryIds = $overrides['declared_category_ids'] ?? array_column($categories, 'id');
        $declaredTagIds = $overrides['declared_tag_ids'] ?? array_column($tags, 'id');
        unset(
            $overrides['categories'],
            $overrides['tags'],
            $overrides['featured_media'],
            $overrides['declared_category_ids'],
            $overrides['declared_tag_ids'],
        );
        $author = $this->authorPayload();
        $embeddedAuthor = [
            'id' => $author['wordpress_id'],
            'slug' => $author['source_slug'],
            'name' => $author['display_name'],
            'link' => $author['old_url'],
        ];
        $payload = [
            'id' => $id,
            'slug' => $slug,
            'status' => $status,
            'date_gmt' => '2024-05-01T10:00:00',
            'modified_gmt' => '2024-05-02T11:00:00',
            'link' => "https://old.example.test/{$slug}/",
            'title' => ['rendered' => "Post {$id}"],
            'excerpt' => ['rendered' => '<p>Excerpt.</p>'],
            'content' => ['rendered' => '<p>Body.</p>'],
            'author' => 10,
            'categories' => array_values($declaredCategoryIds),
            'tags' => array_values($declaredTagIds),
            'featured_media' => is_array($featuredMedia) ? (int) $featuredMedia['id'] : 0,
            '_embedded' => [
                'author' => [$embeddedAuthor],
                'wp:term' => [array_values($categories), array_values($tags)],
            ],
        ];

        if (is_array($featuredMedia)) {
            $payload['_embedded']['wp:featuredmedia'] = [$featuredMedia];
        }

        return array_replace_recursive($payload, $overrides);
    }

    /** @return array<string, mixed> */
    private function categoryPayload(int $id, string $name, string $slug, int $parent = 0): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'taxonomy' => 'category',
            'description' => "Description {$id}",
            'parent' => $parent,
            'link' => "https://old.example.test/category/{$slug}/",
        ];
    }

    /** @return array<string, mixed> */
    private function tagPayload(int $id, string $name, string $slug): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'taxonomy' => 'post_tag',
            'link' => "https://old.example.test/tag/{$slug}/",
        ];
    }

    /** @return array<string, mixed> */
    private function authorPayload(
        int $id = 10,
        string $slug = 'source-author',
        string $name = 'Source Author',
    ): array {
        return [
            'wordpress_id' => $id,
            'source_slug' => $slug,
            'display_name' => $name,
            'old_url' => "https://old.example.test/author/{$slug}/",
        ];
    }

    /** @return array<string, mixed> */
    private function featuredMedia(int $id, string $filename): array
    {
        return [
            'id' => $id,
            'source_url' => "https://old.example.test/wp-content/uploads/{$filename}",
            'mime_type' => 'image/png',
            'alt_text' => "Image {$id}",
            'caption' => ['rendered' => ''],
            'media_details' => ['width' => 1200, 'height' => 800, 'filesize' => 1000],
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     */
    private function cacheFeaturedMedia(array $sections): void
    {
        $posts = [
            ...($sections['published_posts'] ?? []),
            ...collect($sections['nonpublic_posts'] ?? [])
                ->map(fn (array $row): mixed => $row['payload'] ?? null)
                ->filter(fn (mixed $payload): bool => is_array($payload))
                ->all(),
        ];

        foreach ($posts as $post) {
            $usesFallback = is_array(data_get($post, '_razbudise.featured_image_fallback'));
            $media = $usesFallback
                ? data_get($post, '_razbudise.featured_image_fallback.media')
                : data_get($post, '_embedded.wp:featuredmedia.0');
            $media = is_array($media) ? $media : [];
            $wordpressId = (int) ($media['id'] ?? 0);
            $sourceUrl = (string) ($media['source_url'] ?? '');

            if ($wordpressId < 1 || $sourceUrl === '') {
                continue;
            }

            $path = 'wordpress-test/featured-'.$wordpressId.'-'.substr(hash('sha256', $sourceUrl), 0, 12).'.png';
            Storage::disk('public')->put($path, 'cached-featured-image');
            $asset = MediaAsset::query()->get()->first(
                fn (MediaAsset $candidate): bool => data_get($candidate->metadata, 'source_url') === $sourceUrl,
            );
            $attributes = [
                'disk' => 'public',
                'path' => $path,
                'url' => '/storage/'.$path,
                'mime_type' => (string) ($media['mime_type'] ?? 'image/png'),
                'alt_text' => (string) ($media['alt_text'] ?? ''),
                'width' => (int) data_get($media, 'media_details.width', 1),
                'height' => (int) data_get($media, 'media_details.height', 1),
                'size_bytes' => 21,
                'metadata' => [
                    'wordpress_id' => $wordpressId,
                    'source' => $usesFallback ? 'wordpress_featured_fallback' : 'wordpress_rest',
                    'source_url' => $sourceUrl,
                ],
            ];

            if ($asset instanceof MediaAsset) {
                $asset->forceFill($attributes)->save();
            } else {
                MediaAsset::query()->create($attributes);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $post
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function singlePublishedSections(array $post, array $categories): array
    {
        return [
            'published_posts' => [$post],
            'nonpublic_posts' => [],
            'auto_drafts' => [],
            'categories' => $categories,
            'tags' => [],
            'authors' => [$this->authorPayload()],
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     * @param  array<string, int>|null  $statusCounts
     * @return array{path: string, sha256: string}
     */
    private function writeBundle(
        array $sections,
        ?array $statusCounts = null,
        bool $cacheFeaturedMedia = true,
    ): array {
        if ($cacheFeaturedMedia) {
            $this->cacheFeaturedMedia($sections);
        }

        $directory = storage_path('app/reconciliation-test-'.str_replace('.', '', uniqid('', true)));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        $this->bundleDirectories[] = $directory;
        $files = [];
        $filenames = [
            'published_posts' => 'published-posts.ndjson',
            'nonpublic_posts' => 'nonpublic-posts.ndjson',
            'auto_drafts' => 'auto-drafts.ndjson',
            'categories' => 'categories.ndjson',
            'tags' => 'tags.ndjson',
            'authors' => 'authors.ndjson',
        ];

        foreach ($filenames as $section => $filename) {
            $records = array_values($sections[$section] ?? []);
            $content = collect($records)->map(fn (array $record): string => json_encode(
                $record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ))->implode("\n");
            $content = $content === '' ? '' : $content."\n";
            $path = $directory.'/'.$filename;
            file_put_contents($path, $content);
            chmod($path, 0640);
            $files[$section] = [
                'filename' => $filename,
                'records' => count($records),
                'sha256' => hash('sha256', $content),
            ];
        }

        $statusCounts ??= [
            'publish' => count($sections['published_posts'] ?? []),
            'draft' => count($sections['nonpublic_posts'] ?? []),
            'pending' => 0,
            'future' => 0,
            'private' => 0,
            'auto-draft' => count($sections['auto_drafts'] ?? []),
        ];
        $metadata = [
            'schema_version' => 1,
            'generated_at_utc' => '2026-07-15T12:00:00Z',
            'source_base_url' => 'https://old.example.test',
            'source_fingerprint_sha256' => str_repeat('a', 64),
            'private_post_dispositions' => [
                'records' => (int) ($statusCounts['private'] ?? 0),
                'sha256' => (int) ($statusCounts['private'] ?? 0) > 0 ? str_repeat('b', 64) : null,
            ],
            'password_protected_posts' => [
                'records' => 0,
                'ids_sha256' => hash('sha256', ''),
            ],
            'post_status_counts' => $statusCounts,
            'files' => $files,
        ];
        $featuredImageFallbacks = collect([
            ...($sections['published_posts'] ?? []),
            ...collect($sections['nonpublic_posts'] ?? [])
                ->map(fn (array $row): mixed => $row['payload'] ?? null)
                ->filter(fn (mixed $payload): bool => is_array($payload))
                ->all(),
        ])->filter(fn (array $post): bool => is_array(data_get(
            $post,
            '_razbudise.featured_image_fallback',
        )))->values();
        $blobImageRemovals = collect([
            ...($sections['published_posts'] ?? []),
            ...collect($sections['nonpublic_posts'] ?? [])
                ->map(fn (array $row): mixed => $row['payload'] ?? null)
                ->filter(fn (mixed $payload): bool => is_array($payload))
                ->all(),
        ])->filter(fn (array $post): bool => is_array(data_get(
            $post,
            '_razbudise.blob_image_removal',
        )))->values();

        if ($featuredImageFallbacks->isNotEmpty() || $blobImageRemovals->isNotEmpty()) {
            $decisionHashes = $featuredImageFallbacks
                ->pluck('_razbudise.featured_image_fallback.decision_sha256')
                ->merge($blobImageRemovals->pluck('_razbudise.blob_image_removal.decision_sha256'))
                ->unique()
                ->values();
            $this->assertCount(1, $decisionHashes);
            $metadata['content_migration_decisions'] = [
                'records' => $featuredImageFallbacks->count() + $blobImageRemovals->count(),
                'featured_image_fallbacks' => $featuredImageFallbacks->count(),
                'blob_image_removals' => $blobImageRemovals->count(),
                'sha256' => $decisionHashes->first(),
            ];
        }
        $metadataJson = json_encode(
            $metadata,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
        $metadataPath = $directory.'/metadata.json';
        file_put_contents($metadataPath, $metadataJson);
        chmod($metadataPath, 0440);

        foreach (array_values($filenames) as $filename) {
            chmod($directory.'/'.$filename, 0440);
        }

        chmod($directory, 0550);

        return ['path' => $metadataPath, 'sha256' => hash('sha256', $metadataJson)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $inline
     * @return array{path: string, sha256: string}
     */
    private function writeUnavailableMediaManifest(
        string $reconciliationSha256,
        array $payload,
        array $inline = [],
        bool $includeFeatured = true,
    ): array {
        $directory = storage_path('app/unavailable-media-test-'.str_replace('.', '', uniqid('', true)));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        $this->bundleDirectories[] = $directory;
        $inventoryFilename = 'wordpress-uploads-freeze.inventory';
        $inventoryPath = $directory.'/'.$inventoryFilename;
        $inventory = "2026/01/existing-control.png\t1\t1.0\0";
        file_put_contents($inventoryPath, $inventory);
        chmod($inventoryPath, 0440);
        $featured = data_get($payload, '_embedded.wp:featuredmedia.0');
        $post = [
            'wordpress_id' => (int) $payload['id'],
            'source_payload_sha256' => $this->unavailableMediaSourcePayloadSha256($payload),
        ];

        if ($includeFeatured) {
            $this->assertIsArray($featured);
            $post['featured'] = [
                'attachment_id' => (int) $featured['id'],
                'source_url' => (string) $featured['source_url'],
                'role' => 'featured',
                'treatment' => 'frontend_placeholder',
            ];
        }

        if ($inline !== []) {
            $post['inline'] = $inline;
        }

        $manifest = [
            'schema_version' => 1,
            'reconciliation_sha256' => $reconciliationSha256,
            'source_base_url' => 'https://old.example.test',
            'inventory' => [
                'filename' => $inventoryFilename,
                'sha256' => hash('sha256', $inventory),
                'records' => 1,
            ],
            'approval' => [
                'owner' => 'RazbudiSe content lead',
                'approved_at_utc' => '2026-07-17T12:00:00Z',
                'featured_treatment' => 'frontend_placeholder',
                'inline_treatment' => 'remove_element',
            ],
            'totals' => [
                'posts' => 1,
                'featured' => $includeFeatured ? 1 : 0,
                'inline' => count($inline),
                'inline_occurrences' => collect($inline)
                    ->sum(fn (array $entry): int => (int) $entry['occurrences']),
            ],
            'posts' => [$post],
        ];
        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
        $path = $directory.'/unavailable-media.json';
        file_put_contents($path, $json);
        chmod($path, 0440);
        chmod($directory, 0550);

        return ['path' => $path, 'sha256' => hash('sha256', $json)];
    }

    /** @param array<string, mixed> $payload */
    private function unavailableMediaSourcePayloadSha256(array $payload): string
    {
        unset($payload['_razbudise']);

        return hash('sha256', json_encode(
            $this->sortUnavailableMediaPayload($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function sortUnavailableMediaPayload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortUnavailableMediaPayload($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortUnavailableMediaPayload($item);
        }

        return $value;
    }

    private function contentMigrationDecisionSha256(): string
    {
        $json = file_get_contents(
            dirname(__DIR__, 3).'/deployment/wordpress/content-migration-decisions.json',
        );

        $this->assertIsString($json);

        return hash('sha256', $json);
    }
}
