<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\WordpressImportRecord;
use App\Services\Wordpress\WordpressFrozenContentDecisionProvenance;
use App\Services\Wordpress\WordpressMigrationReportService;
use App\Services\Wordpress\WordpressRestImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordpressMigrationReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_counts_missing_content_and_manual_review_buckets(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 100,
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 100,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/no-image/',
            'new_url' => '/no-image',
            'status' => 'imported',
            'warnings' => ['missing_featured_image', 'broken_embed', 'manual_review'],
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 101,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/failed/',
            'status' => 'failed',
            'warnings' => [],
            'error_message' => 'Missing title',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 12,
            'wordpress_type' => 'author',
            'status' => 'pending',
            'warnings' => ['unmapped_author'],
        ]);

        $report = app(WordpressMigrationReportService::class)->summary();

        $this->assertSame(2, $report['posts']['total']);
        $this->assertSame(1, $report['posts']['failed']);
        $this->assertSame(1, $report['issues']['missing_featured_images']);
        $this->assertSame(1, $report['issues']['broken_embeds']);
        $this->assertSame(1, $report['issues']['manual_review']);
        $this->assertSame(1, $report['issues']['unmapped_authors']);
        $this->assertSame('Missing title', $report['latest_failures'][0]['error_message']);
    }

    public function test_nonpublic_manual_review_warnings_remain_auditable_without_blocking_readiness(): void
    {
        foreach (['draft', 'review', 'scheduled', 'archived'] as $index => $status) {
            $wordpressId = 110 + $index;
            $article = Article::factory()->create([
                'old_wordpress_id' => $wordpressId,
                'status' => $status,
                'published_at' => $status === 'archived' ? now()->subDay() : null,
                'scheduled_at' => $status === 'scheduled' ? now()->addDay() : null,
            ]);

            WordpressImportRecord::query()->create([
                'wordpress_id' => $wordpressId,
                'wordpress_type' => 'post',
                'importable_type' => Article::class,
                'importable_id' => $article->id,
                'status' => 'imported',
                'warnings' => ['manual_review'],
            ]);
        }

        $futurePublished = Article::factory()->create([
            'old_wordpress_id' => 114,
            'status' => 'published',
            'published_at' => now()->addDay(),
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 114,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $futurePublished->id,
            'status' => 'imported',
            'warnings' => ['manual_review'],
        ]);

        $report = app(WordpressMigrationReportService::class)->summary();

        $this->assertSame(0, $report['issues']['manual_review']);
        $this->assertSame(5, $report['issues']['review_queue']);
        $this->assertCount(5, collect($report['review_items'])->where('issue', 'manual_review'));
    }

    public function test_verified_private_blob_decision_does_not_count_as_unresolved_manual_review(): void
    {
        $decisionJson = file_get_contents(dirname(__DIR__, 3).'/deployment/wordpress/content-migration-decisions.json');
        $this->assertIsString($decisionJson);
        $decisions = json_decode($decisionJson, true, 16, JSON_THROW_ON_ERROR);
        $decision = data_get($decisions, 'blob_image_removals.10145');
        $decisionSha256 = hash('sha256', $decisionJson);
        $body = '<p>Approved private materialization.</p>';
        $warnings = ['manual_review', 'trusted_blob_image_removed'];
        $counts = ['trusted_blob_image_removed' => 13];
        $payload = [
            'id' => 10145,
            '_razbudise' => [
                'blob_image_removal' => [
                    'strategy' => 'remove_nonpersistent_blob_images',
                    'post_id' => 10145,
                    'raw_post_content_sha256' => $decision['raw_post_content_sha256'],
                    'expected_blob_image_count' => 13,
                    'decision_sha256' => $decisionSha256,
                ],
                'normalization_counts' => $counts,
                'content_pipeline' => [
                    'version' => WordpressRestImporter::CONTENT_PIPELINE_VERSION,
                    'source_body_sha256' => hash('sha256', 'source'),
                    'preview_body_sha256' => hash('sha256', $body),
                    'persisted_body_sha256' => hash('sha256', $body),
                ],
            ],
        ];
        $provenance = app(WordpressFrozenContentDecisionProvenance::class);
        $payload = $provenance->add($payload, str_repeat('a', 64), $decisionSha256);
        $payload = $provenance->addReconciliationPayload($payload, str_repeat('b', 64));
        $payload = $provenance->addMaterialization($payload, $body, $warnings, $counts);
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 10145,
            'body_html' => $body,
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 10145,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'status' => 'imported',
            'source_payload' => $payload,
            'warnings' => $warnings,
        ]);

        $report = app(WordpressMigrationReportService::class)->summary();

        $this->assertSame(0, $report['issues']['manual_review']);
        $this->assertNotContains('manual_review', collect($report['review_items'])->pluck('issue'));

        $record = WordpressImportRecord::query()->where('wordpress_id', 10145)->firstOrFail();
        $tamperedPayload = $record->source_payload;
        data_set($tamperedPayload, '_razbudise.blob_image_removal.expected_blob_image_count', 12);
        $record->forceFill(['source_payload' => $tamperedPayload])->save();

        $this->assertSame(1, app(WordpressMigrationReportService::class)->summary()['issues']['manual_review']);
    }

    public function test_report_finds_imported_articles_that_still_need_featured_images(): void
    {
        $author = Author::factory()->create();
        $article = Article::factory()->for($author)->published()->create([
            'old_wordpress_id' => 102,
            'featured_image_id' => null,
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 102,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/imported-no-image/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'source_payload' => ['id' => 102, 'featured_media' => 1102],
            'warnings' => [],
        ]);

        $report = app(WordpressMigrationReportService::class)->summary();

        $this->assertSame(1, $report['issues']['imported_articles_without_featured_images']);
        $this->assertSame($article->slug, $report['review_items'][0]['slug']);
    }

    public function test_report_does_not_block_natural_source_posts_without_featured_media(): void
    {
        $natural = Article::factory()->published()->create([
            'old_wordpress_id' => 103,
            'featured_image_id' => null,
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 103,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $natural->id,
            'status' => 'imported',
            'source_payload' => ['id' => 103, 'featured_media' => 0],
            'warnings' => [],
        ]);

        $report = app(WordpressMigrationReportService::class)->summary();

        $this->assertSame(0, $report['issues']['imported_articles_without_featured_images']);
        $this->assertNotContains(
            'missing_featured_image',
            collect($report['review_items'])->pluck('issue'),
        );

        $record = WordpressImportRecord::query()->where('wordpress_id', 103)->firstOrFail();
        $record->forceFill(['source_payload' => ['id' => 103, 'featured_media' => '0']])->save();

        $this->assertSame(
            1,
            app(WordpressMigrationReportService::class)->summary()['issues']['imported_articles_without_featured_images'],
        );
    }

    public function test_report_builds_review_queue_rows_for_editorial_follow_up(): void
    {
        $articleWithoutAuthor = Article::factory()->published()->create([
            'old_wordpress_id' => 201,
            'author_id' => null,
        ]);

        $articleWithoutCategory = Article::factory()->published()->create([
            'old_wordpress_id' => 202,
            'category_id' => null,
        ]);

        $category = Category::factory()->create();
        $articleWithWarnings = Article::factory()->for($category)->published()->create([
            'old_wordpress_id' => 203,
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 201,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $articleWithoutAuthor->id,
            'status' => 'imported',
            'warnings' => [],
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 202,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $articleWithoutCategory->id,
            'status' => 'imported',
            'warnings' => [],
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 203,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $articleWithWarnings->id,
            'status' => 'imported',
            'warnings' => ['broken_embed', 'manual_review'],
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 204,
            'wordpress_type' => 'post',
            'status' => 'failed',
            'old_url' => 'https://old.example.test/failed-review/',
            'warnings' => [],
            'error_message' => 'Missing title',
        ]);

        $report = app(WordpressMigrationReportService::class)->summary();
        $issues = collect($report['review_items'])->pluck('issue')->all();

        $this->assertContains('missing_author', $issues);
        $this->assertContains('missing_category', $issues);
        $this->assertContains('broken_embed', $issues);
        $this->assertContains('manual_review', $issues);
        $this->assertContains('failed_import', $issues);
        $this->assertSame(5, $report['issues']['review_queue']);
    }

    public function test_report_exports_review_queue_rows_for_editors(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 301,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/failed-export/',
            'status' => 'failed',
            'warnings' => [],
            'error_message' => 'Missing title',
        ]);

        $rows = app(WordpressMigrationReportService::class)->reviewExportRows();

        $this->assertSame([
            'issue' => 'failed_import',
            'wordpress_id' => 301,
            'title' => null,
            'slug' => null,
            'old_url' => 'https://old.example.test/failed-export/',
            'new_url' => null,
            'error_message' => 'Missing title',
            'warnings' => '',
        ], $rows[0]);
    }

    public function test_report_counts_all_review_issues_and_samples_old_unsafe_removals(): void
    {
        $unsafeArticle = Article::factory()->published()->create(['old_wordpress_id' => 400]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 400,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $unsafeArticle->id,
            'status' => 'imported',
            'warnings' => ['unsafe_html_removed', 'manual_review'],
        ]);

        foreach (range(401, 510) as $wordpressId) {
            $article = Article::factory()->published()->create(['old_wordpress_id' => $wordpressId]);

            WordpressImportRecord::query()->create([
                'wordpress_id' => $wordpressId,
                'wordpress_type' => 'post',
                'importable_type' => Article::class,
                'importable_id' => $article->id,
                'status' => 'imported',
                'warnings' => ['broken_embed', 'manual_review'],
            ]);
        }

        $report = app(WordpressMigrationReportService::class)->summary();

        $this->assertSame(111, $report['issues']['manual_review']);
        $this->assertSame(1, $report['issues']['unsafe_html_removed']);
        $this->assertSame(222, $report['issues']['review_queue']);
        $this->assertSame(100, $report['issues']['review_items_sampled']);
        $this->assertContains(400, collect($report['review_items'])
            ->where('issue', 'unsafe_html_removed')
            ->pluck('old_wordpress_id')
            ->all());
    }

    public function test_trusted_embed_normalizations_are_counted_and_exported_without_review_warning(): void
    {
        $article = Article::factory()->published()->create(['old_wordpress_id' => 601]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 601,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/trusted-embed/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'source_payload' => [
                '_razbudise' => [
                    'normalization_counts' => [
                        'trusted_blob_image_removed' => 9,
                        'trusted_divi_shortcode_normalized' => 10,
                        'trusted_embed_script_removed' => 2,
                        'trusted_empty_icon_removed' => 11,
                        'trusted_featured_image_fallback' => 12,
                        'trusted_iframe_hardened' => 3,
                        'trusted_instagram_fallback_normalized' => 4,
                        'trusted_malformed_link_normalized' => 13,
                        'trusted_nested_link_normalized' => 14,
                        'trusted_twitter_fallback_normalized' => 5,
                        'trusted_tiktok_fallback_normalized' => 6,
                        'trusted_wordpress_interactive_normalized' => 7,
                        'trusted_wordpress_media_wrapper_normalized' => 15,
                        'trusted_wordpress_oembed_normalized' => 8,
                    ],
                ],
            ],
            'warnings' => [
                'trusted_blob_image_removed',
                'trusted_divi_shortcode_normalized',
                'trusted_iframe_hardened',
                'trusted_embed_script_removed',
                'trusted_empty_icon_removed',
                'trusted_featured_image_fallback',
                'trusted_instagram_fallback_normalized',
                'trusted_malformed_link_normalized',
                'trusted_nested_link_normalized',
                'trusted_twitter_fallback_normalized',
                'trusted_tiktok_fallback_normalized',
                'trusted_wordpress_interactive_normalized',
                'trusted_wordpress_media_wrapper_normalized',
                'trusted_wordpress_oembed_normalized',
            ],
        ]);

        $report = app(WordpressMigrationReportService::class)->summary();
        $rows = app(WordpressMigrationReportService::class)->normalizationExportRows();

        $this->assertSame(0, $report['issues']['manual_review']);
        $this->assertSame([
            'trusted_blob_images_removed_articles' => 1,
            'trusted_blob_images_removed_elements' => 9,
            'trusted_divi_shortcodes_normalized_articles' => 1,
            'trusted_divi_shortcodes_normalized_elements' => 10,
            'trusted_embed_scripts_removed_articles' => 1,
            'trusted_embed_scripts_removed_elements' => 2,
            'trusted_empty_icons_removed_articles' => 1,
            'trusted_empty_icons_removed_elements' => 11,
            'trusted_featured_image_fallbacks_articles' => 1,
            'trusted_featured_image_fallbacks_elements' => 12,
            'trusted_unavailable_featured_media_articles' => 0,
            'trusted_unavailable_featured_media_elements' => 0,
            'trusted_unavailable_inline_media_articles' => 0,
            'trusted_unavailable_inline_media_elements' => 0,
            'trusted_iframes_hardened_articles' => 1,
            'trusted_iframes_hardened_elements' => 3,
            'trusted_instagram_fallbacks_normalized_articles' => 1,
            'trusted_instagram_fallbacks_normalized_elements' => 4,
            'trusted_malformed_links_normalized_articles' => 1,
            'trusted_malformed_links_normalized_elements' => 13,
            'trusted_nested_links_normalized_articles' => 1,
            'trusted_nested_links_normalized_elements' => 14,
            'trusted_twitter_fallbacks_normalized_articles' => 1,
            'trusted_twitter_fallbacks_normalized_elements' => 5,
            'trusted_tiktok_fallbacks_normalized_articles' => 1,
            'trusted_tiktok_fallbacks_normalized_elements' => 6,
            'trusted_wordpress_interactive_normalized_articles' => 1,
            'trusted_wordpress_interactive_normalized_elements' => 7,
            'trusted_wordpress_media_wrappers_normalized_articles' => 1,
            'trusted_wordpress_media_wrappers_normalized_elements' => 15,
            'trusted_wordpress_oembed_normalized_articles' => 1,
            'trusted_wordpress_oembed_normalized_elements' => 8,
        ], $report['normalizations']);
        $this->assertCount(14, $rows);
        $this->assertEqualsCanonicalizing(
            [
                'trusted_blob_image_removed',
                'trusted_divi_shortcode_normalized',
                'trusted_iframe_hardened',
                'trusted_embed_script_removed',
                'trusted_empty_icon_removed',
                'trusted_featured_image_fallback',
                'trusted_instagram_fallback_normalized',
                'trusted_malformed_link_normalized',
                'trusted_nested_link_normalized',
                'trusted_twitter_fallback_normalized',
                'trusted_tiktok_fallback_normalized',
                'trusted_wordpress_interactive_normalized',
                'trusted_wordpress_media_wrapper_normalized',
                'trusted_wordpress_oembed_normalized',
            ],
            collect($rows)->pluck('normalization')->all(),
        );

        $this->artisan('wordpress:normalization-export')
            ->expectsOutputToContain('normalization,elements,wordpress_id,title,slug,old_url,new_url')
            ->expectsOutputToContain('trusted_iframe_hardened,3,601')
            ->expectsOutputToContain('trusted_instagram_fallback_normalized,4,601')
            ->expectsOutputToContain('trusted_wordpress_oembed_normalized,8,601')
            ->assertSuccessful();
    }

    public function test_report_keeps_trusted_unavailable_media_informational_and_unexplained_null_featured_images_blocking(): void
    {
        $trusted = Article::factory()->published()->create([
            'old_wordpress_id' => 701,
            'featured_image_id' => null,
        ]);
        $unexplained = Article::factory()->published()->create([
            'old_wordpress_id' => 702,
            'featured_image_id' => null,
        ]);
        $mismatched = Article::factory()->published()->create([
            'old_wordpress_id' => 703,
            'featured_image_id' => null,
        ]);
        $spoofed = Article::factory()->published()->create([
            'old_wordpress_id' => 705,
            'featured_image_id' => null,
        ]);
        $signedPayload = function (
            int $wordpressId,
            Article $article,
            bool $withInline = false,
        ): array {
            $payload = [
                'id' => $wordpressId,
                '_razbudise' => [
                    'unavailable_media' => [
                        'manifest_sha256' => str_repeat('b', 64),
                        'featured' => [
                            'attachment_id' => $wordpressId + 1000,
                            'source_url' => "https://old.example.test/wp-content/uploads/{$wordpressId}.jpg",
                            'role' => 'featured',
                            'treatment' => 'frontend_placeholder',
                        ],
                    ],
                    'normalization_counts' => [
                        'trusted_unavailable_featured_media' => 1,
                    ],
                ],
            ];

            if ($withInline) {
                $payload['_razbudise']['unavailable_media']['inline'] = [[
                    'source_url' => "https://old.example.test/wp-content/uploads/{$wordpressId}-inline.jpg",
                    'occurrences' => 3,
                    'role' => 'inline',
                    'treatment' => 'remove_element',
                ]];
                $payload['_razbudise']['normalization_counts']['trusted_unavailable_inline_media'] = 3;
            }

            $warnings = ['trusted_unavailable_featured_media'];

            if ($withInline) {
                $warnings[] = 'trusted_unavailable_inline_media';
            }

            $payload = app(WordpressFrozenContentDecisionProvenance::class)
                ->addReconciliationPayload($payload, str_repeat('a', 64));
            $payload['_razbudise']['content_pipeline'] = [
                'version' => WordpressRestImporter::CONTENT_PIPELINE_VERSION,
                'source_body_sha256' => hash('sha256', ''),
                'preview_body_sha256' => hash('sha256', (string) $article->body_html),
                'persisted_body_sha256' => hash('sha256', (string) $article->body_html),
            ];

            return app(WordpressFrozenContentDecisionProvenance::class)
                ->addMaterialization(
                    $payload,
                    (string) $article->body_html,
                    $warnings,
                    $payload['_razbudise']['normalization_counts'],
                );
        };

        WordpressImportRecord::query()->create([
            'wordpress_id' => 701,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $trusted->id,
            'status' => 'imported',
            'source_payload' => $signedPayload(701, $trusted, withInline: true),
            'warnings' => [
                'trusted_unavailable_featured_media',
                'trusted_unavailable_inline_media',
            ],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 702,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $unexplained->id,
            'status' => 'imported',
            'warnings' => [],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 704,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $mismatched->id,
            'status' => 'imported',
            'source_payload' => $signedPayload(704, $mismatched),
            'warnings' => ['trusted_unavailable_featured_media'],
        ]);
        $spoofedPayload = $signedPayload(705, $spoofed);
        unset($spoofedPayload['_razbudise']['frozen_reconciliation_provenance']);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 705,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $spoofed->id,
            'status' => 'imported',
            'source_payload' => $spoofedPayload,
            'warnings' => ['trusted_unavailable_featured_media'],
        ]);

        $report = app(WordpressMigrationReportService::class)->summary();

        $this->assertSame(3, $report['issues']['imported_articles_without_featured_images']);
        $this->assertSame([
            'trusted_unavailable_featured_media_articles' => 1,
            'trusted_unavailable_featured_media_elements' => 1,
            'trusted_unavailable_inline_media_articles' => 1,
            'trusted_unavailable_inline_media_elements' => 3,
        ], $report['approved_unavailable_media']);
        $this->assertSame(0, $report['issues']['manual_review']);
        $this->assertNotContains(
            $trusted->slug,
            collect($report['review_items'])->pluck('slug')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$unexplained->slug, $mismatched->slug, $spoofed->slug],
            collect($report['review_items'])->where('issue', 'missing_featured_image')->pluck('slug')->all(),
        );

        $trusted->forceFill(['body_html' => '<p>Database-only projection tamper.</p>'])->save();
        $tamperedReport = app(WordpressMigrationReportService::class)->summary();

        $this->assertSame(4, $tamperedReport['issues']['imported_articles_without_featured_images']);
        $this->assertSame(3, $tamperedReport['issues']['invalid_unavailable_media_evidence']);
        $this->assertSame([
            'trusted_unavailable_featured_media_articles' => 0,
            'trusted_unavailable_featured_media_elements' => 0,
            'trusted_unavailable_inline_media_articles' => 0,
            'trusted_unavailable_inline_media_elements' => 0,
        ], $tamperedReport['approved_unavailable_media']);
    }

    public function test_featured_image_download_failures_are_in_the_review_report(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 705,
            'featured_image_id' => null,
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 705,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'status' => 'imported',
            'warnings' => ['featured_image_download_failed'],
        ]);

        $report = app(WordpressMigrationReportService::class)->summary();

        $this->assertSame(1, $report['issues']['featured_image_download_failures']);
        $this->assertContains(
            'featured_image_download_failed',
            collect($report['review_items'])->pluck('issue')->all(),
        );
    }
}
