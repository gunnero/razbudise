<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Redirect;
use App\Models\WordpressImportRecord;
use App\Services\Wordpress\WordpressFrozenContentDecisionProvenance;
use App\Services\Wordpress\WordpressMigrationReadinessService;
use App\Services\Wordpress\WordpressRestImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WordpressMigrationReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_flags_failed_imports_missing_redirects_and_old_body_links_as_blockers(): void
    {
        config(['services.wordpress.base_url' => 'https://old.example.test']);

        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 10,
            'body_html' => '<p><a href="https://old.example.test/wp-content/uploads/old.jpg">old file</a></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 10,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/old-post/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 11,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/failed/',
            'status' => 'failed',
            'warnings' => [],
            'error_message' => 'Missing title',
        ]);

        $summary = app(WordpressMigrationReadinessService::class)->summary();
        $checks = collect($summary['checks'])->keyBy('code');

        $this->assertFalse($summary['ready']);
        $this->assertSame('fail', $checks['failed_wordpress_imports']['status']);
        $this->assertSame('fail', $checks['missing_wordpress_redirects']['status']);
        $this->assertSame('fail', $checks['unresolved_wordpress_links']['status']);
        $this->assertSame(1, $summary['totals']['unresolved_wordpress_links']);
        $this->assertSame('https://old.example.test/wp-content/uploads/old.jpg', $summary['samples']['unresolved_wordpress_links'][0]['source_url']);
    }

    public function test_readiness_can_pass_when_blockers_are_clear_even_with_warnings(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 20,
            'body_html' => '<p>Clean imported story.</p>',
        ]);
        $sourcePayload = [
            'id' => 20,
            'status' => 'publish',
            '_razbudise' => [
                'unavailable_media' => [
                    'manifest_sha256' => str_repeat('b', 64),
                    'featured' => [
                        'attachment_id' => 1020,
                        'source_url' => 'https://old.example.test/wp-content/uploads/20.jpg',
                        'role' => 'featured',
                        'treatment' => 'frontend_placeholder',
                    ],
                ],
                'normalization_counts' => [
                    'trusted_blob_image_removed' => 7,
                    'trusted_divi_shortcode_normalized' => 8,
                    'trusted_empty_icon_removed' => 9,
                    'trusted_featured_image_fallback' => 10,
                    'trusted_instagram_fallback_normalized' => 2,
                    'trusted_malformed_link_normalized' => 11,
                    'trusted_nested_link_normalized' => 12,
                    'trusted_twitter_fallback_normalized' => 3,
                    'trusted_tiktok_fallback_normalized' => 4,
                    'trusted_unavailable_featured_media' => 1,
                    'trusted_wordpress_interactive_normalized' => 5,
                    'trusted_wordpress_media_wrapper_normalized' => 13,
                    'trusted_wordpress_oembed_normalized' => 6,
                ],
            ],
        ];
        $sourcePayload = app(WordpressFrozenContentDecisionProvenance::class)
            ->addReconciliationPayload($sourcePayload, str_repeat('a', 64));
        $warnings = [
            'broken_embed',
            'trusted_blob_image_removed',
            'trusted_divi_shortcode_normalized',
            'trusted_empty_icon_removed',
            'trusted_featured_image_fallback',
            'trusted_instagram_fallback_normalized',
            'trusted_malformed_link_normalized',
            'trusted_nested_link_normalized',
            'trusted_twitter_fallback_normalized',
            'trusted_tiktok_fallback_normalized',
            'trusted_unavailable_featured_media',
            'trusted_wordpress_interactive_normalized',
            'trusted_wordpress_media_wrapper_normalized',
            'trusted_wordpress_oembed_normalized',
        ];
        $sourcePayload['_razbudise']['content_pipeline'] = [
            'version' => WordpressRestImporter::CONTENT_PIPELINE_VERSION,
            'source_body_sha256' => hash('sha256', ''),
            'preview_body_sha256' => hash('sha256', (string) $article->body_html),
            'persisted_body_sha256' => hash('sha256', (string) $article->body_html),
        ];
        $sourcePayload = app(WordpressFrozenContentDecisionProvenance::class)
            ->addMaterialization(
                $sourcePayload,
                (string) $article->body_html,
                $warnings,
                $sourcePayload['_razbudise']['normalization_counts'],
            );

        WordpressImportRecord::query()->create([
            'wordpress_id' => 20,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/clean/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'source_payload' => $sourcePayload,
            'warnings' => $warnings,
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/clean/',
            'target_url' => '/'.$article->slug,
            'status_code' => 301,
            'is_active' => true,
        ]);

        $summary = app(WordpressMigrationReadinessService::class)->summary();

        $this->assertTrue($summary['ready']);
        $checks = collect($summary['checks'])->keyBy('code');
        $this->assertSame('warn', $checks['migration_review_queue']['status']);
        $this->assertSame('warn', $checks['approved_unavailable_media']['status']);
        $this->assertSame('pass', $checks['missing_featured_images']['status']);
        $this->assertSame(0, $summary['totals']['migration_issues']['manual_review']);
        $this->assertSame([
            'trusted_blob_images_removed_articles' => 1,
            'trusted_blob_images_removed_elements' => 7,
            'trusted_divi_shortcodes_normalized_articles' => 1,
            'trusted_divi_shortcodes_normalized_elements' => 8,
            'trusted_embed_scripts_removed_articles' => 0,
            'trusted_embed_scripts_removed_elements' => 0,
            'trusted_empty_icons_removed_articles' => 1,
            'trusted_empty_icons_removed_elements' => 9,
            'trusted_featured_image_fallbacks_articles' => 1,
            'trusted_featured_image_fallbacks_elements' => 10,
            'trusted_unavailable_featured_media_articles' => 1,
            'trusted_unavailable_featured_media_elements' => 1,
            'trusted_unavailable_inline_media_articles' => 0,
            'trusted_unavailable_inline_media_elements' => 0,
            'trusted_iframes_hardened_articles' => 0,
            'trusted_iframes_hardened_elements' => 0,
            'trusted_instagram_fallbacks_normalized_articles' => 1,
            'trusted_instagram_fallbacks_normalized_elements' => 2,
            'trusted_malformed_links_normalized_articles' => 1,
            'trusted_malformed_links_normalized_elements' => 11,
            'trusted_nested_links_normalized_articles' => 1,
            'trusted_nested_links_normalized_elements' => 12,
            'trusted_twitter_fallbacks_normalized_articles' => 1,
            'trusted_twitter_fallbacks_normalized_elements' => 3,
            'trusted_tiktok_fallbacks_normalized_articles' => 1,
            'trusted_tiktok_fallbacks_normalized_elements' => 4,
            'trusted_wordpress_interactive_normalized_articles' => 1,
            'trusted_wordpress_interactive_normalized_elements' => 5,
            'trusted_wordpress_media_wrappers_normalized_articles' => 1,
            'trusted_wordpress_media_wrappers_normalized_elements' => 13,
            'trusted_wordpress_oembed_normalized_articles' => 1,
            'trusted_wordpress_oembed_normalized_elements' => 6,
        ], $summary['totals']['migration_normalizations']);
        $this->assertSame(
            $summary['totals']['migration_normalizations'],
            $summary['totals']['global_migration_normalizations'],
        );
        $this->assertSame([
            'trusted_unavailable_featured_media_articles' => 1,
            'trusted_unavailable_featured_media_elements' => 1,
            'trusted_unavailable_inline_media_articles' => 0,
            'trusted_unavailable_inline_media_elements' => 0,
        ], $summary['totals']['approved_unavailable_media']);

        $article->forceFill(['body_html' => '<p>Database-only projection tamper.</p>'])->save();
        $tamperedSummary = app(WordpressMigrationReadinessService::class)->summary();
        $tamperedChecks = collect($tamperedSummary['checks'])->keyBy('code');

        $this->assertFalse($tamperedSummary['ready']);
        $this->assertSame('fail', $tamperedChecks['invalid_unavailable_media_evidence']['status']);
        $this->assertSame(1, $tamperedChecks['invalid_unavailable_media_evidence']['context']['count']);
        $this->assertSame('fail', $tamperedChecks['missing_featured_images']['status']);
        $this->assertSame([
            'trusted_unavailable_featured_media_articles' => 0,
            'trusted_unavailable_featured_media_elements' => 0,
            'trusted_unavailable_inline_media_articles' => 0,
            'trusted_unavailable_inline_media_elements' => 0,
        ], $tamperedSummary['totals']['approved_unavailable_media']);
    }

    public function test_readiness_normalizations_use_published_source_ids_and_preserve_global_draft_totals(): void
    {
        $now = now();
        $rows = collect(range(1, 1360))->map(function (int $wordpressId) use ($now): array {
            $published = $wordpressId <= 1330;

            return [
                'wordpress_id' => $wordpressId,
                'wordpress_type' => 'post',
                'old_url' => "https://old.example.test/{$wordpressId}/",
                'new_url' => "/{$wordpressId}",
                'status' => 'imported',
                'source_payload' => json_encode([
                    'id' => $wordpressId,
                    'status' => $published ? 'publish' : 'draft',
                    '_razbudise' => [
                        'normalization_counts' => [
                            'trusted_divi_shortcode_normalized' => 1,
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
                'warnings' => json_encode([
                    'trusted_divi_shortcode_normalized',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });

        $rows->chunk(250)->each(
            fn ($chunk) => WordpressImportRecord::query()->insert($chunk->all()),
        );

        $summary = app(WordpressMigrationReadinessService::class)->summary();

        $this->assertSame(
            1330,
            $summary['totals']['migration_normalizations']['trusted_divi_shortcodes_normalized_articles'],
        );
        $this->assertSame(
            1330,
            $summary['totals']['migration_normalizations']['trusted_divi_shortcodes_normalized_elements'],
        );
        $this->assertSame(
            1360,
            $summary['totals']['global_migration_normalizations']['trusted_divi_shortcodes_normalized_articles'],
        );
        $this->assertSame(
            1360,
            $summary['totals']['global_migration_normalizations']['trusted_divi_shortcodes_normalized_elements'],
        );
        $this->assertSame(
            30,
            $summary['totals']['global_migration_normalizations']['trusted_divi_shortcodes_normalized_articles']
                - $summary['totals']['migration_normalizations']['trusted_divi_shortcodes_normalized_articles'],
        );
    }

    public function test_readiness_blocks_an_unexplained_null_featured_image(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 23,
            'body_html' => '<p>Clean imported story without source-media approval.</p>',
            'featured_image_id' => null,
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 23,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/unexplained-featured-gap/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);
        Redirect::query()->create([
            'source_url' => 'https://old.example.test/unexplained-featured-gap/',
            'target_url' => '/'.$article->slug,
            'status_code' => 301,
            'is_active' => true,
        ]);

        $summary = app(WordpressMigrationReadinessService::class)->summary();
        $check = collect($summary['checks'])->keyBy('code')['missing_featured_images'];

        $this->assertFalse($summary['ready']);
        $this->assertSame('fail', $check['status']);
        $this->assertSame(1, $check['context']['count']);
        $this->assertSame(1, $summary['totals']['completion']['missing_featured_images']);
    }

    public function test_readiness_blocks_inline_only_unavailable_media_without_materialization(): void
    {
        $featuredImage = MediaAsset::factory()->create();
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 24,
            'featured_image_id' => $featuredImage->id,
            'body_html' => '<p>Inline policy projection.</p>',
        ]);
        $sourcePayload = [
            'id' => 24,
            '_razbudise' => [
                'unavailable_media' => [
                    'manifest_sha256' => str_repeat('b', 64),
                    'inline' => [[
                        'source_url' => 'https://old.example.test/wp-content/uploads/24-inline.jpg',
                        'occurrences' => 1,
                        'role' => 'inline',
                        'treatment' => 'remove_element',
                    ]],
                ],
                'normalization_counts' => [
                    'trusted_unavailable_inline_media' => 1,
                ],
            ],
        ];
        $sourcePayload = app(WordpressFrozenContentDecisionProvenance::class)
            ->addReconciliationPayload($sourcePayload, str_repeat('a', 64));
        $sourcePayload['_razbudise']['content_pipeline'] = [
            'version' => WordpressRestImporter::CONTENT_PIPELINE_VERSION,
            'source_body_sha256' => hash('sha256', ''),
            'preview_body_sha256' => hash('sha256', (string) $article->body_html),
            'persisted_body_sha256' => hash('sha256', (string) $article->body_html),
        ];

        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 24,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/inline-only/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'source_payload' => $sourcePayload,
            'warnings' => ['trusted_unavailable_inline_media'],
        ]);
        Redirect::query()->create([
            'source_url' => 'https://old.example.test/inline-only/',
            'target_url' => '/'.$article->slug,
            'status_code' => 301,
            'is_active' => true,
        ]);

        $summary = app(WordpressMigrationReadinessService::class)->summary();
        $checks = collect($summary['checks'])->keyBy('code');

        $this->assertFalse($summary['ready']);
        $this->assertSame('fail', $checks['invalid_unavailable_media_evidence']['status']);
        $this->assertSame(1, $checks['invalid_unavailable_media_evidence']['context']['count']);
        $this->assertSame(0, $summary['totals']['approved_unavailable_media']['trusted_unavailable_inline_media_elements']);

        $record->forceFill(['warnings' => []])->save();
        $warningRemoved = app(WordpressMigrationReadinessService::class)->summary();
        $warningRemovedChecks = collect($warningRemoved['checks'])->keyBy('code');

        $this->assertFalse($warningRemoved['ready']);
        $this->assertSame('fail', $warningRemovedChecks['invalid_unavailable_media_evidence']['status']);
        $this->assertSame(1, $warningRemovedChecks['invalid_unavailable_media_evidence']['context']['count']);

        $evidenceRemovedPayload = $record->source_payload;
        unset($evidenceRemovedPayload['_razbudise']['unavailable_media']);
        $record->forceFill([
            'source_payload' => $evidenceRemovedPayload,
            'warnings' => [],
        ])->save();
        $evidenceRemoved = app(WordpressMigrationReadinessService::class)->summary();
        $evidenceRemovedChecks = collect($evidenceRemoved['checks'])->keyBy('code');

        $this->assertFalse($evidenceRemoved['ready']);
        $this->assertSame('fail', $evidenceRemovedChecks['invalid_unavailable_media_evidence']['status']);
        $this->assertSame(1, $evidenceRemovedChecks['invalid_unavailable_media_evidence']['context']['count']);

        $fullySuppressedPayload = $record->source_payload;
        unset($fullySuppressedPayload['_razbudise']);
        $record->forceFill([
            'source_payload' => $fullySuppressedPayload,
            'warnings' => [],
        ])->save();
        $fullySuppressed = app(WordpressMigrationReadinessService::class)->summary();
        $fullySuppressedChecks = collect($fullySuppressed['checks'])->keyBy('code');

        $this->assertFalse($fullySuppressed['ready']);
        $this->assertSame('fail', $fullySuppressedChecks['invalid_reconciliation_materializations']['status']);
        $this->assertSame(1, $fullySuppressedChecks['invalid_reconciliation_materializations']['context']['count']);
    }

    public function test_readiness_includes_old_link_repair_progress_totals(): void
    {
        $cleanArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 21,
            'body_html' => '<p>Clean imported story.</p>',
        ]);
        $dirtyArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 22,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/dirty.jpg"></p>',
        ]);

        foreach ([$cleanArticle, $dirtyArticle] as $article) {
            WordpressImportRecord::query()->create([
                'wordpress_id' => $article->old_wordpress_id,
                'wordpress_type' => 'post',
                'importable_type' => Article::class,
                'importable_id' => $article->id,
                'old_url' => 'https://old.example.test/'.$article->slug.'/',
                'new_url' => '/'.$article->slug,
                'status' => 'imported',
                'warnings' => [],
            ]);
        }
        WordpressImportRecord::query()->where('wordpress_id', 22)->update([
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);

        $summary = app(WordpressMigrationReadinessService::class)->summary();

        $this->assertSame([
            'articles_checked' => 2,
            'articles_with_references' => 1,
            'references_found' => 1,
            'failed_repair_warnings' => 1,
            'shortcodes_remaining' => 0,
            'next_repair_command' => 'php artisan wordpress:repair-old-links --apply --limit=400 --skip-known-failed',
            'cleanup_command' => 'php artisan media:cleanup-quality --apply',
            'export_command' => 'php artisan wordpress:old-link-export',
        ], $summary['totals']['old_link_repair']);
        $this->assertSame('old_link_repair_failed', $summary['samples']['unresolved_wordpress_links'][0]['status']);
        $this->assertSame('https://old.example.test/wp-content/uploads/2026/dirty.jpg', $summary['samples']['unresolved_wordpress_links'][0]['literal']);
    }

    public function test_readiness_includes_local_migration_completion_audit_totals(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 25,
            'body_html' => '<p class="wp-block-paragraph" style="color:red">Needs cleanup.</p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 25,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/needs-cleanup/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/needs-cleanup/',
            'target_url' => '/'.$article->slug,
            'status_code' => 301,
            'is_active' => true,
        ]);

        $summary = app(WordpressMigrationReadinessService::class)->summary();

        $this->assertSame(1, $summary['totals']['completion']['imported_articles']);
        $this->assertSame(1, $summary['totals']['completion']['body_cleanup_candidates']);
        $this->assertSame('php artisan wordpress:migration-completion --json', $summary['totals']['completion']['audit_command']);
        $this->assertSame(
            'php artisan wordpress:migration-completion --sync-taxonomies --refresh-source --generate-redirects --json',
            $summary['totals']['completion']['completion_command'],
        );
        $this->assertSame(25, $summary['samples']['completion_body_cleanup'][0]['wordpress_id']);
    }

    public function test_migration_readiness_command_supports_json_output_and_exit_code(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 30,
            'wordpress_type' => 'post',
            'status' => 'failed',
            'warnings' => [],
            'error_message' => 'Missing title',
        ]);

        $exitCode = Artisan::call('wordpress:migration-readiness', ['--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"ready": false', $output);
        $this->assertStringContainsString('"failed_wordpress_imports"', $output);
        $this->assertSame(0, data_get($payload, 'totals.media.missing_file'));
        $this->assertSame(0, data_get($payload, 'totals.media.missing_variants'));
    }

    public function test_migration_readiness_fails_for_missing_stored_media_files_or_responsive_variants(): void
    {
        Storage::fake('public');

        MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/missing-launch-image.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => 'Launch image',
            'width' => 1200,
            'height' => 630,
            'metadata' => ['credit' => 'RazbudiSe archive'],
        ]);

        $summary = app(WordpressMigrationReadinessService::class)->summary();
        $mediaCheck = collect($summary['checks'])->keyBy('code')['media_quality_issues'];

        $this->assertFalse($summary['ready']);
        $this->assertSame('fail', $mediaCheck['status']);
        $this->assertSame(1, $mediaCheck['context']['missing_file']);
        $this->assertSame(1, $mediaCheck['context']['missing_variants']);
        $this->assertSame(2, $mediaCheck['context']['blocking_missing_files_or_variants']);

        $exitCode = Artisan::call('wordpress:migration-readiness', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ready']);
        $this->assertSame('fail', collect($payload['checks'])->keyBy('code')['media_quality_issues']['status']);
    }

    public function test_migration_readiness_fails_when_imported_author_has_no_legacy_source_url(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 31,
            'wordpress_type' => 'author',
            'old_url' => null,
            'new_url' => '/profil/elena',
            'status' => 'imported',
            'source_payload' => ['id' => 31, 'slug' => 'elena'],
            'warnings' => [],
        ]);

        $summary = app(WordpressMigrationReadinessService::class)->summary();
        $check = collect($summary['checks'])->keyBy('code')['missing_author_legacy_urls'];

        $this->assertFalse($summary['ready']);
        $this->assertSame('fail', $check['status']);
        $this->assertSame(1, $check['context']['count']);
        $this->assertSame(1, $summary['totals']['completion']['missing_author_legacy_urls']);
    }

    public function test_migration_readiness_fails_for_explicit_manual_review_markers_but_not_general_review_queue_warnings(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 32,
            'body_html' => '<p>Requires editorial confirmation.</p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 32,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/manual-review/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['manual_review'],
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/manual-review/',
            'target_url' => '/'.$article->slug,
            'status_code' => 301,
            'is_active' => true,
        ]);

        $summary = app(WordpressMigrationReadinessService::class)->summary();
        $checks = collect($summary['checks'])->keyBy('code');

        $this->assertFalse($summary['ready']);
        $this->assertSame('fail', $checks['manual_review_items']['status']);
        $this->assertSame('warn', $checks['migration_review_queue']['status']);
    }

    public function test_migration_readiness_fails_for_critical_seo_but_keeps_noncritical_seo_as_warning(): void
    {
        Article::factory()->published()->create([
            'old_wordpress_id' => null,
            'old_url' => 'https://old.example.test/unredirected/',
            'excerpt' => 'Article with a critical redirect gap.',
        ]);

        $summary = app(WordpressMigrationReadinessService::class)->summary();
        $check = collect($summary['checks'])->keyBy('code')['public_seo_issues'];

        $this->assertFalse($summary['ready']);
        $this->assertSame('fail', $check['status']);
        $this->assertSame(1, $check['context']['issues_by_severity']['critical']);
    }

    public function test_migration_readiness_fails_for_invalid_or_dangling_taxonomy_import_mappings(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 33,
            'wordpress_type' => 'category',
            'importable_type' => Category::class,
            'importable_id' => 999999,
            'old_url' => 'https://old.example.test/category/dangling/',
            'new_url' => '/category/dangling',
            'status' => 'imported',
            'warnings' => [],
        ]);

        $summary = app(WordpressMigrationReadinessService::class)->summary();
        $check = collect($summary['checks'])->keyBy('code')['invalid_taxonomy_import_records'];

        $this->assertFalse($summary['ready']);
        $this->assertSame('fail', $check['status']);
        $this->assertSame(1, $check['context']['categories']);
        $this->assertSame(0, $check['context']['tags']);
        $this->assertSame(1, $summary['totals']['completion']['invalid_category_import_records']);
        $this->assertSame('dangling_importable_id', $summary['samples']['invalid_category_import_records'][0]['reason']);
    }
}
