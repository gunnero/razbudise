<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Redirect;
use App\Models\Tag;
use App\Models\WordpressImportRecord;
use App\Services\Wordpress\WordpressFrozenContentDecisionProvenance;
use App\Services\Wordpress\WordpressMigrationCompletionService;
use App\Services\Wordpress\WordpressRestImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WordpressLegacyEmbedFixtures;
use Tests\TestCase;

class WordpressMigrationCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_audit_compares_source_counts_with_import_records_and_safe_missing_ids(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 1001,
            'slug' => 'existing-local',
            'body_html' => '<p>Clean imported article.</p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 1001,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/source-one/',
            'new_url' => '/existing-local',
            'status' => 'imported',
            'source_payload' => $this->minimalPost(1001, 'source-one'),
            'warnings' => [],
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/source-one/',
            'target_url' => '/existing-local',
            'status_code' => 301,
            'is_active' => true,
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 1002,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/failed-source/',
            'status' => 'failed',
            'source_payload' => $this->minimalPost(1002, 'failed-source'),
            'warnings' => [],
            'error_message' => 'Missing title.',
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(1001, 'source-one'),
                $this->minimalPost(1002, 'failed-source'),
                $this->minimalPost(1003, 'missing-source'),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '3']),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([
                $this->term(501, 'Култура', 'kultura'),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([
                $this->term(601, 'Битола', 'bitola'),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1']),
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->audit('https://old.example.test', perPage: 3);

        $this->assertSame(3, $audit['source']['posts']);
        $this->assertSame(1, $audit['local']['imported_articles']);
        $this->assertSame(1, $audit['local']['post_records']['failed']);
        $this->assertSame(1, $audit['gaps']['missing_import_records']);
        $this->assertSame([1003], $audit['samples']['safe_import_missing_ids']);
        $this->assertSame(1, $audit['gaps']['recorded_not_imported']);
        $this->assertSame(1002, $audit['samples']['recorded_not_imported'][0]['wordpress_id']);
        $this->assertSame(1, $audit['gaps']['slug_changes']);
        $this->assertSame(0, $audit['gaps']['missing_redirects']);
        $this->assertSame(1, $audit['source']['categories']);
        $this->assertSame(1, $audit['source']['tags']);
    }

    public function test_completion_audit_counts_missing_category_tag_and_author_redirects(): void
    {
        foreach ([
            [1101, 'category', 'https://razbudise.mk/kategorija/kultura/', '/category/kultura'],
            [1102, 'tag', 'https://razbudise.mk/tag/stara-oznaka/', '/tag/nova-oznaka'],
            [1103, 'author', 'https://razbudise.mk/author/elena/', '/profil/elena'],
        ] as [$wordpressId, $wordpressType, $oldUrl, $newUrl]) {
            WordpressImportRecord::query()->create([
                'wordpress_id' => $wordpressId,
                'wordpress_type' => $wordpressType,
                'old_url' => $oldUrl,
                'new_url' => $newUrl,
                'status' => 'imported',
                'warnings' => [],
            ]);
        }

        $audit = app(WordpressMigrationCompletionService::class)->audit('');

        $this->assertSame(3, $audit['gaps']['missing_redirects']);
        $this->assertSame(
            ['category', 'tag', 'author'],
            collect($audit['samples']['missing_redirects'])->pluck('wordpress_type')->all(),
        );
    }

    public function test_completion_audit_is_not_ready_when_imported_author_has_no_legacy_source_url(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 1104,
            'wordpress_type' => 'author',
            'old_url' => null,
            'new_url' => '/profil/elena',
            'status' => 'imported',
            'source_payload' => ['id' => 1104, 'slug' => 'elena'],
            'warnings' => [],
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->audit('');

        $this->assertFalse($audit['ready']);
        $this->assertSame(1, $audit['gaps']['missing_author_legacy_urls']);
        $this->assertSame(1, $audit['gaps']['missing_redirects']);
        $this->assertSame('missing_source_url', $audit['samples']['missing_redirects'][0]['reason']);
        $this->assertSame('author', $audit['samples']['missing_redirects'][0]['wordpress_type']);
    }

    public function test_import_missing_posts_skips_existing_and_failed_records_without_duplicates(): void
    {
        Article::factory()->published()->create([
            'old_wordpress_id' => 2001,
            'slug' => 'already-imported',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 2002,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/failed-record/',
            'status' => 'failed',
            'source_payload' => $this->minimalPost(2002, 'failed-record'),
            'warnings' => [],
            'error_message' => 'Original failure.',
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(2001, 'already-imported'),
                $this->minimalPost(2002, 'failed-record'),
                $this->minimalPost(2003, 'new-missing-post'),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1']),
        ]);

        $summary = app(WordpressRestImporter::class)->importMissingPosts('https://old.example.test', perPage: 3);

        $this->assertSame(3, $summary['source_posts']);
        $this->assertSame(1, $summary['skipped_existing']);
        $this->assertSame(1, $summary['skipped_recorded_not_imported']);
        $this->assertSame(1, $summary['imported']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame([2003], $summary['missing_ids']);
        $this->assertSame(1, Article::query()->where('old_wordpress_id', 2001)->count());
        $this->assertDatabaseHas(Article::class, ['old_wordpress_id' => 2003, 'slug' => 'new-missing-post']);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 2002,
            'wordpress_type' => 'post',
            'status' => 'failed',
        ]);
        $this->assertDatabaseMissing(Article::class, ['old_wordpress_id' => 2002]);
    }

    public function test_post_import_records_category_and_tag_import_records(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(3001, 'post-with-recorded-terms', [
                    '_embedded' => [
                        'wp:term' => [
                            [
                                [
                                    'id' => 3101,
                                    'name' => 'Култура',
                                    'slug' => 'kultura',
                                    'taxonomy' => 'category',
                                    'link' => 'https://old.example.test/category/kultura/',
                                ],
                            ],
                            [
                                [
                                    'id' => 3201,
                                    'name' => 'Битола',
                                    'slug' => 'bitola',
                                    'taxonomy' => 'post_tag',
                                    'link' => 'https://old.example.test/tag/bitola/',
                                ],
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $category = Category::query()->where('slug', 'kultura')->firstOrFail();
        $tag = Tag::query()->where('slug', 'bitola')->firstOrFail();

        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 3101,
            'wordpress_type' => 'category',
            'importable_type' => Category::class,
            'importable_id' => $category->id,
            'new_url' => '/category/kultura',
            'status' => 'imported',
        ]);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 3201,
            'wordpress_type' => 'tag',
            'importable_type' => Tag::class,
            'importable_id' => $tag->id,
            'new_url' => '/tag/bitola',
            'status' => 'imported',
        ]);
    }

    public function test_taxonomy_sync_imports_all_categories_and_tags_even_without_posts(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([
                $this->term(4101, 'Култура', 'kultura'),
                $this->term(4102, 'Интервјуа', 'intervjua', ['parent' => 4101]),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '2']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([
                $this->term(4201, 'Битола', 'bitola'),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1']),
        ]);

        $summary = app(WordpressRestImporter::class)->importTaxonomies('https://old.example.test', perPage: 2);

        $parent = Category::query()->where('slug', 'kultura')->firstOrFail();
        $child = Category::query()->where('slug', 'intervjua')->firstOrFail();

        $this->assertSame([
            'categories_imported' => 2,
            'tags_imported' => 1,
            'failed' => 0,
            'total_categories' => 2,
            'total_tags' => 1,
        ], $summary);
        $this->assertTrue($child->parent()->is($parent));
        $this->assertDatabaseHas(Tag::class, ['slug' => 'bitola', 'name' => 'Битола']);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 4201,
            'wordpress_type' => 'tag',
            'status' => 'imported',
        ]);
    }

    public function test_cleanup_imported_article_bodies_removes_remaining_wordpress_markup(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 5001,
            'body_html' => '<p class="wp-block-paragraph" style="color:red">Текст.</p><p>[post_tags]</p><p>&nbsp;</p>'.WordpressLegacyEmbedFixtures::allTrustedNormalizations(),
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 5001,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/cleanup-body/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $summary = app(WordpressMigrationCompletionService::class)->cleanImportedArticleBodies(apply: true);

        $body = $article->fresh()->body_html;

        $this->assertSame(1, $summary['articles_checked']);
        $this->assertSame(1, $summary['cleanup_candidates']);
        $this->assertSame(1, $summary['cleaned_articles']);
        $this->assertStringContainsString('Текст.', $body);
        $this->assertStringNotContainsString('class=', $body);
        $this->assertStringNotContainsString('style=', $body);
        $this->assertStringNotContainsString('[post_tags]', $body);
        $this->assertStringNotContainsString('&nbsp;', $body);
        $this->assertSame([
            'trusted_blob_images_removed_articles' => 0,
            'trusted_blob_images_removed_elements' => 0,
            'trusted_divi_shortcodes_normalized_articles' => 0,
            'trusted_divi_shortcodes_normalized_elements' => 0,
            'trusted_embed_scripts_removed_articles' => 1,
            'trusted_embed_scripts_removed_elements' => 3,
            'trusted_empty_icons_removed_articles' => 0,
            'trusted_empty_icons_removed_elements' => 0,
            'trusted_featured_image_fallbacks_articles' => 0,
            'trusted_featured_image_fallbacks_elements' => 0,
            'trusted_unavailable_featured_media_articles' => 0,
            'trusted_unavailable_featured_media_elements' => 0,
            'trusted_unavailable_inline_media_articles' => 0,
            'trusted_unavailable_inline_media_elements' => 0,
            'trusted_iframes_hardened_articles' => 1,
            'trusted_iframes_hardened_elements' => 1,
            'trusted_instagram_fallbacks_normalized_articles' => 1,
            'trusted_instagram_fallbacks_normalized_elements' => 1,
            'trusted_malformed_links_normalized_articles' => 0,
            'trusted_malformed_links_normalized_elements' => 0,
            'trusted_nested_links_normalized_articles' => 0,
            'trusted_nested_links_normalized_elements' => 0,
            'trusted_twitter_fallbacks_normalized_articles' => 1,
            'trusted_twitter_fallbacks_normalized_elements' => 1,
            'trusted_tiktok_fallbacks_normalized_articles' => 1,
            'trusted_tiktok_fallbacks_normalized_elements' => 1,
            'trusted_wordpress_interactive_normalized_articles' => 1,
            'trusted_wordpress_interactive_normalized_elements' => 2,
            'trusted_wordpress_media_wrappers_normalized_articles' => 0,
            'trusted_wordpress_media_wrappers_normalized_elements' => 0,
            'trusted_wordpress_oembed_normalized_articles' => 1,
            'trusted_wordpress_oembed_normalized_elements' => 1,
        ], $summary['normalizations']);
        $record = WordpressImportRecord::query()->where('wordpress_id', 5001)->firstOrFail();
        $this->assertSame([
            'trusted_embed_script_removed' => 3,
            'trusted_iframe_hardened' => 1,
            'trusted_instagram_fallback_normalized' => 1,
            'trusted_twitter_fallback_normalized' => 1,
            'trusted_tiktok_fallback_normalized' => 1,
            'trusted_wordpress_interactive_normalized' => 2,
            'trusted_wordpress_oembed_normalized' => 1,
        ], data_get($record->source_payload, '_razbudise.normalization_counts'));
        $this->assertNotContains('manual_review', $record->warnings);
    }

    public function test_cleanup_audit_accepts_exact_signed_reconciliation_materialization_and_fails_closed(): void
    {
        $body = '<p>&nbsp;</p><p>Preserved source boundary.</p><p>&nbsp;</p>';
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 1261,
            'body_html' => $body,
        ]);
        $warnings = [];
        $counts = [];
        $payload = [
            'id' => 1261,
            '_razbudise' => [
                'normalization_counts' => $counts,
                'content_pipeline' => [
                    'version' => WordpressRestImporter::CONTENT_PIPELINE_VERSION,
                    'source_body_sha256' => hash('sha256', $body),
                    'preview_body_sha256' => hash('sha256', $body),
                    'persisted_body_sha256' => hash('sha256', $body),
                ],
            ],
        ];
        $provenance = app(WordpressFrozenContentDecisionProvenance::class);
        $payload = $provenance->addReconciliationPayload($payload, str_repeat('a', 64));
        $payload = $provenance->addMaterialization($payload, $body, $warnings, $counts);
        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 1261,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'status' => 'imported',
            'source_payload' => $payload,
            'warnings' => $warnings,
        ]);

        $service = app(WordpressMigrationCompletionService::class);
        $verified = $service->cleanImportedArticleBodies(apply: false);

        $this->assertSame(0, $verified['cleanup_candidates']);
        $this->assertSame($body, $article->fresh()->body_html);

        $tampered = $record->source_payload;
        data_set($tampered, '_razbudise.frozen_materialization_provenance.signature', str_repeat('0', 64));
        $record->forceFill(['source_payload' => $tampered])->save();

        $this->assertSame(1, $service->cleanImportedArticleBodies(apply: false)['cleanup_candidates']);
    }

    public function test_completion_command_outputs_json_report_and_failure_code_for_gaps(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(6001, 'command-missing-post'),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1']),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
        ]);

        $exitCode = Artisan::call('wordpress:migration-completion', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 1,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"posts": 1', $output);
        $this->assertStringContainsString('"missing_import_records": 1', $output);
        $this->assertStringContainsString('"safe_import_missing_ids"', $output);
    }

    public function test_completion_audit_bounds_taxonomy_inventory_when_max_pages_is_set(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(6101, 'bounded-command-post'),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1']),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([
                $this->term(6201, 'Култура', 'kultura'),
            ], 200, ['X-WP-TotalPages' => '3', 'X-WP-Total' => '3']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([
                $this->term(6301, 'Битола', 'bitola'),
            ], 200, ['X-WP-TotalPages' => '3', 'X-WP-Total' => '3']),
        ]);

        Artisan::call('wordpress:migration-completion', [
            'base-url' => 'https://old.example.test',
            '--max-pages' => 1,
            '--per-page' => 1,
            '--json' => true,
        ]);

        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/wp/v2/categories')
            && str_contains($request->url(), '_fields='));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/wp/v2/tags')
            && str_contains($request->url(), '_fields='));
    }

    public function test_local_audit_blocks_a_stored_wordpress_publish_post_that_is_not_public_locally(): void
    {
        $article = Article::factory()->create([
            'old_wordpress_id' => 39880,
            'status' => 'review',
            'published_at' => now()->subYear(),
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 39880,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'status' => 'imported',
            'source_payload' => $this->minimalPost(39880, 'source-published-local-review'),
            'warnings' => [],
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->localAudit();

        $this->assertFalse($audit['ready']);
        $this->assertSame(1, $audit['gaps']['source_publication_mismatches']);
        $this->assertSame(39880, $audit['samples']['source_publication_mismatches'][0]['wordpress_id']);
        $this->assertContains('local_status_not_published', $audit['samples']['source_publication_mismatches'][0]['reasons']);
    }

    public function test_local_audit_blocks_an_unexplained_null_featured_image(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 39881,
            'featured_image_id' => null,
            'body_html' => '<p>Clean body.</p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 39881,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->localAudit();

        $this->assertFalse($audit['ready']);
        $this->assertSame(1, $audit['gaps']['missing_featured_images']);
    }

    public function test_completion_normalization_parity_requires_signed_exact_unavailable_media_evidence(): void
    {
        $sourcePost = $this->minimalPost(39882, 'trusted-unavailable-media', [
            'featured_media' => 49882,
            'content' => [
                'rendered' => '<p>Before.</p>'
                    .'<img src="https://old.example.test/wp-content/uploads/missing-inline.jpg">'
                    .'<img src="https://old.example.test/wp-content/uploads/missing-inline.jpg">'
                    .'<p>After.</p>',
            ],
        ]);
        $article = Article::factory()->create([
            'old_wordpress_id' => 39882,
            'slug' => 'trusted-unavailable-media',
            'status' => 'published',
            'published_at' => '2024-05-01 10:00:00',
            'featured_image_id' => null,
            'body_html' => '<p>Before.</p><p>After.</p>',
        ]);
        $payload = $sourcePost;
        $payload['_razbudise']['unavailable_media'] = [
            'manifest_sha256' => str_repeat('b', 64),
            'featured' => [
                'attachment_id' => 49882,
                'source_url' => 'https://old.example.test/wp-content/uploads/missing-featured.jpg',
                'role' => 'featured',
                'treatment' => 'frontend_placeholder',
            ],
            'inline' => [[
                'source_url' => 'https://old.example.test/wp-content/uploads/missing-inline.jpg',
                'occurrences' => 2,
                'role' => 'inline',
                'treatment' => 'remove_element',
            ]],
        ];
        $payload = app(WordpressFrozenContentDecisionProvenance::class)
            ->addReconciliationPayload($payload, str_repeat('a', 64));
        $payload['_razbudise']['normalization_counts'] = [
            'trusted_unavailable_featured_media' => 1,
            'trusted_unavailable_inline_media' => 2,
        ];
        $warnings = [
            'trusted_unavailable_featured_media',
            'trusted_unavailable_inline_media',
        ];
        $payload['_razbudise']['content_pipeline'] = [
            'version' => WordpressRestImporter::CONTENT_PIPELINE_VERSION,
            'source_body_sha256' => hash('sha256', (string) data_get($sourcePost, 'content.rendered', '')),
            'preview_body_sha256' => hash('sha256', (string) $article->body_html),
            'persisted_body_sha256' => hash('sha256', (string) $article->body_html),
        ];
        $payload = app(WordpressFrozenContentDecisionProvenance::class)
            ->addMaterialization(
                $payload,
                (string) $article->body_html,
                $warnings,
                $payload['_razbudise']['normalization_counts'],
            );
        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 39882,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => $sourcePost['link'],
            'new_url' => '/trusted-unavailable-media',
            'status' => 'imported',
            'source_payload' => $payload,
            'warnings' => $warnings,
        ]);
        Redirect::query()->create([
            'source_url' => $sourcePost['link'],
            'target_url' => '/trusted-unavailable-media',
            'status_code' => 301,
            'is_active' => true,
        ]);
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response(
                [$sourcePost],
                200,
                ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1'],
            ),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
        ]);

        $audit = app(WordpressMigrationCompletionService::class)
            ->audit('https://old.example.test', perPage: 1);

        $this->assertTrue($audit['source']['decision_normalization_evidence']['valid']);
        $this->assertSame(
            [39882],
            $audit['source']['decision_normalization_evidence']['verified_wordpress_ids'],
        );
        $this->assertSame(1, $audit['source']['normalization_expected']['trusted_unavailable_featured_media_articles']);
        $this->assertSame(1, $audit['source']['normalization_expected']['trusted_unavailable_featured_media_elements']);
        $this->assertSame(1, $audit['source']['normalization_expected']['trusted_unavailable_inline_media_articles']);
        $this->assertSame(2, $audit['source']['normalization_expected']['trusted_unavailable_inline_media_elements']);
        $this->assertTrue($audit['source']['normalization_matches_persisted']);
        $this->assertSame(0, $audit['gaps']['missing_featured_images']);
        $this->assertTrue($audit['ready']);

        $article->forceFill(['body_html' => '<p>Database-only projection tamper.</p>'])->save();

        $bodyTamperedAudit = app(WordpressMigrationCompletionService::class)
            ->audit('https://old.example.test', perPage: 1);

        $this->assertFalse($bodyTamperedAudit['source']['decision_normalization_evidence']['valid']);
        $this->assertContains(
            '39882:frozen_materialization_provenance_invalid',
            $bodyTamperedAudit['source']['decision_normalization_evidence']['errors'],
        );
        $this->assertFalse($bodyTamperedAudit['source']['normalization_matches_persisted']);
        $this->assertSame(1, $bodyTamperedAudit['gaps']['invalid_unavailable_media_evidence']);
        $this->assertFalse($bodyTamperedAudit['ready']);

        $article->forceFill(['body_html' => '<p>Before.</p><p>After.</p>'])->save();

        $tampered = $record->source_payload;
        data_set($tampered, '_razbudise.normalization_counts.trusted_unavailable_inline_media', 1);
        $record->forceFill(['source_payload' => $tampered])->save();

        $tamperedAudit = app(WordpressMigrationCompletionService::class)
            ->audit('https://old.example.test', perPage: 1);

        $this->assertFalse($tamperedAudit['source']['decision_normalization_evidence']['valid']);
        $this->assertContains(
            '39882:unavailable_inline_media_count_mismatch',
            $tamperedAudit['source']['decision_normalization_evidence']['errors'],
        );
        $this->assertFalse($tamperedAudit['source']['normalization_matches_persisted']);
        $this->assertFalse($tamperedAudit['ready']);

        data_set($tampered, '_razbudise.normalization_counts.trusted_unavailable_inline_media', 2);
        data_set($tampered, '_razbudise.unavailable_media.manifest_sha256', str_repeat('c', 64));
        $record->forceFill(['source_payload' => $tampered])->save();

        $invalidSignatureAudit = app(WordpressMigrationCompletionService::class)
            ->audit('https://old.example.test', perPage: 1);

        $this->assertFalse($invalidSignatureAudit['source']['decision_normalization_evidence']['valid']);
        $this->assertContains(
            '39882:frozen_reconciliation_provenance_invalid',
            $invalidSignatureAudit['source']['decision_normalization_evidence']['errors'],
        );
        $this->assertFalse($invalidSignatureAudit['ready']);
    }

    public function test_live_audit_blocks_both_publication_directions_and_source_freshness_drift(): void
    {
        $staleArticle = Article::factory()->create([
            'old_wordpress_id' => 7001,
            'status' => 'published',
            'published_at' => '2024-05-01 09:00:00',
        ]);
        $removedSourceArticle = Article::factory()->create([
            'old_wordpress_id' => 7002,
            'status' => 'published',
            'published_at' => '2024-05-01 10:00:00',
        ]);
        $reviewArticle = Article::factory()->create([
            'old_wordpress_id' => 7003,
            'status' => 'review',
            'published_at' => '2024-05-01 10:00:00',
        ]);

        foreach ([$staleArticle, $removedSourceArticle, $reviewArticle] as $article) {
            WordpressImportRecord::query()->create([
                'wordpress_id' => $article->old_wordpress_id,
                'wordpress_type' => 'post',
                'importable_type' => Article::class,
                'importable_id' => $article->id,
                'status' => 'imported',
                'source_payload' => $this->minimalPost((int) $article->old_wordpress_id, $article->slug),
                'warnings' => [],
            ]);
        }

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(7001, $staleArticle->slug, [
                    'modified_gmt' => '2026-07-01T12:00:00',
                ]),
                $this->minimalPost(7003, $reviewArticle->slug),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '2']),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->audit('https://old.example.test', perPage: 2);

        $this->assertFalse($audit['ready']);
        $this->assertSame(2, $audit['gaps']['source_publication_mismatches']);
        $this->assertEqualsCanonicalizing(
            [7002, 7003],
            collect($audit['samples']['source_publication_mismatches'])->pluck('wordpress_id')->all(),
        );
        $this->assertSame(2, $audit['gaps']['source_freshness_mismatches']);
        $freshness = collect($audit['samples']['source_freshness_mismatches'])->keyBy('wordpress_id');
        $this->assertContains('source_modified_since_import', $freshness[7001]['reasons']);
        $this->assertContains('local_published_at_differs_from_source', $freshness[7001]['reasons']);
        $this->assertContains('local_status_differs_from_source', $freshness[7003]['reasons']);
    }

    public function test_live_audit_reports_future_post_schedule_timestamp_drift(): void
    {
        $sourcePost = $this->minimalPost(7050, 'future-source', [
            'status' => 'future',
            'date_gmt' => '2030-05-01T10:00:00',
        ]);
        $article = Article::factory()->create([
            'old_wordpress_id' => 7050,
            'slug' => 'future-source',
            'status' => 'scheduled',
            'published_at' => null,
            'scheduled_at' => '2030-05-01 09:00:00',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 7050,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => $sourcePost['link'],
            'new_url' => '/future-source',
            'status' => 'imported',
            'source_payload' => $sourcePost,
            'warnings' => [],
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response(
                [$sourcePost],
                200,
                ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1'],
            ),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->audit('https://old.example.test', perPage: 1);
        $freshness = collect($audit['samples']['source_freshness_mismatches'])->firstWhere('wordpress_id', 7050);

        $this->assertSame(0, $audit['gaps']['source_publication_mismatches']);
        $this->assertNotNull($freshness);
        $this->assertContains('local_scheduled_at_differs_from_source', $freshness['reasons']);
        $this->assertSame('2030-05-01T09:00:00+00:00', $freshness['local_scheduled_at']);
    }

    public function test_refresh_source_reimports_existing_posts(): void
    {
        $article = Article::factory()->create([
            'old_wordpress_id' => 7101,
            'title' => 'Old local title',
            'status' => 'review',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 7101,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'status' => 'imported',
            'source_payload' => $this->minimalPost(7101, 'refresh-source', ['status' => 'pending']),
            'warnings' => [],
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(7101, 'refresh-source', [
                    'title' => ['rendered' => 'Refreshed source title'],
                    'modified_gmt' => '2026-07-15T12:00:00',
                ]),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1']),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
        ]);

        Artisan::call('wordpress:migration-completion', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 1,
            '--refresh-source' => true,
            '--json' => true,
        ]);

        $this->assertSame('Refreshed source title', $article->fresh()->title);
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame('2026-07-15T12:00:00', data_get($article->wordpressImportRecord()->firstOrFail()->source_payload, 'modified_gmt'));
    }

    public function test_refresh_source_and_import_posts_exit_nonzero_when_an_import_fails(): void
    {

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(7102, 'failed-refresh', [
                    'title' => ['rendered' => ''],
                    'content' => [
                        'rendered' => '<blockquote class="instagram-media"><a href="https://www.instagram.com/p/Failed123/">Fallback</a></blockquote><script async src="//www.instagram.com/embed.js"></script>',
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1']),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
        ]);

        $exitCode = Artisan::call('wordpress:migration-completion', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 1,
            '--refresh-source' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(data_get($payload, 'actions.refresh_source.normalizations_match_preflight'));
        $this->assertContains('action_failed', $payload['failure_reasons']);
        $this->assertContains('refresh_normalization_mismatch', $payload['failure_reasons']);
        $this->assertContains('persisted_normalization_mismatch', $payload['failure_reasons']);
        $this->assertSame(1, Artisan::call('wordpress:import-posts', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 1,
        ]));
    }

    public function test_raw_source_preview_precedes_refresh_and_matches_applied_and_persisted_normalizations(): void
    {
        Storage::fake('public');
        WordpressImportRecord::query()->create([
            'wordpress_id' => 7999,
            'wordpress_type' => 'post',
            'status' => 'imported',
            'source_payload' => [
                '_razbudise' => [
                    'normalization_counts' => ['trusted_embed_script_removed' => 1],
                ],
            ],
            'warnings' => ['trusted_embed_script_removed'],
        ]);
        $posts = [
            $this->minimalPost(7201, 'source-embed-scripts', [
                'content' => [
                    'rendered' => '<p><img src="https://old.example.test/wp-content/uploads/source-preview.png" data-id="5938" fetchpriority="high"></p>'.WordpressLegacyEmbedFixtures::allTrustedNormalizations(),
                ],
            ]),
            $this->minimalPost(7202, 'source-iframe-and-risk', [
                'content' => [
                    'rendered' => '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed"></iframe><script>alert(1)</script>',
                ],
            ]),
        ];
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response($posts, 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '2',
            ]),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-content/uploads/source-preview.png' => Http::response($this->transparentPng(), 200),
        ]);

        $this->assertSame(1, Artisan::call('wordpress:migration-completion', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 100,
            '--json' => true,
        ]));
        $previewPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $preview = data_get($previewPayload, 'audit.source.normalization_preview');

        $this->assertTrue(data_get($previewPayload, 'audit.source.scan_complete'));
        $this->assertSame(2, data_get($previewPayload, 'audit.source.expected_posts'));
        $this->assertSame(2, data_get($previewPayload, 'audit.source.unique_posts'));
        $this->assertSame(1, $preview['trusted_embed_scripts_removed_articles']);
        $this->assertSame(3, $preview['trusted_embed_scripts_removed_elements']);
        $this->assertSame(2, $preview['trusted_iframes_hardened_articles']);
        $this->assertSame(2, $preview['trusted_iframes_hardened_elements']);
        $this->assertSame(1, $preview['trusted_instagram_fallbacks_normalized_articles']);
        $this->assertSame(1, $preview['trusted_instagram_fallbacks_normalized_elements']);
        $this->assertSame(1, $preview['trusted_twitter_fallbacks_normalized_articles']);
        $this->assertSame(1, $preview['trusted_twitter_fallbacks_normalized_elements']);
        $this->assertSame(1, $preview['trusted_tiktok_fallbacks_normalized_articles']);
        $this->assertSame(1, $preview['trusted_tiktok_fallbacks_normalized_elements']);
        $this->assertSame(1, $preview['trusted_wordpress_interactive_normalized_articles']);
        $this->assertSame(2, $preview['trusted_wordpress_interactive_normalized_elements']);
        $this->assertSame(1, $preview['trusted_wordpress_oembed_normalized_articles']);
        $this->assertSame(1, $preview['trusted_wordpress_oembed_normalized_elements']);
        $this->assertSame(1, $preview['manual_review_articles']);
        $this->assertSame(1, $preview['manual_review_articles_by_warning']['unsafe_html_removed']);
        $this->assertSame(0, $preview['manual_review_articles_by_warning']['untrusted_iframe_removed']);

        $this->assertSame(1, Artisan::call('wordpress:migration-completion', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 100,
            '--refresh-source' => true,
            '--json' => true,
        ]));
        $refreshPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($preview, data_get($refreshPayload, 'preflight.source.normalization_preview'));
        $this->assertSame(
            array_intersect_key($preview, array_flip([
                'trusted_blob_images_removed_articles',
                'trusted_blob_images_removed_elements',
                'trusted_divi_shortcodes_normalized_articles',
                'trusted_divi_shortcodes_normalized_elements',
                'trusted_embed_scripts_removed_articles',
                'trusted_embed_scripts_removed_elements',
                'trusted_empty_icons_removed_articles',
                'trusted_empty_icons_removed_elements',
                'trusted_featured_image_fallbacks_articles',
                'trusted_featured_image_fallbacks_elements',
                'trusted_unavailable_featured_media_articles',
                'trusted_unavailable_featured_media_elements',
                'trusted_unavailable_inline_media_articles',
                'trusted_unavailable_inline_media_elements',
                'trusted_iframes_hardened_articles',
                'trusted_iframes_hardened_elements',
                'trusted_instagram_fallbacks_normalized_articles',
                'trusted_instagram_fallbacks_normalized_elements',
                'trusted_malformed_links_normalized_articles',
                'trusted_malformed_links_normalized_elements',
                'trusted_nested_links_normalized_articles',
                'trusted_nested_links_normalized_elements',
                'trusted_twitter_fallbacks_normalized_articles',
                'trusted_twitter_fallbacks_normalized_elements',
                'trusted_tiktok_fallbacks_normalized_articles',
                'trusted_tiktok_fallbacks_normalized_elements',
                'trusted_wordpress_interactive_normalized_articles',
                'trusted_wordpress_interactive_normalized_elements',
                'trusted_wordpress_media_wrappers_normalized_articles',
                'trusted_wordpress_media_wrappers_normalized_elements',
                'trusted_wordpress_oembed_normalized_articles',
                'trusted_wordpress_oembed_normalized_elements',
            ])),
            data_get($refreshPayload, 'actions.refresh_source.normalizations'),
        );
        $this->assertTrue(data_get($refreshPayload, 'actions.refresh_source.normalizations_match_preflight'));
        $this->assertSame(
            data_get($refreshPayload, 'actions.refresh_source.normalizations'),
            data_get($refreshPayload, 'audit.local.persisted_normalizations'),
        );
        $this->assertSame(2, data_get(
            $refreshPayload,
            'audit.local.global_persisted_normalizations.trusted_embed_scripts_removed_articles',
        ));
        $this->assertSame(4, data_get(
            $refreshPayload,
            'audit.local.global_persisted_normalizations.trusted_embed_scripts_removed_elements',
        ));
        $this->assertTrue(data_get($refreshPayload, 'normalization_comparison.persisted_matches_preflight'));
        $refreshedBody = (string) Article::query()->where('old_wordpress_id', 7201)->value('body_html');
        $this->assertStringNotContainsString('data-id=', $refreshedBody);
        $this->assertStringNotContainsString('fetchpriority=', $refreshedBody);
        $this->assertNotContains(
            'manual_review',
            WordpressImportRecord::query()->where('wordpress_id', 7201)->value('warnings'),
        );
        $this->assertSame(0, app(WordpressMigrationCompletionService::class)
            ->cleanImportedArticleBodies(apply: false)['cleanup_candidates']);
        $this->assertSame([
            'trusted_embed_script_removed' => 3,
            'trusted_iframe_hardened' => 1,
            'trusted_instagram_fallback_normalized' => 1,
            'trusted_twitter_fallback_normalized' => 1,
            'trusted_tiktok_fallback_normalized' => 1,
            'trusted_wordpress_interactive_normalized' => 2,
            'trusted_wordpress_oembed_normalized' => 1,
        ], data_get(
            WordpressImportRecord::query()->where('wordpress_id', 7201)->value('source_payload'),
            '_razbudise.normalization_counts',
        ));
    }

    public function test_completion_combines_live_normalizations_with_verified_frozen_decision_evidence(): void
    {
        Storage::fake('public');
        $decisionPath = dirname(__DIR__, 3).'/deployment/wordpress/content-migration-decisions.json';
        $decisionJson = file_get_contents($decisionPath);
        $this->assertIsString($decisionJson);
        $decisions = json_decode($decisionJson, true, 16, JSON_THROW_ON_ERROR);
        $decisionSha256 = hash('sha256', $decisionJson);
        $blobBody = '<p>Before.</p>'.collect(range(1, 13))
            ->map(fn (int $index): string => '<img src="blob:https://old.example.test/'.$index.'">')
            ->implode('').'<p>After.</p>';
        $blobPost = $this->minimalPost(10145, 'verified-blob-decision', [
            'content' => ['rendered' => $blobBody],
        ]);
        $blobPost['_razbudise'] = [
            'blob_image_removal' => [
                'strategy' => 'remove_nonpersistent_blob_images',
                'post_id' => 10145,
                'raw_post_content_sha256' => data_get(
                    $decisions,
                    'blob_image_removals.10145.raw_post_content_sha256',
                ),
                'expected_blob_image_count' => 13,
                'decision_sha256' => $decisionSha256,
            ],
        ];
        $blobPost = app(WordpressFrozenContentDecisionProvenance::class)->add(
            $blobPost,
            str_repeat('a', 64),
            $decisionSha256,
        );
        $fallbackUrl = 'https://old.example.test/wp-content/uploads/fallback-2795.png';
        $fallbackMedia = [
            'id' => 2795,
            'post' => 2782,
            'media_type' => 'image',
            'mime_type' => 'image/png',
            'source_url' => $fallbackUrl,
            'alt_text' => 'Fallback',
            'media_details' => ['width' => 1, 'height' => 1, 'filesize' => 68],
        ];
        $fallbackPost = $this->minimalPost(2782, 'verified-featured-fallback', [
            'featured_media' => 0,
            'content' => [
                'rendered' => '<p><img class="wp-image-2795" src="'.$fallbackUrl.'" alt="Fallback"></p>',
            ],
        ]);
        $fallbackPost['_razbudise'] = [
            'featured_image_fallback' => [
                'strategy' => 'explicit_inline_attachment',
                'post_id' => 2782,
                'attachment_id' => 2795,
                'decision_sha256' => $decisionSha256,
                'media' => $fallbackMedia,
            ],
        ];
        $fallbackPost = app(WordpressFrozenContentDecisionProvenance::class)->add(
            $fallbackPost,
            str_repeat('a', 64),
            $decisionSha256,
        );
        $publicBlobPost = $blobPost;
        unset($publicBlobPost['_razbudise']);
        $publicFallbackPost = $fallbackPost;
        unset($publicFallbackPost['_razbudise']);

        Http::fake([
            $fallbackUrl => Http::response($this->transparentPng(), 200, ['Content-Type' => 'image/png']),
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response(
                [$publicFallbackPost, $publicBlobPost],
                200,
                ['X-WP-TotalPages' => '1', 'X-WP-Total' => '2'],
            ),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '0',
            ]),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '0',
            ]),
        ]);

        $importer = app(WordpressRestImporter::class);
        $importer->importStoredPostPayload(
            $fallbackPost,
            'https://old.example.test',
            trustContentDecisionEvidence: true,
        );
        $importer->importStoredPostPayload(
            $blobPost,
            'https://old.example.test',
            trustContentDecisionEvidence: true,
        );

        $audit = app(WordpressMigrationCompletionService::class)
            ->audit('https://old.example.test', perPage: 100);

        $this->assertSame(0, data_get(
            $audit,
            'source.normalization_preview.trusted_blob_images_removed_elements',
        ));
        $this->assertSame(0, data_get(
            $audit,
            'source.normalization_preview.trusted_featured_image_fallbacks_elements',
        ));
        $this->assertSame(13, data_get(
            $audit,
            'source.normalization_expected.trusted_blob_images_removed_elements',
        ));
        $this->assertSame(1, data_get(
            $audit,
            'source.normalization_expected.trusted_featured_image_fallbacks_elements',
        ));
        $this->assertTrue(data_get($audit, 'source.decision_normalization_evidence.valid'));
        $this->assertSame(
            [2782, 10145],
            data_get($audit, 'source.decision_normalization_evidence.verified_wordpress_ids'),
        );
        $this->assertTrue($audit['source']['normalization_matches_persisted']);
        $this->assertSame(0, $audit['gaps']['source_normalization_mismatches']);

        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 10145)
            ->firstOrFail();
        $tamperedPayload = $record->source_payload;
        data_set(
            $tamperedPayload,
            '_razbudise.frozen_content_decision_provenance.manifest_sha256',
            'invalid',
        );
        $record->forceFill(['source_payload' => $tamperedPayload])->save();

        $tampered = app(WordpressMigrationCompletionService::class)
            ->audit('https://old.example.test', perPage: 100);

        $this->assertFalse(data_get($tampered, 'source.decision_normalization_evidence.valid'));
        $this->assertFalse($tampered['source']['normalization_matches_persisted']);
        $this->assertSame(1, $tampered['gaps']['source_normalization_mismatches']);
    }

    public function test_incomplete_source_scan_blocks_mutation_before_any_import(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => function ($request) {
                $page = (int) ($request->data()['page'] ?? 1);

                return Http::response([
                    $this->minimalPost(7300 + $page, "partial-page-{$page}"),
                ], 200, [
                    'X-WP-TotalPages' => '2',
                    'X-WP-Total' => '2',
                ]);
            },
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
        ]);

        $this->assertSame(1, Artisan::call('wordpress:migration-completion', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 1,
            '--max-pages' => 1,
            '--refresh-source' => true,
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('source_scan_incomplete', $payload['blocked_reason']);
        $this->assertFalse(data_get($payload, 'preflight.source.scan_complete'));
        $this->assertSame(1, data_get($payload, 'preflight.gaps.source_scan_incomplete'));
        $this->assertSame([], $payload['actions']);
        $this->assertSame(0, Article::query()->whereNotNull('old_wordpress_id')->count());
    }

    public function test_incomplete_taxonomy_scan_blocks_mutation_even_when_posts_are_complete(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(7351, 'complete-post-page'),
            ], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '1',
            ]),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([
                $this->term(7352, 'Tag page one', 'tag-page-one'),
            ], 200, ['X-WP-TotalPages' => '2', 'X-WP-Total' => '2']),
        ]);

        $this->assertSame(1, Artisan::call('wordpress:migration-completion', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 1,
            '--max-pages' => 1,
            '--refresh-source' => true,
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue(data_get($payload, 'preflight.source.post_scan_complete'));
        $this->assertFalse(data_get($payload, 'preflight.source.taxonomy_scan_complete'));
        $this->assertFalse(data_get($payload, 'preflight.source.scan_complete'));
        $this->assertSame('source_scan_incomplete', $payload['blocked_reason']);
        $this->assertSame([], $payload['actions']);
        $this->assertSame(0, Article::query()->whereNotNull('old_wordpress_id')->count());
    }

    public function test_taxonomy_scan_rejects_duplicate_ids_even_when_collected_count_matches_source_total(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '0',
            ]),
            'https://old.example.test/wp-json/wp/v2/categories*' => function ($request) {
                $page = (int) ($request->data()['page'] ?? 1);

                return Http::response([
                    $this->term(7601, "Duplicate category page {$page}", 'duplicate-category'),
                ], 200, [
                    'X-WP-TotalPages' => '2',
                    'X-WP-Total' => '2',
                ]);
            },
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '0',
            ]),
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->audit('https://old.example.test');

        $this->assertFalse($audit['source']['taxonomy_scan_complete']);
        $this->assertSame(2, $audit['source']['categories']);
        $this->assertSame(2, $audit['source']['expected_categories']);
        $this->assertSame(1, $audit['source']['unique_categories']);
        $this->assertTrue($audit['source']['category_total_header_present']);
        $this->assertTrue($audit['source']['category_pagination_metadata_consistent']);
        $this->assertFalse($audit['source']['category_cardinality_consistent']);
    }

    public function test_taxonomy_scan_rejects_truncated_term_payload_against_source_total(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '0',
            ]),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([
                $this->term(7611, 'Only returned category', 'only-returned-category'),
            ], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '2',
            ]),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '0',
            ]),
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->audit('https://old.example.test');

        $this->assertFalse($audit['source']['taxonomy_scan_complete']);
        $this->assertSame(1, $audit['source']['categories']);
        $this->assertSame(2, $audit['source']['expected_categories']);
        $this->assertSame(1, $audit['source']['unique_categories']);
        $this->assertFalse($audit['source']['category_cardinality_consistent']);
    }

    public function test_taxonomy_scan_rejects_missing_total_header_and_exposes_metadata_gap(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '0',
            ]),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([
                $this->term(7621, 'Headerless category', 'headerless-category'),
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '0',
            ]),
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->audit('https://old.example.test');

        $this->assertFalse($audit['source']['taxonomy_scan_complete']);
        $this->assertNull($audit['source']['expected_categories']);
        $this->assertSame(1, $audit['source']['unique_categories']);
        $this->assertFalse($audit['source']['category_total_header_present']);
        $this->assertTrue($audit['source']['category_total_pages_header_present']);
        $this->assertFalse($audit['source']['category_cardinality_consistent']);
    }

    public function test_taxonomy_parity_blocks_reverse_extras_wrong_types_and_dangling_local_mappings(): void
    {
        $extraCategory = Category::factory()->create(['slug' => 'local-extra-category']);
        $wrongCategoryTarget = Tag::query()->create(['name' => 'Wrong category target', 'slug' => 'wrong-category-target']);
        $extraTag = Tag::query()->create(['name' => 'Local extra tag', 'slug' => 'local-extra-tag']);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 7701,
            'wordpress_type' => 'category',
            'importable_type' => Tag::class,
            'importable_id' => $wrongCategoryTarget->id,
            'old_url' => 'https://old.example.test/category/source-category/',
            'new_url' => '/category/source-category',
            'status' => 'imported',
            'warnings' => [],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 7791,
            'wordpress_type' => 'category',
            'importable_type' => Category::class,
            'importable_id' => $extraCategory->id,
            'old_url' => 'https://old.example.test/category/local-extra-category/',
            'new_url' => '/category/local-extra-category',
            'status' => 'imported',
            'warnings' => [],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 7702,
            'wordpress_type' => 'tag',
            'importable_type' => Tag::class,
            'importable_id' => 999999,
            'old_url' => 'https://old.example.test/tag/source-tag/',
            'new_url' => '/tag/source-tag',
            'status' => 'imported',
            'warnings' => [],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 7792,
            'wordpress_type' => 'tag',
            'importable_type' => Tag::class,
            'importable_id' => $extraTag->id,
            'old_url' => 'https://old.example.test/tag/local-extra-tag/',
            'new_url' => '/tag/local-extra-tag',
            'status' => 'imported',
            'warnings' => [],
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '0',
            ]),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([
                $this->term(7701, 'Source category', 'source-category'),
            ], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '1',
            ]),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([
                $this->term(7702, 'Source tag', 'source-tag'),
            ], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '1',
            ]),
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->audit('https://old.example.test');

        $this->assertTrue($audit['source']['taxonomy_scan_complete']);
        $this->assertFalse($audit['ready']);
        $this->assertSame(1, $audit['gaps']['source_categories_without_records']);
        $this->assertSame(1, $audit['gaps']['source_tags_without_records']);
        $this->assertSame(1, $audit['gaps']['category_records_without_source']);
        $this->assertSame(1, $audit['gaps']['tag_records_without_source']);
        $this->assertSame(1, $audit['gaps']['invalid_category_import_records']);
        $this->assertSame(1, $audit['gaps']['invalid_tag_import_records']);
        $this->assertSame([7791], $audit['samples']['category_records_without_source']);
        $this->assertSame([7792], $audit['samples']['tag_records_without_source']);
        $this->assertSame('wrong_importable_type', $audit['samples']['invalid_category_import_records'][0]['reason']);
        $this->assertSame('dangling_importable_id', $audit['samples']['invalid_tag_import_records'][0]['reason']);
    }

    public function test_taxonomy_parity_rejects_category_mapping_to_inactive_public_target(): void
    {
        $inactiveCategory = Category::factory()->create([
            'slug' => 'inactive-source-category',
            'is_active' => false,
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 7711,
            'wordpress_type' => 'category',
            'importable_type' => Category::class,
            'importable_id' => $inactiveCategory->id,
            'old_url' => 'https://old.example.test/category/inactive-source-category/',
            'new_url' => '/category/inactive-source-category',
            'status' => 'imported',
            'warnings' => [],
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '0',
            ]),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([
                $this->term(7711, 'Inactive source category', 'inactive-source-category'),
            ], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '1',
            ]),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '0',
            ]),
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->audit('https://old.example.test');

        $this->assertFalse($audit['ready']);
        $this->assertSame(1, $audit['gaps']['source_categories_without_records']);
        $this->assertSame(1, $audit['gaps']['invalid_category_import_records']);
        $this->assertSame('inactive_category', $audit['samples']['invalid_category_import_records'][0]['reason']);
    }

    public function test_source_backed_readiness_blocks_imported_article_without_author(): void
    {
        $post = $this->minimalPost(7401, 'authorless-source');
        $article = Article::factory()->create([
            'old_wordpress_id' => 7401,
            'slug' => 'authorless-source',
            'status' => 'published',
            'published_at' => '2024-05-01 10:00:00',
            'author_id' => null,
            'body_html' => '<p>Body.</p>',
            'old_url' => $post['link'],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 7401,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => $post['link'],
            'new_url' => '/authorless-source',
            'status' => 'imported',
            'source_payload' => $post,
            'warnings' => [],
        ]);
        Redirect::query()->create([
            'source_url' => $post['link'],
            'target_url' => '/authorless-source',
            'status_code' => 301,
            'is_active' => true,
        ]);
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([$post], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '1',
            ]),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
        ]);

        $audit = app(WordpressMigrationCompletionService::class)->audit('https://old.example.test');

        $this->assertTrue($audit['source']['scan_complete']);
        $this->assertSame(1, $audit['gaps']['imported_articles_without_author']);
        $this->assertFalse($audit['ready']);
    }

    public function test_no_action_audit_fails_when_source_normalizations_are_not_persisted(): void
    {
        $rawEmbed = '<blockquote class="twitter-tweet" data-dnt="true" data-width="500"><a href="https://twitter.com/example/status/123456789">Tweet</a></blockquote>';
        $post = $this->minimalPost(7451, 'normalization-not-persisted', [
            'content' => ['rendered' => $rawEmbed],
        ]);
        $author = Author::factory()->create();
        $article = Article::factory()->create([
            'old_wordpress_id' => 7451,
            'slug' => 'normalization-not-persisted',
            'status' => 'published',
            'published_at' => '2024-05-01 10:00:00',
            'author_id' => $author->id,
            'body_html' => '<blockquote><a href="https://www.instagram.com/p/Pending123/">Fallback</a></blockquote>',
            'old_url' => $post['link'],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 7451,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => $post['link'],
            'new_url' => '/normalization-not-persisted',
            'status' => 'imported',
            'source_payload' => $post,
            'warnings' => [],
        ]);
        Redirect::query()->create([
            'source_url' => $post['link'],
            'target_url' => '/normalization-not-persisted',
            'status_code' => 301,
            'is_active' => true,
        ]);
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([$post], 200, [
                'X-WP-TotalPages' => '1',
                'X-WP-Total' => '1',
            ]),
            'https://old.example.test/wp-json/wp/v2/categories*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
            'https://old.example.test/wp-json/wp/v2/tags*' => Http::response([], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '0']),
        ]);

        $this->assertSame(1, Artisan::call('wordpress:migration-completion', [
            'base-url' => 'https://old.example.test',
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue(data_get($payload, 'audit.source.scan_complete'));
        $this->assertFalse(data_get($payload, 'audit.source.normalization_matches_persisted'));
        $this->assertSame(1, data_get($payload, 'audit.gaps.source_normalization_mismatches'));
        $this->assertFalse(data_get($payload, 'audit.ready'));
        $this->assertFalse(data_get($payload, 'normalization_comparison.persisted_matches_preflight'));
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalPost(int $id, string $slug, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'slug' => $slug,
            'status' => 'publish',
            'date_gmt' => '2024-05-01T10:00:00',
            'modified_gmt' => '2024-05-02T11:00:00',
            'link' => "https://old.example.test/{$slug}/",
            'title' => ['rendered' => "Post {$id}"],
            'excerpt' => ['rendered' => '<p>Excerpt.</p>'],
            'content' => ['rendered' => '<p>Body.</p>'],
            '_embedded' => [],
        ], $overrides);
    }

    private function transparentPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAF/gL+Xjz1WQAAAABJRU5ErkJggg==', true) ?: '';
    }

    /**
     * @return array<string, mixed>
     */
    private function term(int $id, string $name, string $slug, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'description' => '',
            'parent' => 0,
            'link' => "https://old.example.test/{$slug}/",
        ], $overrides);
    }
}
