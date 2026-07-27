<?php

namespace Tests\Feature;

use App\Jobs\ImportWordpressPostsJob;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Redirect;
use App\Models\SeoMetadata;
use App\Models\Tag;
use App\Models\WordpressImportMapping;
use App\Models\WordpressImportRecord;
use App\Services\Wordpress\WordpressContentSanitizer;
use App\Services\Wordpress\WordpressFrozenContentDecisionProvenance;
use App\Services\Wordpress\WordpressImportRetryService;
use App\Services\Wordpress\WordpressMigrationReportService;
use App\Services\Wordpress\WordpressRestImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WordpressLegacyEmbedFixtures;
use Tests\TestCase;

class WordpressRestImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_preserves_future_post_as_scheduled_with_wordpress_date(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(122, 'zakazana-objava', [
                    'status' => 'future',
                    'date_gmt' => '2030-05-01T10:00:00',
                    'modified_gmt' => '2026-07-15T08:00:00',
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 122)->firstOrFail();

        $this->assertSame('scheduled', $article->status);
        $this->assertNull($article->published_at);
        $this->assertSame('2030-05-01 10:00:00', $article->scheduled_at?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_importer_preserves_wordpress_post_data_and_cleans_content(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                [
                    'id' => 123,
                    'slug' => 'stara-objava',
                    'status' => 'publish',
                    'date_gmt' => '2024-05-01T10:00:00',
                    'modified_gmt' => '2024-05-02T11:00:00',
                    'link' => 'https://old.example.test/stara-objava/',
                    'title' => ['rendered' => 'Стара објава'],
                    'excerpt' => ['rendered' => '<p>Краток опис.</p>'],
                    'content' => [
                        'rendered' => '<p class="wp-block-paragraph" style="color:red">Текст.</p><p>[post_tags]</p><p>&nbsp;</p>',
                    ],
                    'author' => 7,
                    'categories' => [11],
                    'tags' => [21],
                    'featured_media' => 31,
                    'yoast_head_json' => [
                        'title' => 'SEO стара објава',
                        'description' => 'SEO опис.',
                        'og_title' => 'OG стара објава',
                        'og_description' => 'OG опис.',
                    ],
                    '_embedded' => [
                        'author' => [
                            [
                                'id' => 7,
                                'name' => 'WP Автор',
                                'slug' => 'wp-avtor',
                                'description' => 'Автор од WordPress.',
                            ],
                        ],
                        'wp:term' => [
                            [
                                [
                                    'id' => 11,
                                    'name' => 'Вести',
                                    'slug' => 'vesti',
                                    'taxonomy' => 'category',
                                    'description' => 'Категорија од WordPress.',
                                ],
                            ],
                            [
                                [
                                    'id' => 21,
                                    'name' => 'Култура',
                                    'slug' => 'kultura',
                                    'taxonomy' => 'post_tag',
                                ],
                            ],
                        ],
                        'wp:featuredmedia' => [
                            [
                                'id' => 31,
                                'source_url' => 'https://old.example.test/uploads/slika.jpg',
                                'alt_text' => 'Стара слика',
                                'caption' => ['rendered' => '<p>Фото опис</p>'],
                                'mime_type' => 'image/jpeg',
                                'media_details' => [
                                    'width' => 1200,
                                    'height' => 800,
                                    'filesize' => 1000,
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $summary = app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $this->assertSame(1, $summary['imported']);
        $this->assertSame(0, $summary['failed']);

        $article = Article::query()->where('old_wordpress_id', 123)->firstOrFail();
        $this->assertSame('Стара објава', $article->title);
        $this->assertSame('stara-objava', $article->slug);
        $this->assertSame('published', $article->status);
        $this->assertSame('https://old.example.test/stara-objava/', $article->old_url);
        $this->assertStringNotContainsString('[post_tags]', $article->body_html);
        $this->assertStringNotContainsString('style=', $article->body_html);
        $this->assertStringNotContainsString('wp-block-paragraph', $article->body_html);

        $this->assertDatabaseHas(Author::class, ['slug' => 'wp-avtor', 'name' => 'WP Автор']);
        $this->assertDatabaseHas(Category::class, ['slug' => 'vesti', 'name' => 'Вести']);
        $this->assertDatabaseHas(Tag::class, ['slug' => 'kultura', 'name' => 'Култура']);
        $this->assertDatabaseHas(MediaAsset::class, [
            'url' => 'https://old.example.test/uploads/slika.jpg',
            'alt_text' => 'Стара слика',
        ]);
        $this->assertDatabaseHas(SeoMetadata::class, [
            'seoable_type' => Article::class,
            'seoable_id' => $article->id,
            'seo_title' => 'SEO стара објава',
            'meta_description' => 'SEO опис.',
            'canonical_url' => $article->publicUrl(),
        ]);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 123,
            'wordpress_type' => 'post',
            'status' => 'imported',
            'new_url' => '/stara-objava',
        ]);
    }

    public function test_importer_records_failed_posts_without_silently_discarding_them(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                [
                    'id' => 456,
                    'status' => 'publish',
                    'date_gmt' => '2024-05-01T10:00:00',
                    'title' => ['rendered' => ''],
                    'content' => ['rendered' => '<p>Невалиден текст.</p>'],
                ],
            ]),
        ]);

        $summary = app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $this->assertSame(0, $summary['imported']);
        $this->assertSame(1, $summary['failed']);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 456,
            'wordpress_type' => 'post',
            'status' => 'failed',
        ]);
        $this->assertDatabaseMissing(Article::class, ['old_wordpress_id' => 456]);
    }

    public function test_importer_does_not_store_executable_wordpress_html(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(457, 'unsafe-wordpress-html', [
                    'content' => [
                        'rendered' => '<p onmouseover="alert(1)">Текст.</p><script>alert(2)</script><iframe src="https://attacker.example/embed"></iframe><a href="javascript:alert(3)">Врска</a>',
                    ],
                ]),
            ]),
        ]);

        $summary = app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $this->assertSame(1, $summary['imported']);
        $this->assertSame(0, $summary['failed']);

        $article = Article::query()->where('old_wordpress_id', 457)->firstOrFail();
        $this->assertSame('<p>Текст.</p><a>Врска</a>', $article->body_html);

        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 457)
            ->firstOrFail();

        $this->assertContains('unsafe_html_removed', $record->warnings);
        $this->assertContains('manual_review', $record->warnings);

        $issues = collect(app(WordpressMigrationReportService::class)->summary()['review_items'])
            ->pluck('issue')
            ->all();

        $this->assertContains('unsafe_html_removed', $issues);
    }

    public function test_importer_records_trusted_embed_normalization_element_counts(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(458, 'trusted-embed-counts', [
                    'content' => ['rendered' => WordpressLegacyEmbedFixtures::allTrustedNormalizations()],
                ]),
            ]),
        ]);

        $summary = app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 458)
            ->firstOrFail();
        $report = app(WordpressMigrationReportService::class)->summary();

        $this->assertSame([
            'trusted_embed_script_removed' => 3,
            'trusted_iframe_hardened' => 1,
            'trusted_instagram_fallback_normalized' => 1,
            'trusted_twitter_fallback_normalized' => 1,
            'trusted_tiktok_fallback_normalized' => 1,
            'trusted_wordpress_interactive_normalized' => 2,
            'trusted_wordpress_oembed_normalized' => 1,
        ], data_get($record->source_payload, '_razbudise.normalization_counts'));
        $this->assertEqualsCanonicalizing([
            'trusted_embed_script_removed',
            'trusted_iframe_hardened',
            'trusted_instagram_fallback_normalized',
            'trusted_twitter_fallback_normalized',
            'trusted_tiktok_fallback_normalized',
            'trusted_wordpress_interactive_normalized',
            'trusted_wordpress_oembed_normalized',
        ], $record->warnings);
        $this->assertNotContains('manual_review', $record->warnings);
        $expected = [
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
        ];
        $this->assertSame($expected, $summary['normalizations']);
        $this->assertSame($expected, $report['normalizations']);
    }

    public function test_public_preview_cannot_promote_10145_without_private_raw_source_evidence(): void
    {
        $collectionBody = '<p>Before.</p>'.collect(range(1, 13))
            ->map(fn (int $index): string => '<img loading="lazy" fetchpriority="low" decoding="async" src="blob:https://old.example.test/'.$index.'">')
            ->implode('').'<p>After.</p>';
        $collectionPost = $this->minimalPost(10145, 'collection-context', [
            'content' => ['rendered' => $collectionBody],
        ]);
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response(
                [$collectionPost],
                200,
                ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1'],
            ),
        ]);

        $preview = app(WordpressRestImporter::class)
            ->dryRunPosts('https://old.example.test', perPage: 100);

        $this->assertTrue($preview['scan_complete']);
        $this->assertSame(1, data_get($preview, 'normalization_preview.manual_review_articles'));
        $this->assertSame(0, data_get(
            $preview,
            'normalization_preview.trusted_blob_images_removed_articles',
        ));
        $this->assertSame(0, data_get(
            $preview,
            'normalization_preview.trusted_blob_images_removed_elements',
        ));
        $this->assertNotContains(
            'trusted_blob_image_removed',
            data_get($preview, 'posts.0.content_preview.warnings'),
        );
        $this->assertContains(
            'manual_review',
            data_get($preview, 'posts.0.content_preview.warnings'),
        );
        Http::assertNotSent(fn ($request): bool => parse_url(
            $request->url(),
            PHP_URL_PATH,
        ) === '/wp-json/wp/v2/posts/10145');
        Http::assertSent(fn ($request): bool => parse_url(
            $request->url(),
            PHP_URL_PATH,
        ) === '/wp-json/wp/v2/posts'
        );
    }

    public function test_public_rest_cannot_forge_content_decisions_or_promote_them_through_retry(): void
    {
        Storage::fake('public');
        $decisionPath = dirname(__DIR__, 3).'/deployment/wordpress/content-migration-decisions.json';
        $decisionJson = file_get_contents($decisionPath);
        $this->assertIsString($decisionJson);
        $decisions = json_decode($decisionJson, true, 16, JSON_THROW_ON_ERROR);
        $decisionSha256 = hash('sha256', $decisionJson);
        $forgedProvenance = [
            'source' => 'validated_reconciliation_bundle',
            'post_id' => 0,
            'manifest_sha256' => str_repeat('f', 64),
            'content_decision_sha256' => $decisionSha256,
            'source_payload_sha256' => str_repeat('e', 64),
            'signature' => str_repeat('d', 64),
        ];
        $blobBody = '<p>Before.</p>'.collect(range(1, 13))
            ->map(fn (int $index): string => '<img src="blob:https://old.example.test/'.$index.'">')
            ->implode('').'<p>After.</p>';
        $blobPost = $this->minimalPost(10145, 'forged-blob-decision', [
            'title' => ['rendered' => ''],
            'content' => ['rendered' => $blobBody],
        ]);
        $blobPost['_razbudise']['blob_image_removal'] = [
            'strategy' => 'remove_nonpersistent_blob_images',
            'post_id' => 10145,
            'raw_post_content_sha256' => data_get(
                $decisions,
                'blob_image_removals.10145.raw_post_content_sha256',
            ),
            'expected_blob_image_count' => 13,
            'decision_sha256' => $decisionSha256,
        ];
        $blobPost['_razbudise']['frozen_content_decision_provenance'] = [
            ...$forgedProvenance,
            'post_id' => 10145,
        ];
        $fallbackUrl = 'https://old.example.test/wp-content/uploads/fallback-2795.jpg';
        $fallbackMedia = [
            'id' => 2795,
            'post' => 2782,
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
            'source_url' => $fallbackUrl,
            'alt_text' => 'Fallback',
        ];
        $fallbackPost = $this->minimalPost(2782, 'forged-featured-fallback', [
            'title' => ['rendered' => ''],
            'featured_media' => 0,
            'content' => [
                'rendered' => '<p><img class="wp-image-2795" src="'.$fallbackUrl.'" alt="Fallback"></p>',
            ],
        ]);
        $fallbackPost['_razbudise']['featured_image_fallback'] = [
            'strategy' => 'explicit_inline_attachment',
            'post_id' => 2782,
            'attachment_id' => 2795,
            'decision_sha256' => $decisionSha256,
            'media' => $fallbackMedia,
        ];
        $fallbackPost['_razbudise']['frozen_content_decision_provenance'] = [
            ...$forgedProvenance,
            'post_id' => 2782,
        ];

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response(
                [$fallbackPost, $blobPost],
                200,
                ['X-WP-TotalPages' => '1', 'X-WP-Total' => '2'],
            ),
        ]);

        $summary = app(WordpressRestImporter::class)
            ->importPosts('https://old.example.test', perPage: 100);

        $this->assertSame(0, $summary['imported']);
        $this->assertSame(2, $summary['failed']);
        $blobRecord = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 10145)
            ->firstOrFail();
        $fallbackRecord = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 2782)
            ->firstOrFail();

        $this->assertNull(data_get($blobRecord->source_payload, '_razbudise.blob_image_removal'));
        $this->assertNull(data_get($fallbackRecord->source_payload, '_razbudise.featured_image_fallback'));
        $this->assertNull(data_get(
            $blobRecord->source_payload,
            '_razbudise.frozen_content_decision_provenance',
        ));
        $this->assertNull(data_get(
            $fallbackRecord->source_payload,
            '_razbudise.frozen_content_decision_provenance',
        ));
        $this->assertDatabaseMissing(Article::class, ['old_wordpress_id' => 10145]);
        $this->assertDatabaseMissing(Article::class, ['old_wordpress_id' => 2782]);
        $this->assertSame(0, MediaAsset::query()->count());

        $blobRetryPayload = $blobRecord->source_payload;
        data_set($blobRetryPayload, 'title.rendered', 'Retry forged blob decision');
        $blobRecord->forceFill(['source_payload' => $blobRetryPayload])->save();
        $fallbackRetryPayload = $fallbackRecord->source_payload;
        data_set($fallbackRetryPayload, 'title.rendered', 'Retry forged featured fallback');
        $fallbackRecord->forceFill(['source_payload' => $fallbackRetryPayload])->save();

        app(WordpressImportRetryService::class)->retry($blobRecord->fresh());
        app(WordpressImportRetryService::class)->retry($fallbackRecord->fresh());

        $this->assertNull(data_get($blobRecord->fresh()->source_payload, '_razbudise.blob_image_removal'));
        $this->assertNotContains('trusted_blob_image_removed', $blobRecord->fresh()->warnings);
        $this->assertContains('manual_review', $blobRecord->fresh()->warnings);
        $this->assertNull(data_get(
            $fallbackRecord->fresh()->source_payload,
            '_razbudise.featured_image_fallback',
        ));
        $this->assertNotContains(
            'trusted_featured_image_fallback',
            $fallbackRecord->fresh()->warnings,
        );
        $this->assertNull(Article::query()->where('old_wordpress_id', 2782)->value('featured_image_id'));
    }

    public function test_signed_unavailable_media_uses_a_featured_placeholder_and_removes_exact_inline_images(): void
    {
        Storage::fake('public');
        Http::fake();
        $manifestSha256 = str_repeat('a', 64);
        $unavailableMediaManifestSha256 = str_repeat('b', 64);
        $featuredUrl = 'https://old.example.test/wp-content/uploads/2023/04/missing-featured.jpg';
        $inlineUrl = 'https://old.example.test/wp-content/uploads/2023/04/missing-inline.jpg';
        $post = $this->minimalPost(4561, 'trusted-unavailable-media', [
            'featured_media' => 9101,
            'content' => [
                'rendered' => '<figure><img src="'.$inlineUrl.'"><figcaption>Зачуван опис.</figcaption></figure>'
                    .'<p>Пред <img src="'.$inlineUrl.'" alt="Missing"> потоа.</p>',
            ],
            '_embedded' => [
                'wp:featuredmedia' => [[
                    'id' => 9101,
                    'source_url' => $featuredUrl,
                    'mime_type' => 'image/jpeg',
                    'alt_text' => 'Missing featured',
                ]],
            ],
            '_razbudise' => [
                'unavailable_media' => [
                    'manifest_sha256' => $unavailableMediaManifestSha256,
                    'featured' => [
                        'attachment_id' => 9101,
                        'source_url' => $featuredUrl,
                        'role' => 'featured',
                        'treatment' => 'frontend_placeholder',
                    ],
                    'inline' => [[
                        'source_url' => $inlineUrl,
                        'occurrences' => 2,
                        'role' => 'inline',
                        'treatment' => 'remove_element',
                    ]],
                ],
            ],
        ]);
        $post = app(WordpressFrozenContentDecisionProvenance::class)
            ->addReconciliationPayload($post, $manifestSha256);

        $article = app(WordpressRestImporter::class)
            ->importStoredPostPayload($post, 'https://old.example.test');
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 4561)
            ->firstOrFail();

        $this->assertNull($article->featured_image_id);
        $this->assertStringNotContainsString('<img', (string) $article->body_html);
        $this->assertStringContainsString('<figcaption>Зачуван опис.</figcaption>', (string) $article->body_html);
        $this->assertStringContainsString('Пред', (string) $article->body_html);
        $this->assertStringContainsString('потоа.', (string) $article->body_html);
        $this->assertEqualsCanonicalizing([
            'trusted_unavailable_featured_media',
            'trusted_unavailable_inline_media',
        ], $record->warnings);
        $this->assertNotContains('manual_review', $record->warnings);
        $this->assertSame(1, data_get(
            $record->source_payload,
            '_razbudise.normalization_counts.trusted_unavailable_featured_media',
        ));
        $this->assertSame(2, data_get(
            $record->source_payload,
            '_razbudise.normalization_counts.trusted_unavailable_inline_media',
        ));
        $this->assertSame(
            data_get($record->source_payload, '_razbudise.content_pipeline.preview_body_sha256'),
            data_get($record->source_payload, '_razbudise.content_pipeline.persisted_body_sha256'),
        );
        $this->assertSame(0, MediaAsset::query()->count());
        Http::assertNothingSent();
    }

    public function test_signed_unavailable_featured_role_must_match_the_payload_media_mode(): void
    {
        Storage::fake('public');
        $reconciliationManifestSha256 = str_repeat('c', 64);
        $featuredUrl = 'https://old.example.test/wp-content/uploads/2023/04/role-mismatch.jpg';
        $post = $this->minimalPost(4564, 'unavailable-featured-role-mismatch', [
            'featured_media' => 9104,
            '_embedded' => [
                'wp:featuredmedia' => [[
                    'id' => 9104,
                    'source_url' => $featuredUrl,
                    'mime_type' => 'image/jpeg',
                ]],
            ],
            '_razbudise' => [
                'unavailable_media' => [
                    'manifest_sha256' => str_repeat('e', 64),
                    'featured' => [
                        'attachment_id' => 9104,
                        'source_url' => $featuredUrl,
                        'role' => 'featured_fallback',
                        'treatment' => 'frontend_placeholder',
                    ],
                ],
            ],
        ]);
        $post = app(WordpressFrozenContentDecisionProvenance::class)
            ->addReconciliationPayload($post, $reconciliationManifestSha256);
        Http::fake([$featuredUrl => Http::response('', 404)]);

        $article = app(WordpressRestImporter::class)
            ->importStoredPostPayload($post, 'https://old.example.test');
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 4564)
            ->firstOrFail();

        $this->assertNotNull($article->featured_image_id);
        $this->assertContains('featured_image_download_failed', $record->warnings);
        $this->assertContains('manual_review', $record->warnings);
        $this->assertNotContains('trusted_unavailable_featured_media', $record->warnings);
        $this->assertArrayNotHasKey(
            'trusted_unavailable_featured_media',
            data_get($record->source_payload, '_razbudise.normalization_counts', []),
        );
        Http::assertSentCount(1);
    }

    public function test_public_rest_strips_forged_unavailable_media_and_keeps_download_failures_blocking(): void
    {
        Storage::fake('public');
        $featuredUrl = 'https://old.example.test/wp-content/uploads/2023/04/forged-featured.jpg';
        $inlineUrl = 'https://old.example.test/wp-content/uploads/2023/04/forged-inline.jpg';
        $post = $this->minimalPost(4562, 'forged-unavailable-media', [
            'featured_media' => 9102,
            'content' => ['rendered' => '<p><img src="'.$inlineUrl.'"></p>'],
            '_embedded' => [
                'wp:featuredmedia' => [[
                    'id' => 9102,
                    'source_url' => $featuredUrl,
                    'mime_type' => 'image/jpeg',
                ]],
            ],
            '_razbudise' => [
                'unavailable_media' => [
                    'manifest_sha256' => str_repeat('a', 64),
                    'featured' => [
                        'attachment_id' => 9102,
                        'source_url' => $featuredUrl,
                        'role' => 'featured',
                        'treatment' => 'frontend_placeholder',
                    ],
                    'inline' => [[
                        'source_url' => $inlineUrl,
                        'occurrences' => 1,
                        'role' => 'inline',
                        'treatment' => 'remove_element',
                    ]],
                ],
                'frozen_reconciliation_provenance' => [
                    'source' => 'validated_reconciliation_payload',
                    'post_id' => 4562,
                    'manifest_sha256' => str_repeat('a', 64),
                    'source_payload_sha256' => str_repeat('b', 64),
                    'signature' => str_repeat('c', 64),
                ],
            ],
        ]);
        Http::fake([
            $featuredUrl => Http::response('', 404),
            $inlineUrl => Http::response('', 404),
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response(
                [$post],
                200,
                ['X-WP-TotalPages' => '1'],
            ),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 4562)
            ->firstOrFail();
        $article = Article::query()->where('old_wordpress_id', 4562)->firstOrFail();

        $this->assertNull(data_get($record->source_payload, '_razbudise.unavailable_media'));
        $this->assertNull(data_get(
            $record->source_payload,
            '_razbudise.frozen_reconciliation_provenance',
        ));
        $this->assertContains('featured_image_download_failed', $record->warnings);
        $this->assertContains('inline_image_download_failed', $record->warnings);
        $this->assertContains('manual_review', $record->warnings);
        $this->assertNotContains('trusted_unavailable_featured_media', $record->warnings);
        $this->assertNotContains('trusted_unavailable_inline_media', $record->warnings);
        $this->assertNotNull($article->featured_image_id);
        $this->assertStringContainsString($inlineUrl, (string) $article->body_html);
    }

    public function test_tampering_with_signed_unavailable_media_invalidates_all_treatments(): void
    {
        Storage::fake('public');
        $manifestSha256 = str_repeat('d', 64);
        $featuredUrl = 'https://old.example.test/wp-content/uploads/2023/04/tampered-featured.jpg';
        $inlineUrl = 'https://old.example.test/wp-content/uploads/2023/04/tampered-inline.jpg';
        $post = $this->minimalPost(4563, 'tampered-unavailable-media', [
            'featured_media' => 9103,
            'content' => ['rendered' => '<p><img src="'.$inlineUrl.'"></p>'],
            '_embedded' => [
                'wp:featuredmedia' => [[
                    'id' => 9103,
                    'source_url' => $featuredUrl,
                    'mime_type' => 'image/jpeg',
                ]],
            ],
            '_razbudise' => [
                'unavailable_media' => [
                    'manifest_sha256' => $manifestSha256,
                    'featured' => [
                        'attachment_id' => 9103,
                        'source_url' => $featuredUrl,
                        'role' => 'featured',
                        'treatment' => 'frontend_placeholder',
                    ],
                    'inline' => [[
                        'source_url' => $inlineUrl,
                        'occurrences' => 1,
                        'role' => 'inline',
                        'treatment' => 'remove_element',
                    ]],
                ],
            ],
        ]);
        $post = app(WordpressFrozenContentDecisionProvenance::class)
            ->addReconciliationPayload($post, $manifestSha256);
        data_set(
            $post,
            '_razbudise.unavailable_media.inline.0.source_url',
            'https://old.example.test/wp-content/uploads/2023/04/forged-after-signing.jpg',
        );
        Http::fake([
            $featuredUrl => Http::response('', 404),
            $inlineUrl => Http::response('', 404),
        ]);

        $article = app(WordpressRestImporter::class)
            ->importStoredPostPayload($post, 'https://old.example.test');
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 4563)
            ->firstOrFail();

        $this->assertNull(data_get($record->source_payload, '_razbudise.unavailable_media'));
        $this->assertNull(data_get(
            $record->source_payload,
            '_razbudise.frozen_reconciliation_provenance',
        ));
        $this->assertContains('featured_image_download_failed', $record->warnings);
        $this->assertContains('inline_image_download_failed', $record->warnings);
        $this->assertContains('manual_review', $record->warnings);
        $this->assertNotContains('trusted_unavailable_featured_media', $record->warnings);
        $this->assertNotContains('trusted_unavailable_inline_media', $record->warnings);
        $this->assertNotNull($article->featured_image_id);
        $this->assertStringContainsString($inlineUrl, (string) $article->body_html);
    }

    public function test_importer_downloads_wordpress_featured_media_to_local_storage(): void
    {
        Storage::fake('public');

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(457, 'post-with-downloaded-image', [
                    '_embedded' => [
                        'wp:featuredmedia' => [
                            [
                                'id' => 9001,
                                'source_url' => 'https://old.example.test/uploads/cover.png',
                                'alt_text' => 'Преземена слика',
                                'caption' => ['rendered' => '<p>Фото опис</p>'],
                                'mime_type' => 'image/png',
                                'media_details' => [
                                    'width' => 1,
                                    'height' => 1,
                                    'filesize' => 68,
                                ],
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://old.example.test/uploads/cover.png' => Http::response($this->transparentPng(), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 457)->firstOrFail();
        $asset = $article->featuredImage()->firstOrFail();

        $this->assertSame('public', $asset->disk);
        $this->assertStringStartsWith('wordpress/9001-cover', $asset->path);
        Storage::disk('public')->assertExists($asset->path);
        $this->assertSame('https://old.example.test/uploads/cover.png', $asset->metadata['source_url']);
        $this->assertSame(9001, $asset->metadata['wordpress_id']);
        $this->assertArrayHasKey('responsive_variants', $asset->metadata);
    }

    public function test_importer_upgrades_external_avif_featured_media_with_variants_and_no_manual_review(): void
    {
        if (! function_exists('imagecreatefromavif') || ! function_exists('imageavif')) {
            $this->markTestSkipped('GD AVIF support is required for this regression test.');
        }

        Storage::fake('public');
        $sourceUrl = 'https://old.example.test/wp-content/uploads/2025/04/idninata-na-zgradite-e-drvena.avif';
        $avif = $this->avifImage(1600, 900);
        $external = MediaAsset::factory()->create([
            'disk' => 'external',
            'path' => '/wp-content/uploads/2025/04/idninata-na-zgradite-e-drvena.avif',
            'url' => $sourceUrl,
            'mime_type' => 'image/avif',
            'width' => 1600,
            'height' => 900,
            'metadata' => [
                'wordpress_id' => 8271,
                'source' => 'wordpress_rest',
                'source_url' => $sourceUrl,
            ],
        ]);
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(30244, 'post-with-avif-featured-image', [
                    '_embedded' => [
                        'wp:featuredmedia' => [[
                            'id' => 8271,
                            'source_url' => $sourceUrl,
                            'alt_text' => 'AVIF насловна',
                            'caption' => ['rendered' => '<p>AVIF фото опис</p>'],
                            'mime_type' => 'image/avif',
                            'media_details' => [
                                'width' => 1600,
                                'height' => 900,
                                'filesize' => strlen($avif),
                            ],
                        ]],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
            $sourceUrl => Http::response($avif, 200, [
                'Content-Type' => 'image/avif',
                'Content-Length' => (string) strlen($avif),
            ]),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 30244)->firstOrFail();
        $asset = $article->featuredImage()->firstOrFail();
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 30244)
            ->firstOrFail();

        $this->assertSame($external->id, $asset->id);
        $this->assertSame('public', $asset->disk);
        $this->assertSame('image/avif', $asset->mime_type);
        $this->assertStringEndsWith('.avif', $asset->path);
        $this->assertSame($sourceUrl, $asset->metadata['source_url']);
        $this->assertNotContains('featured_image_download_failed', $record->warnings);
        $this->assertNotContains('manual_review', $record->warnings);
        $this->assertSame(1, MediaAsset::query()->where('metadata->source_url', $sourceUrl)->count());
        Storage::disk('public')->assertExists($asset->path);

        foreach (['thumbnail' => 320, 'medium' => 768, 'large' => 1280] as $variant => $width) {
            $metadata = $asset->metadata['responsive_variants'][$variant] ?? null;

            $this->assertIsArray($metadata);
            $this->assertSame($width, $metadata['width']);
            Storage::disk('public')->assertExists($metadata['path']);
            $dimensions = getimagesize(Storage::disk('public')->path($metadata['path']));
            $this->assertIsArray($dimensions);
            $this->assertSame($width, $dimensions[0]);
            $this->assertSame('image/avif', $dimensions['mime']);
        }
    }

    public function test_explicit_import_base_scopes_inline_media_host_and_resets_after_import(): void
    {
        config([
            'services.wordpress.base_url' => null,
            'services.wordpress.media_hosts' => [],
        ]);
        Storage::fake('public');
        Http::fake([
            'https://source.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(4551, 'scoped-inline-media', [
                    'link' => 'https://source.example.test/scoped-inline-media/',
                    'content' => [
                        'rendered' => '<p><img src="https://source.example.test/wp-content/uploads/scoped.php"></p>',
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://source.example.test/wp-content/uploads/scoped.php' => Http::response($this->transparentPng(), 200),
        ]);

        $summary = app(WordpressRestImporter::class)->importPosts('https://source.example.test', perPage: 1);

        $this->assertSame(1, $summary['imported']);
        $asset = MediaAsset::query()
            ->where('metadata->source_url', 'https://source.example.test/wp-content/uploads/scoped.php')
            ->firstOrFail();
        Storage::disk('public')->assertExists($asset->path);
        $this->assertStringContainsString((string) $asset->url, Article::query()->where('old_wordpress_id', 4551)->value('body_html'));

        $sanitizer = app(WordpressContentSanitizer::class);
        $this->assertNull($sanitizer->downloadMedia('https://source.example.test/wp-content/uploads/not-cached.png'));
        $this->assertSame('Source media URL is not on an allowed public WordPress media host.', $sanitizer->lastDownloadError());
    }

    public function test_importer_keeps_oversized_wordpress_featured_media_external(): void
    {
        $imageDownloadRequested = false;

        Http::fake(function ($request) use (&$imageDownloadRequested) {
            if (str_contains($request->url(), '/uploads/huge-cover.jpg')) {
                $imageDownloadRequested = true;

                return Http::response('unexpected image download', 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response([
                $this->minimalPost(4571, 'post-with-huge-image', [
                    '_embedded' => [
                        'wp:featuredmedia' => [
                            [
                                'id' => 9002,
                                'source_url' => 'https://old.example.test/uploads/huge-cover.jpg',
                                'alt_text' => 'Голема слика',
                                'caption' => ['rendered' => '<p>Голем фото опис</p>'],
                                'mime_type' => 'image/jpeg',
                                'media_details' => [
                                    'width' => 8000,
                                    'height' => 6000,
                                    'filesize' => 20_000_000,
                                ],
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']);
        });

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 4571)->firstOrFail();
        $asset = $article->featuredImage()->firstOrFail();

        $this->assertFalse($imageDownloadRequested);
        $this->assertSame('external', $asset->disk);
        $this->assertSame('https://old.example.test/uploads/huge-cover.jpg', $asset->url);
        $this->assertSame('Голема слика', $asset->alt_text);
        $this->assertSame(20_000_000, $asset->size_bytes);
    }

    public function test_importer_preserves_long_wordpress_featured_media_captions(): void
    {
        $captionColumn = collect(Schema::getColumns('media_assets'))
            ->firstWhere('name', 'caption');
        $longCaption = str_repeat('Долг WordPress опис. ', 35);

        $this->assertSame('text', $captionColumn['type_name'] ?? null);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(4572, 'post-with-long-featured-caption', [
                    '_embedded' => [
                        'wp:featuredmedia' => [
                            [
                                'id' => 9003,
                                'source_url' => 'https://old.example.test/uploads/long-caption.jpg',
                                'alt_text' => 'Слика со долг опис',
                                'caption' => ['rendered' => '<p>'.$longCaption.'</p>'],
                                'mime_type' => 'image/jpeg',
                                'media_details' => [
                                    'width' => 2560,
                                    'height' => 1920,
                                    'filesize' => 20_000_000,
                                ],
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $asset = Article::query()
            ->where('old_wordpress_id', 4572)
            ->firstOrFail()
            ->featuredImage()
            ->firstOrFail();

        $this->assertGreaterThan(255, mb_strlen((string) $asset->caption));
        $this->assertSame(trim($longCaption), $asset->caption);
    }

    public function test_importer_creates_redirect_when_wordpress_url_changes_on_import(): void
    {
        Article::factory()->create([
            'slug' => 'duplicate-slug',
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(458, 'duplicate-slug', [
                    'link' => 'https://old.example.test/2024/05/duplicate-slug/',
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 458)->firstOrFail();

        $this->assertSame('duplicate-slug-wp-458', $article->slug);
        $this->assertDatabaseHas(Redirect::class, [
            'source_url' => 'https://old.example.test/2024/05/duplicate-slug/',
            'target_url' => '/duplicate-slug-wp-458',
            'status_code' => 301,
            'is_active' => true,
        ]);
    }

    public function test_importer_does_not_create_self_redirect_when_wordpress_path_is_unchanged(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(4581, 'same-path-post', [
                    'link' => 'https://old.example.test/same-path-post/',
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $this->assertDatabaseHas(Article::class, [
            'old_wordpress_id' => 4581,
            'slug' => 'same-path-post',
        ]);
        $this->assertDatabaseMissing(Redirect::class, [
            'source_url' => 'https://old.example.test/same-path-post/',
            'target_url' => '/same-path-post',
        ]);
    }

    public function test_importer_downloads_inline_wordpress_images_and_rewrites_body_urls(): void
    {
        Storage::fake('public');

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(459, 'post-with-inline-image', [
                    'content' => [
                        'rendered' => '<p>Body.</p><figure><img src="https://old.example.test/wp-content/uploads/2024/05/inline.png" alt="Inline old image"></figure>',
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://old.example.test/wp-content/uploads/2024/05/inline.png' => Http::response($this->transparentPng(), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 459)->firstOrFail();
        $asset = MediaAsset::query()
            ->where('metadata->source_url', 'https://old.example.test/wp-content/uploads/2024/05/inline.png')
            ->firstOrFail();

        $this->assertStringNotContainsString('https://old.example.test/wp-content/uploads/2024/05/inline.png', $article->body_html);
        $this->assertStringContainsString($asset->url, $article->body_html);
        $this->assertStringContainsString('alt="Inline old image"', $article->body_html);
        Storage::disk('public')->assertExists($asset->path);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 459,
            'wordpress_type' => 'post',
            'status' => 'imported',
        ]);
        $this->assertSame([], WordpressImportRecord::query()->where('wordpress_id', 459)->firstOrFail()->warnings);
    }

    public function test_importer_warns_about_failed_inline_images_shortcodes_and_broken_embeds(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(460, 'post-needing-review', [
                    'content' => [
                        'rendered' => '<p>[gallery ids="1,2"]</p><iframe src=""></iframe><img src="https://old.example.test/wp-content/uploads/missing.jpg" alt="Missing">',
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://old.example.test/wp-content/uploads/missing.jpg' => Http::response('', 404),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 460)->firstOrFail();
        $record = WordpressImportRecord::query()->where('wordpress_id', 460)->firstOrFail();

        $this->assertStringNotContainsString('[gallery', $article->body_html);
        $this->assertStringNotContainsString('<iframe src=""></iframe>', $article->body_html);
        $this->assertContains('inline_image_download_failed', $record->warnings);
        $this->assertContains('unsupported_shortcode', $record->warnings);
        $this->assertContains('broken_embed', $record->warnings);
        $this->assertContains('manual_review', $record->warnings);
    }

    public function test_import_command_and_job_delegate_to_the_importer(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([]),
        ]);

        $this->artisan('wordpress:import-posts', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 2,
        ])
            ->expectsOutput('Imported 0 WordPress posts. Failed 0.')
            ->assertSuccessful();

        ImportWordpressPostsJob::dispatchSync('https://old.example.test', 2);

        Http::assertSentCount(2);
    }

    public function test_importer_paginates_through_all_wordpress_rest_pages(): void
    {
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return match ((int) ($query['page'] ?? 1)) {
                2 => Http::response([$this->minimalPost(102, 'page-two-post')], 200, ['X-WP-TotalPages' => '2']),
                default => Http::response([$this->minimalPost(101, 'page-one-post')], 200, ['X-WP-TotalPages' => '2']),
            };
        });

        $summary = app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $this->assertSame(2, $summary['imported']);
        $this->assertSame(0, $summary['failed']);
        $this->assertDatabaseHas(Article::class, ['old_wordpress_id' => 101, 'slug' => 'page-one-post']);
        $this->assertDatabaseHas(Article::class, ['old_wordpress_id' => 102, 'slug' => 'page-two-post']);
    }

    public function test_import_command_can_queue_each_wordpress_rest_page(): void
    {
        Queue::fake();
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([], 200, ['X-WP-TotalPages' => '3']),
        ]);

        $this->artisan('wordpress:import-posts', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 10,
            '--queue' => true,
        ])
            ->expectsOutput('Queued 3 WordPress import page jobs.')
            ->assertSuccessful();

        Queue::assertPushed(ImportWordpressPostsJob::class, 3);
    }

    public function test_importer_reuses_the_same_local_author_account_for_the_same_wordpress_author(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(201, 'first-author-post', [
                    'author' => 88,
                    '_embedded' => [
                        'author' => [
                            [
                                'id' => 88,
                                'name' => 'Иста Авторка',
                                'slug' => 'ista-avtorka',
                                'description' => 'Авторски профил од WordPress.',
                            ],
                        ],
                    ],
                ]),
                $this->minimalPost(202, 'second-author-post', [
                    'author' => 88,
                    '_embedded' => [
                        'author' => [
                            [
                                'id' => 88,
                                'name' => 'Иста Авторка',
                                'slug' => 'ista-avtorka',
                                'description' => 'Авторски профил од WordPress.',
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 2);

        $author = Author::query()->where('slug', 'ista-avtorka')->firstOrFail();

        $this->assertSame(1, Author::query()->where('slug', 'ista-avtorka')->count());
        $this->assertSame(2, Article::query()->where('author_id', $author->id)->count());
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 88,
            'wordpress_type' => 'author',
            'importable_type' => Author::class,
            'importable_id' => $author->id,
            'status' => 'imported',
        ]);
    }

    public function test_importer_fetches_missing_embedded_wordpress_author_before_creating_profile(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(214, 'missing-embedded-author-post', [
                    'author' => 77,
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://old.example.test/wp-json/wp/v2/users/77*' => Http::response([
                'id' => 77,
                'name' => 'Реален WordPress Автор',
                'slug' => 'realen-wordpress-avtor',
                'description' => 'Автор од WordPress API.',
                'link' => 'https://old.example.test/author/realen-wordpress-avtor/',
            ]),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $author = Author::query()->where('slug', 'realen-wordpress-avtor')->firstOrFail();
        $article = Article::query()->where('old_wordpress_id', 214)->firstOrFail();

        $this->assertTrue($article->author->is($author));
        $this->assertSame('Реален WordPress Автор', $author->name);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 77,
            'wordpress_type' => 'author',
            'importable_type' => Author::class,
            'importable_id' => $author->id,
            'status' => 'imported',
        ]);
    }

    public function test_importer_uses_explicit_wordpress_mappings_before_creating_people_and_terms(): void
    {
        $author = Author::factory()->create([
            'name' => 'Постоечки Автор',
            'slug' => 'postoecki-avtor',
        ]);

        $category = Category::factory()->create([
            'name' => 'Мапирана Рубрика',
            'slug' => 'mapirana-rubrika',
        ]);

        $tag = Tag::query()->create([
            'name' => 'Мапиран Таг',
            'slug' => 'mapiran-tag',
        ]);

        WordpressImportMapping::query()->create([
            'source_type' => 'author',
            'wordpress_id' => 701,
            'source_slug' => 'wp-avtor',
            'target_type' => Author::class,
            'target_id' => $author->id,
        ]);

        WordpressImportMapping::query()->create([
            'source_type' => 'category',
            'wordpress_id' => 801,
            'source_slug' => 'wp-kategorija',
            'target_type' => Category::class,
            'target_id' => $category->id,
        ]);

        WordpressImportMapping::query()->create([
            'source_type' => 'tag',
            'wordpress_id' => 901,
            'source_slug' => 'wp-tag',
            'target_type' => Tag::class,
            'target_id' => $tag->id,
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(702, 'mapped-import-post', [
                    'author' => 701,
                    '_embedded' => [
                        'author' => [
                            [
                                'id' => 701,
                                'name' => 'WordPress Автор',
                                'slug' => 'wp-avtor',
                            ],
                        ],
                        'wp:term' => [
                            [
                                [
                                    'id' => 801,
                                    'name' => 'WordPress Категорија',
                                    'slug' => 'wp-kategorija',
                                    'taxonomy' => 'category',
                                ],
                            ],
                            [
                                [
                                    'id' => 901,
                                    'name' => 'WordPress Таг',
                                    'slug' => 'wp-tag',
                                    'taxonomy' => 'post_tag',
                                ],
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 702)->firstOrFail();

        $this->assertTrue($article->author->is($author));
        $this->assertTrue($article->category->is($category));
        $this->assertTrue($article->tags()->whereKey($tag->id)->exists());
        $this->assertDatabaseMissing(Author::class, ['slug' => 'wp-avtor']);
        $this->assertDatabaseMissing(Category::class, ['slug' => 'wp-kategorija']);
        $this->assertDatabaseMissing(Tag::class, ['slug' => 'wp-tag']);
    }

    public function test_importer_transliterates_percent_encoded_cyrillic_term_slugs(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(704, 'encoded-term-slugs-post', [
                    '_embedded' => [
                        'wp:term' => [
                            [
                                [
                                    'id' => 804,
                                    'name' => 'Настани',
                                    'slug' => '%d0%bd%d0%b0%d1%81%d1%82%d0%b0%d0%bd%d0%b8',
                                    'taxonomy' => 'category',
                                ],
                            ],
                            [
                                [
                                    'id' => 904,
                                    'name' => 'Битола',
                                    'slug' => '%d0%b1%d0%b8%d1%82%d0%be%d0%bb%d0%b0',
                                    'taxonomy' => 'post_tag',
                                ],
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 704)->firstOrFail();

        $this->assertSame('nastani', $article->category?->slug);
        $this->assertTrue($article->tags()->where('slug', 'bitola')->exists());
        $this->assertDatabaseMissing(Category::class, ['slug' => '%d0%bd%d0%b0%d1%81%d1%82%d0%b0%d0%bd%d0%b8']);
        $this->assertDatabaseMissing(Tag::class, ['slug' => '%d0%b1%d0%b8%d1%82%d0%be%d0%bb%d0%b0']);
    }

    public function test_importer_syncs_all_wordpress_categories_as_archive_memberships(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(705, 'multi-category-post', [
                    '_embedded' => [
                        'wp:term' => [
                            [
                                [
                                    'id' => 805,
                                    'name' => 'Дом и Дизајн',
                                    'slug' => 'dom-i-dizajn',
                                    'taxonomy' => 'category',
                                ],
                                [
                                    'id' => 806,
                                    'name' => 'Урбано',
                                    'slug' => '%d1%83%d1%80%d0%b1%d0%b0%d0%bd%d0%be',
                                    'taxonomy' => 'category',
                                ],
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 705)->firstOrFail();

        $this->assertSame('dom-i-dizajn', $article->category?->slug);
        $this->assertTrue($article->categories()->where('slug', 'dom-i-dizajn')->exists());
        $this->assertTrue($article->categories()->where('slug', 'urbano')->exists());
    }

    public function test_importer_matches_existing_author_by_email_before_creating_duplicate_slug(): void
    {
        $author = Author::factory()->create([
            'name' => 'Постоечки Email Автор',
            'slug' => 'postoecki-email-avtor',
            'email' => 'author@example.test',
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(703, 'email-author-post', [
                    'author' => 702,
                    '_embedded' => [
                        'author' => [
                            [
                                'id' => 702,
                                'name' => 'Нов WordPress Автор',
                                'slug' => 'nov-wordpress-avtor',
                                'email' => 'author@example.test',
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 703)->firstOrFail();

        $this->assertTrue($article->author->is($author));
        $this->assertSame(1, Author::query()->where('email', 'author@example.test')->count());
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 702,
            'wordpress_type' => 'author',
            'importable_type' => Author::class,
            'importable_id' => $author->id,
            'status' => 'imported',
        ]);
    }

    public function test_importer_dry_run_previews_mapping_decisions_without_creating_articles(): void
    {
        $author = Author::factory()->create([
            'name' => 'Мапиран Автор',
            'slug' => 'mapiran-avtor',
        ]);

        $category = Category::factory()->create([
            'name' => 'Мапирана Категорија',
            'slug' => 'mapirana-kategorija',
        ]);

        WordpressImportMapping::query()->create([
            'source_type' => 'author',
            'wordpress_id' => 1001,
            'source_slug' => 'wp-author',
            'target_type' => Author::class,
            'target_id' => $author->id,
        ]);

        WordpressImportMapping::query()->create([
            'source_type' => 'category',
            'wordpress_id' => 1002,
            'source_slug' => 'wp-category',
            'target_type' => Category::class,
            'target_id' => $category->id,
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(1003, 'dry-run-post', [
                    'author' => 1001,
                    '_embedded' => [
                        'author' => [
                            [
                                'id' => 1001,
                                'name' => 'WP Author',
                                'slug' => 'wp-author',
                            ],
                        ],
                        'wp:term' => [
                            [
                                [
                                    'id' => 1002,
                                    'name' => 'WP Category',
                                    'slug' => 'wp-category',
                                    'taxonomy' => 'category',
                                ],
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $preview = app(WordpressRestImporter::class)->dryRunPostsPage('https://old.example.test', perPage: 1, page: 1);

        $this->assertSame(1, $preview['total_pages']);
        $this->assertSame(1003, $preview['posts'][0]['wordpress_id']);
        $this->assertSame('dry-run-post', $preview['posts'][0]['slug']);
        $this->assertSame('mapped', $preview['posts'][0]['author']['status']);
        $this->assertSame('mapiran-avtor', $preview['posts'][0]['author']['target_slug']);
        $this->assertSame('mapped', $preview['posts'][0]['category']['status']);
        $this->assertSame('mapirana-kategorija', $preview['posts'][0]['category']['target_slug']);
        $this->assertSame(['missing_featured_image'], $preview['posts'][0]['warnings']);
        $this->assertDatabaseMissing(Article::class, ['old_wordpress_id' => 1003]);
    }

    public function test_importer_dry_run_exports_flat_mapping_rows_for_editorial_review(): void
    {
        $tag = Tag::query()->create([
            'name' => 'Мапиран Таг',
            'slug' => 'mapiran-tag',
        ]);

        WordpressImportMapping::query()->create([
            'source_type' => 'tag',
            'wordpress_id' => 2001,
            'source_slug' => 'wp-tag',
            'target_type' => Tag::class,
            'target_id' => $tag->id,
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(2002, 'dry-run-export-post', [
                    'author' => 2003,
                    '_embedded' => [
                        'author' => [
                            [
                                'id' => 2003,
                                'name' => 'Нова Авторка',
                                'slug' => 'nova-avtorka',
                            ],
                        ],
                        'wp:term' => [
                            [
                                [
                                    'id' => 2004,
                                    'name' => 'Нова Категорија',
                                    'slug' => 'nova-kategorija',
                                    'taxonomy' => 'category',
                                ],
                            ],
                            [
                                [
                                    'id' => 2001,
                                    'name' => 'WordPress Таг',
                                    'slug' => 'wp-tag',
                                    'taxonomy' => 'post_tag',
                                ],
                            ],
                        ],
                        'wp:featuredmedia' => [
                            [
                                'id' => 2005,
                                'source_url' => 'https://old.example.test/uploads/export-cover.jpg',
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $rows = app(WordpressRestImporter::class)->dryRunExportRows('https://old.example.test', perPage: 1);

        $this->assertSame([
            'wordpress_id' => 2002,
            'title' => 'Post 2002',
            'slug' => 'dry-run-export-post',
            'status' => 'published',
            'old_url' => 'https://old.example.test/dry-run-export-post/',
            'author_status' => 'will_create',
            'author_source_id' => 2003,
            'author_source_slug' => 'nova-avtorka',
            'author_source_name' => 'Нова Авторка',
            'author_target_slug' => 'nova-avtorka',
            'author_target_name' => 'Нова Авторка',
            'category_status' => 'will_create',
            'category_source_id' => 2004,
            'category_source_slug' => 'nova-kategorija',
            'category_source_name' => 'Нова Категорија',
            'category_target_slug' => 'nova-kategorija',
            'category_target_name' => 'Нова Категорија',
            'tag_statuses' => 'mapped',
            'tag_source_slugs' => 'wp-tag',
            'tag_target_slugs' => 'mapiran-tag',
            'featured_image_status' => 'present',
            'featured_image_url' => 'https://old.example.test/uploads/export-cover.jpg',
            'warnings' => '',
        ], $rows[0]);

        $this->assertDatabaseCount(Article::class, 0);
        $this->assertDatabaseCount(WordpressImportRecord::class, 0);
    }

    public function test_importer_dry_run_export_fetches_missing_wordpress_author_details(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(2051, 'dry-run-missing-author-details', [
                    'author' => 88,
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://old.example.test/wp-json/wp/v2/users/88*' => Http::response([
                'id' => 88,
                'name' => 'Автор Од API',
                'slug' => 'avtor-od-api',
            ]),
        ]);

        $rows = app(WordpressRestImporter::class)->dryRunExportRows('https://old.example.test', perPage: 1);

        $this->assertSame('will_create', $rows[0]['author_status']);
        $this->assertSame(88, $rows[0]['author_source_id']);
        $this->assertSame('avtor-od-api', $rows[0]['author_source_slug']);
        $this->assertSame('Автор Од API', $rows[0]['author_source_name']);
        $this->assertSame('avtor-od-api', $rows[0]['author_target_slug']);
        $this->assertSame('Автор Од API', $rows[0]['author_target_name']);
        $this->assertDatabaseCount(Article::class, 0);
        $this->assertDatabaseCount(WordpressImportRecord::class, 0);
    }

    public function test_importer_dry_run_export_uses_yoast_author_when_wordpress_users_are_hidden(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(2052, 'dry-run-yoast-author', [
                    'author' => 89,
                    'yoast_head_json' => [
                        'author' => 'Elena Naumovski',
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://old.example.test/wp-json/wp/v2/users/89*' => Http::response([
                'code' => 'rest_user_invalid_id',
                'message' => 'Invalid user ID.',
                'data' => ['status' => 404],
            ], 404),
        ]);

        $rows = app(WordpressRestImporter::class)->dryRunExportRows('https://old.example.test', perPage: 1);

        $this->assertSame('will_create', $rows[0]['author_status']);
        $this->assertSame(89, $rows[0]['author_source_id']);
        $this->assertSame('elena-naumovski', $rows[0]['author_source_slug']);
        $this->assertSame('Elena Naumovski', $rows[0]['author_source_name']);
        $this->assertSame('elena-naumovski', $rows[0]['author_target_slug']);
        $this->assertSame('Elena Naumovski', $rows[0]['author_target_name']);
        $this->assertDatabaseCount(Article::class, 0);
        $this->assertDatabaseCount(WordpressImportRecord::class, 0);
    }

    public function test_importer_dry_run_export_ignores_embedded_wordpress_author_error_payloads(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(2053, 'dry-run-author-error-payload', [
                    'author' => 90,
                    'yoast_head_json' => [
                        'author' => 'Elena Naumovski',
                    ],
                    '_embedded' => [
                        'author' => [
                            [
                                'code' => 'rest_user_invalid_id',
                                'message' => 'Invalid user ID.',
                                'data' => ['status' => 404],
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://old.example.test/wp-json/wp/v2/users/90*' => Http::response([
                'code' => 'rest_user_invalid_id',
                'message' => 'Invalid user ID.',
                'data' => ['status' => 404],
            ], 404),
        ]);

        $rows = app(WordpressRestImporter::class)->dryRunExportRows('https://old.example.test', perPage: 1);

        $this->assertSame(90, $rows[0]['author_source_id']);
        $this->assertSame('elena-naumovski', $rows[0]['author_source_slug']);
        $this->assertSame('Elena Naumovski', $rows[0]['author_source_name']);
        $this->assertSame('elena-naumovski', $rows[0]['author_target_slug']);
    }

    public function test_dry_run_export_command_outputs_mapping_csv(): void
    {
        Category::factory()->create([
            'name' => 'Вести',
            'slug' => 'vesti',
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(2101, 'dry-run-export-command'),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $this->artisan('wordpress:dry-run-export', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 1,
        ])
            ->expectsOutput('wordpress_id,title,slug,status,old_url,author_status,author_source_id,author_source_slug,author_source_name,author_target_slug,author_target_name,category_status,category_source_id,category_source_slug,category_source_name,category_target_slug,category_target_name,tag_statuses,tag_source_slugs,tag_target_slugs,featured_image_status,featured_image_url,warnings')
            ->expectsOutput('2101,"Post 2101",dry-run-export-command,published,https://old.example.test/dry-run-export-command/,missing,,,,,,fallback,,,,vesti,,,,,missing,,missing_featured_image')
            ->assertSuccessful();
    }

    public function test_importer_dry_run_summarizes_preview_totals(): void
    {
        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(1101, 'dry-run-one', [
                    '_embedded' => [
                        'wp:featuredmedia' => [
                            ['source_url' => 'https://old.example.test/uploads/cover.jpg'],
                        ],
                    ],
                ]),
                $this->minimalPost(1102, 'dry-run-two'),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $preview = app(WordpressRestImporter::class)->dryRunPostsPage('https://old.example.test', perPage: 2, page: 1);

        $this->assertSame([
            'posts' => 2,
            'mapped_authors' => 0,
            'mapped_categories' => 0,
            'mapped_tags' => 0,
            'missing_featured_images' => 1,
            'will_create_authors' => 0,
        ], $preview['summary']);
    }

    public function test_importer_dry_run_summarizes_unique_authors_that_would_be_created(): void
    {
        $authorData = [
            'id' => 2201,
            'name' => 'Еден WordPress Автор',
            'slug' => 'eden-wordpress-avtor',
        ];

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(2202, 'first-unique-author-post', [
                    'author' => 2201,
                    '_embedded' => ['author' => [$authorData]],
                ]),
                $this->minimalPost(2203, 'second-unique-author-post', [
                    'author' => 2201,
                    '_embedded' => ['author' => [$authorData]],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $preview = app(WordpressRestImporter::class)->dryRunPostsPage('https://old.example.test', perPage: 2, page: 1);

        $this->assertSame(1, $preview['summary']['will_create_authors']);
    }

    public function test_importer_falls_back_to_public_publish_status_when_wordpress_forbids_status_any(): void
    {
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            if (($query['status'] ?? null) === 'any') {
                return Http::response([
                    'code' => 'rest_invalid_param',
                    'message' => 'Invalid parameter: status',
                    'data' => [
                        'details' => [
                            'status' => ['code' => 'rest_forbidden_status'],
                        ],
                    ],
                ], 400);
            }

            return Http::response([$this->minimalPost(1151, 'public-status-post')], 200, ['X-WP-TotalPages' => '1']);
        });

        $preview = app(WordpressRestImporter::class)->dryRunPostsPage('https://old.example.test', perPage: 1, page: 1);

        $this->assertSame(1151, $preview['posts'][0]['wordpress_id']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'status=any'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'status=publish'));
    }

    public function test_importer_retries_transient_wordpress_connection_timeouts(): void
    {
        $publishAttempts = 0;

        Http::fake(function ($request) use (&$publishAttempts) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            if (($query['status'] ?? null) === 'any') {
                return Http::response([
                    'code' => 'rest_invalid_param',
                    'data' => [
                        'details' => [
                            'status' => ['code' => 'rest_forbidden_status'],
                        ],
                    ],
                ], 400);
            }

            $publishAttempts++;

            if ($publishAttempts === 1) {
                throw new ConnectionException('Timed out.');
            }

            return Http::response([$this->minimalPost(1161, 'retry-timeout-post')], 200, ['X-WP-TotalPages' => '1']);
        });

        $preview = app(WordpressRestImporter::class)->dryRunPostsPage('https://old.example.test', perPage: 1, page: 1);

        $this->assertSame(1161, $preview['posts'][0]['wordpress_id']);
        $this->assertSame(2, $publishAttempts);
    }

    public function test_importer_dry_run_can_rehearse_all_wordpress_pages_without_writes(): void
    {
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return match ((int) ($query['page'] ?? 1)) {
                2 => Http::response([
                    $this->minimalPost(1202, 'dry-run-page-two', [
                        '_embedded' => [
                            'wp:featuredmedia' => [
                                ['source_url' => 'https://old.example.test/uploads/page-two.jpg'],
                            ],
                        ],
                    ]),
                ], 200, ['X-WP-TotalPages' => '2']),
                default => Http::response([
                    $this->minimalPost(1201, 'dry-run-page-one'),
                ], 200, ['X-WP-TotalPages' => '2']),
            };
        });

        $preview = app(WordpressRestImporter::class)->dryRunPosts('https://old.example.test', perPage: 1);

        $this->assertSame(2, $preview['total_pages']);
        $this->assertSame(2, $preview['pages_scanned']);
        $this->assertSame([1201, 1202], collect($preview['posts'])->pluck('wordpress_id')->all());
        $this->assertSame(2, $preview['summary']['posts']);
        $this->assertSame(1, $preview['summary']['missing_featured_images']);
        $this->assertSame(0, $preview['summary']['will_create_authors']);
        $this->assertDatabaseCount(Article::class, 0);
        $this->assertDatabaseCount(WordpressImportRecord::class, 0);
    }

    public function test_importer_dry_run_can_limit_pages_for_safe_live_smoke_checks(): void
    {
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return Http::response([
                $this->minimalPost(1300 + (int) ($query['page'] ?? 1), 'dry-run-limited-page'),
            ], 200, ['X-WP-TotalPages' => '5']);
        });

        $preview = app(WordpressRestImporter::class)->dryRunPosts('https://old.example.test', perPage: 1, maxPages: 2);

        $this->assertSame(5, $preview['total_pages']);
        $this->assertSame(2, $preview['pages_scanned']);
        $this->assertSame([1301, 1302], collect($preview['posts'])->pluck('wordpress_id')->all());
    }

    public function test_dry_run_all_command_outputs_aggregate_mapping_summary(): void
    {
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return match ((int) ($query['page'] ?? 1)) {
                2 => Http::response([$this->minimalPost(1402, 'dry-run-command-two')], 200, ['X-WP-TotalPages' => '2']),
                default => Http::response([$this->minimalPost(1401, 'dry-run-command-one')], 200, ['X-WP-TotalPages' => '2']),
            };
        });

        $this->artisan('wordpress:dry-run-all', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 1,
        ])
            ->expectsOutput('Total pages: 2')
            ->expectsOutput('Pages scanned: 2')
            ->expectsOutput('Posts scanned: 2')
            ->expectsOutput('Missing featured images: 2')
            ->expectsOutput('Authors to create: 0')
            ->assertSuccessful();
    }

    public function test_import_page_command_imports_only_the_requested_wordpress_page(): void
    {
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return match ((int) ($query['page'] ?? 1)) {
                2 => Http::response([$this->minimalPost(1502, 'page-two-only')], 200, ['X-WP-TotalPages' => '2']),
                default => Http::response([$this->minimalPost(1501, 'page-one-skipped')], 200, ['X-WP-TotalPages' => '2']),
            };
        });

        $this->artisan('wordpress:import-page', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 1,
            '--page' => 2,
        ])
            ->expectsOutput('Imported 1 WordPress posts from page 2. Failed 0.')
            ->assertSuccessful();

        $this->assertDatabaseMissing(Article::class, ['old_wordpress_id' => 1501]);
        $this->assertDatabaseHas(Article::class, ['old_wordpress_id' => 1502, 'slug' => 'page-two-only']);
    }

    public function test_import_batch_command_honors_limit_offset_and_page_progress(): void
    {
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return match ((int) ($query['page'] ?? 1)) {
                2 => Http::response([
                    $this->minimalPost(1703, 'batch-third'),
                    $this->minimalPost(1704, 'batch-fourth'),
                ], 200, ['X-WP-TotalPages' => '2']),
                default => Http::response([
                    $this->minimalPost(1701, 'batch-first'),
                    $this->minimalPost(1702, 'batch-second'),
                ], 200, ['X-WP-TotalPages' => '2']),
            };
        });

        $this->artisan('wordpress:import-batch', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 2,
            '--limit' => 2,
            '--offset' => 1,
            '--sleep' => 0,
        ])
            ->expectsOutput('Importing WordPress posts with limit 2, offset 1, per page 2.')
            ->expectsOutput('Page 1 of 2: imported 1, failed 0.')
            ->expectsOutput('Page 2 of 2: imported 1, failed 0.')
            ->expectsOutput('Imported 2 WordPress posts across 2 scanned pages. Failed 0.')
            ->assertSuccessful();

        $this->assertDatabaseMissing(Article::class, ['old_wordpress_id' => 1701]);
        $this->assertDatabaseHas(Article::class, ['old_wordpress_id' => 1702, 'slug' => 'batch-second']);
        $this->assertDatabaseHas(Article::class, ['old_wordpress_id' => 1703, 'slug' => 'batch-third']);
        $this->assertDatabaseMissing(Article::class, ['old_wordpress_id' => 1704]);
    }

    public function test_import_batch_command_caps_page_size_for_memory_safe_live_imports(): void
    {
        $requestedPerPages = [];

        Http::fake(function ($request) use (&$requestedPerPages) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $requestedPerPages[] = (int) ($query['per_page'] ?? 0);

            return Http::response([
                $this->minimalPost(1710, 'memory-safe-batch-post'),
            ], 200, ['X-WP-TotalPages' => '1']);
        });

        $this->artisan('wordpress:import-batch', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 50,
            '--limit' => 1,
            '--offset' => 0,
            '--sleep' => 0,
        ])
            ->expectsOutput('Importing WordPress posts with limit 1, offset 0, per page 20.')
            ->assertSuccessful();

        $this->assertSame([20], $requestedPerPages);
    }

    public function test_import_batch_command_keeps_wordpress_media_external_for_memory_safety(): void
    {
        $imageDownloadRequested = false;

        Http::fake(function ($request) use (&$imageDownloadRequested) {
            if (str_contains($request->url(), '/uploads/batch-cover.jpg')) {
                $imageDownloadRequested = true;

                return Http::response($this->transparentPng(), 200, ['Content-Type' => 'image/png']);
            }

            return Http::response([
                $this->minimalPost(1711, 'batch-external-media-post', [
                    '_embedded' => [
                        'wp:featuredmedia' => [
                            [
                                'id' => 9011,
                                'source_url' => 'https://old.example.test/uploads/batch-cover.jpg',
                                'alt_text' => 'Batch image',
                                'caption' => ['rendered' => '<p>Batch caption</p>'],
                                'mime_type' => 'image/png',
                                'media_details' => [
                                    'width' => 1200,
                                    'height' => 675,
                                    'filesize' => 1000,
                                ],
                            ],
                        ],
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']);
        });

        $this->artisan('wordpress:import-batch', [
            'base-url' => 'https://old.example.test',
            '--per-page' => 20,
            '--limit' => 1,
            '--offset' => 0,
            '--sleep' => 0,
        ])->assertSuccessful();

        $article = Article::query()->where('old_wordpress_id', 1711)->firstOrFail();
        $asset = $article->featuredImage()->firstOrFail();

        $this->assertFalse($imageDownloadRequested);
        $this->assertSame('external', $asset->disk);
        $this->assertSame('https://old.example.test/uploads/batch-cover.jpg', $asset->url);
    }

    public function test_failed_import_record_can_be_retried_from_its_stored_payload(): void
    {
        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 1601,
            'wordpress_type' => 'post',
            'status' => 'failed',
            'old_url' => 'https://old.example.test/retry-payload-post/',
            'source_payload' => $this->minimalPost(1601, 'retry-payload-post'),
            'warnings' => [],
            'error_message' => 'Original failure.',
        ]);

        $article = app(WordpressImportRetryService::class)->retry($record);

        $this->assertSame('retry-payload-post', $article->slug);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 1601,
            'wordpress_type' => 'post',
            'status' => 'imported',
            'error_message' => null,
            'new_url' => '/retry-payload-post',
        ]);
    }

    public function test_retry_failed_command_reports_imported_retries(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 1602,
            'wordpress_type' => 'post',
            'status' => 'failed',
            'old_url' => 'https://old.example.test/retry-command-post/',
            'source_payload' => $this->minimalPost(1602, 'retry-command-post'),
            'warnings' => [],
            'error_message' => 'Original failure.',
        ]);

        $this->artisan('wordpress:retry-failed', [
            'wordpress-id' => 1602,
        ])
            ->expectsOutput('Retried 1 failed WordPress import. Imported 1. Failed 0.')
            ->assertSuccessful();

        $this->assertDatabaseHas(Article::class, [
            'old_wordpress_id' => 1602,
            'slug' => 'retry-command-post',
        ]);
    }

    public function test_retry_failed_command_can_limit_retries_to_database_lock_failures(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 1603,
            'wordpress_type' => 'post',
            'status' => 'failed',
            'old_url' => 'https://old.example.test/retry-lock-post/',
            'source_payload' => $this->minimalPost(1603, 'retry-lock-post'),
            'warnings' => [],
            'error_message' => 'SQLSTATE[HY000]: General error: 5 database is locked',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 1604,
            'wordpress_type' => 'post',
            'status' => 'failed',
            'old_url' => 'https://old.example.test/retry-non-lock-post/',
            'source_payload' => $this->minimalPost(1604, 'retry-non-lock-post'),
            'warnings' => [],
            'error_message' => 'Missing title.',
        ]);

        $this->artisan('wordpress:retry-failed', [
            '--only-locks' => true,
        ])
            ->expectsOutput('Retried 1 failed WordPress import. Imported 1. Failed 0.')
            ->assertSuccessful();

        $this->assertDatabaseHas(Article::class, [
            'old_wordpress_id' => 1603,
            'slug' => 'retry-lock-post',
        ]);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 1604,
            'wordpress_type' => 'post',
            'status' => 'failed',
        ]);
        $this->assertDatabaseMissing(Article::class, ['old_wordpress_id' => 1604]);
    }

    public function test_importer_reuses_the_same_wordpress_author_for_multiple_posts(): void
    {
        $sharedAuthor = [
            'id' => 88,
            'name' => 'Shared WP Author',
            'slug' => 'shared-wp-author',
            'description' => 'Writes more than one article.',
            'link' => 'https://old.example.test/author/shared-wp-author/',
        ];

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(1610, 'shared-author-one', [
                    'author' => 88,
                    '_embedded' => ['author' => [$sharedAuthor]],
                ]),
                $this->minimalPost(1611, 'shared-author-two', [
                    'author' => 88,
                    '_embedded' => ['author' => [$sharedAuthor]],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 2);

        $author = Author::query()->where('slug', 'shared-wp-author')->firstOrFail();

        $this->assertSame(1, Author::query()->where('slug', 'shared-wp-author')->count());
        $this->assertSame(
            [$author->id, $author->id],
            Article::query()
                ->whereIn('old_wordpress_id', [1610, 1611])
                ->orderBy('old_wordpress_id')
                ->pluck('author_id')
                ->all(),
        );
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 88,
            'wordpress_type' => 'author',
            'importable_id' => $author->id,
            'status' => 'imported',
        ]);
    }

    public function test_importer_rewrites_internal_links_to_existing_imported_articles(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 301,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/stara-vrska/',
            'new_url' => '/nova-vrska',
            'status' => 'imported',
            'warnings' => [],
        ]);

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response([
                $this->minimalPost(302, 'article-with-link', [
                    'content' => [
                        'rendered' => '<p>Прочитај <a href="https://old.example.test/stara-vrska/">повеќе</a> и <a href="https://external.example/test">надворешно</a>.</p>',
                    ],
                ]),
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WordpressRestImporter::class)->importPosts('https://old.example.test', perPage: 1);

        $article = Article::query()->where('old_wordpress_id', 302)->firstOrFail();

        $this->assertStringContainsString('href="/nova-vrska"', $article->body_html);
        $this->assertStringContainsString('href="https://external.example/test"', $article->body_html);
    }

    public function test_importer_report_summarizes_failures_and_missing_featured_images(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 401,
            'wordpress_type' => 'post',
            'status' => 'failed',
            'old_url' => 'https://old.example.test/broken/',
            'source_payload' => ['id' => 401],
            'warnings' => [],
            'error_message' => 'Missing title',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 402,
            'wordpress_type' => 'post',
            'status' => 'imported',
            'old_url' => 'https://old.example.test/no-image/',
            'new_url' => '/no-image',
            'source_payload' => ['id' => 402],
            'warnings' => ['missing_featured_image'],
        ]);

        $report = app(WordpressRestImporter::class)->report();

        $this->assertSame(2, $report['total_post_records']);
        $this->assertSame(1, $report['imported_count']);
        $this->assertSame(1, $report['failed_count']);
        $this->assertSame(0, $report['pending_count']);
        $this->assertSame(0, $report['skipped_count']);
        $this->assertSame(1, $report['missing_featured_image_count']);
        $this->assertSame('https://old.example.test/broken/', $report['failed'][0]['old_url']);
        $this->assertSame('/no-image', $report['missing_featured_images'][0]['new_url']);
        $this->assertSame([402, 401], collect($report['latest_records'])->pluck('wordpress_id')->all());
        $this->assertSame('missing_featured_image', $report['latest_records'][0]['warnings'][0]);
        $this->assertSame('Missing title', $report['latest_records'][1]['error_message']);
    }

    public function test_post_import_cleanup_rewrites_late_internal_links_and_creates_redirects_idempotently(): void
    {
        $source = Article::factory()->create([
            'slug' => 'source-article',
            'body_html' => '<p><a href="https://old.example.test/target-article/">Внатрешно</a> <a href="https://external.example/test">Надворешно</a></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 501,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $source->id,
            'old_url' => 'https://old.example.test/source-article/',
            'new_url' => '/source-article',
            'status' => 'imported',
            'warnings' => [],
        ]);

        $target = Article::factory()->create([
            'slug' => 'target-article',
            'body_html' => '<p>Target.</p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 502,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $target->id,
            'old_url' => 'https://old.example.test/target-article/',
            'new_url' => '/target-article',
            'status' => 'imported',
            'warnings' => [],
        ]);

        $firstRun = app(WordpressRestImporter::class)->finalizeImportedContent();
        $secondRun = app(WordpressRestImporter::class)->finalizeImportedContent();

        $this->assertSame([
            'author_legacy_urls_backfilled' => 0,
            'author_legacy_urls_unresolved' => 0,
            'rewritten_articles' => 1,
            'redirects_created_or_updated' => 0,
        ], $firstRun);
        $this->assertSame([
            'author_legacy_urls_backfilled' => 0,
            'author_legacy_urls_unresolved' => 0,
            'rewritten_articles' => 0,
            'redirects_created_or_updated' => 0,
        ], $secondRun);
        $this->assertStringContainsString('href="/target-article"', $source->fresh()->body_html);
        $this->assertStringContainsString('href="https://external.example/test"', $source->fresh()->body_html);
        $this->assertSame(0, Redirect::query()->count());
    }

    public function test_post_import_cleanup_creates_redirects_for_all_url_bearing_import_types_with_real_archive_paths(): void
    {
        $article = Article::factory()->create([
            'body_html' => '<p><a href="https://razbudise.mk/kategorija/%D0%BA%D1%83%D0%BB%D1%82%D1%83%D1%80%D0%B0/">Категорија</a> <a href="https://razbudise.mk/author/%D0%B5%D0%BB%D0%B5%D0%BD%D0%B0/">Автор</a></p>',
        ]);
        $records = [
            [701, 'post', 'https://razbudise.mk/stara-objava/', '/nova-objava'],
            [702, 'category', 'https://razbudise.mk/kategorija/%D0%BA%D1%83%D0%BB%D1%82%D1%83%D1%80%D0%B0/', '/category/kultura'],
            [703, 'tag', 'https://razbudise.mk/tag/%D0%B1%D0%B8%D1%82%D0%BE%D0%BB%D0%B0/', '/tag/bitola'],
            [704, 'author', 'https://razbudise.mk/author/%D0%B5%D0%BB%D0%B5%D0%BD%D0%B0/', '/profil/elena'],
            [705, 'page', 'https://razbudise.mk/za-nas/', '/za-nas'],
            [706, 'tag', 'https://razbudise.mk/tag/%D0%BD%D0%B0%D1%81%D1%82%D0%B0%D0%BD%D0%B8/', '/tag/настани'],
        ];

        foreach ($records as [$wordpressId, $wordpressType, $oldUrl, $newUrl]) {
            $attributes = [
                'wordpress_id' => $wordpressId,
                'wordpress_type' => $wordpressType,
                'old_url' => $oldUrl,
                'new_url' => $newUrl,
                'status' => 'imported',
                'warnings' => [],
            ];

            if ($wordpressType === 'post') {
                $attributes['importable_type'] = Article::class;
                $attributes['importable_id'] = $article->id;
            }

            WordpressImportRecord::query()->create($attributes);
        }

        $summary = app(WordpressRestImporter::class)->finalizeImportedContent();

        $this->assertSame(1, $summary['rewritten_articles']);
        $this->assertSame(4, $summary['redirects_created_or_updated']);
        $this->assertDatabaseCount(Redirect::class, 4);
        $this->assertDatabaseHas(Redirect::class, [
            'source_url' => 'https://razbudise.mk/kategorija/%D0%BA%D1%83%D0%BB%D1%82%D1%83%D1%80%D0%B0/',
            'target_url' => '/category/kultura',
            'status_code' => 301,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas(Redirect::class, [
            'source_url' => 'https://razbudise.mk/author/%D0%B5%D0%BB%D0%B5%D0%BD%D0%B0/',
            'target_url' => '/profil/elena',
        ]);
        $this->assertDatabaseMissing(Redirect::class, ['source_url' => 'https://razbudise.mk/za-nas/']);
        $this->assertDatabaseMissing(Redirect::class, [
            'source_url' => 'https://razbudise.mk/tag/%D0%BD%D0%B0%D1%81%D1%82%D0%B0%D0%BD%D0%B8/',
        ]);
        $this->assertStringContainsString('href="/category/kultura"', $article->fresh()->body_html);
        $this->assertStringContainsString('href="/profil/elena"', $article->fresh()->body_html);

        $this->getJson('/api/redirects/resolve?'.http_build_query([
            'url' => 'https://razbudise.mk/kategorija/%D0%BA%D1%83%D0%BB%D1%82%D1%83%D1%80%D0%B0/',
        ]))
            ->assertOk()
            ->assertJsonPath('data.target_url', '/category/kultura')
            ->assertJsonPath('data.status_code', 301);
    }

    public function test_finalization_backfills_four_missing_author_urls_from_source_slugs_before_creating_redirects(): void
    {
        Http::fake([
            'https://razbudise.mk/wp-json/wp/v2/users/801' => Http::response([
                'id' => 801,
                'name' => 'Source author 801',
                'slug' => 'elena',
                'link' => 'https://razbudise.mk/author/elena/',
            ]),
            'https://razbudise.mk/wp-json/wp/v2/users/*' => Http::response([], 404),
        ]);

        $authors = [
            [801, 'elena', '/profil/elena'],
            [802, 'marija', '/profil/marija'],
            [803, 'nikola', '/profil/nikola'],
            [804, '%D0%B1%D0%B8%D0%BB%D1%98%D0%B0%D0%BD%D0%B0', '/profil/biljana'],
        ];

        foreach ($authors as [$wordpressId, $sourceSlug, $newUrl]) {
            WordpressImportRecord::query()->create([
                'wordpress_id' => $wordpressId,
                'wordpress_type' => 'author',
                'old_url' => null,
                'new_url' => $newUrl,
                'status' => 'imported',
                'source_payload' => [
                    'id' => $wordpressId,
                    'name' => "Source author {$wordpressId}",
                    'slug' => $sourceSlug,
                ],
                'warnings' => [],
            ]);
        }

        $summary = app(WordpressRestImporter::class)->finalizeImportedContent('https://razbudise.mk');

        $this->assertSame(4, $summary['author_legacy_urls_backfilled']);
        $this->assertSame(0, $summary['author_legacy_urls_unresolved']);
        $this->assertSame(4, $summary['redirects_created_or_updated']);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 801,
            'wordpress_type' => 'author',
            'old_url' => 'https://razbudise.mk/author/elena/',
        ]);
        $this->assertDatabaseHas(WordpressImportRecord::class, [
            'wordpress_id' => 804,
            'wordpress_type' => 'author',
            'old_url' => 'https://razbudise.mk/author/%D0%B1%D0%B8%D0%BB%D1%98%D0%B0%D0%BD%D0%B0/',
        ]);
        $this->assertDatabaseCount(Redirect::class, 4);

        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'author')
            ->where('wordpress_id', 801)
            ->firstOrFail();

        $this->assertSame(
            'wordpress_users_api',
            data_get($record->source_payload, '_razbudise.legacy_author_url_evidence.evidence_source'),
        );
        $this->assertSame(
            'source_link',
            data_get($record->source_payload, '_razbudise.legacy_author_url_evidence.url_source'),
        );
        $this->assertNull(data_get($record->source_payload, '_razbudise.legacy_author_url_evidence.source_base_url'));
    }

    public function test_finalization_does_not_guess_author_legacy_url_from_local_profile_only(): void
    {
        Http::fake([
            'https://razbudise.mk/wp-json/wp/v2/users/901' => Http::response([], 404),
        ]);
        $author = Author::factory()->create(['slug' => 'local-only-author']);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 901,
            'wordpress_type' => 'author',
            'importable_type' => Author::class,
            'importable_id' => $author->id,
            'old_url' => null,
            'new_url' => '/profil/local-only-author',
            'status' => 'imported',
            'source_payload' => ['id' => 901, 'name' => 'Local only author'],
            'warnings' => [],
        ]);

        $summary = app(WordpressRestImporter::class)->finalizeImportedContent('https://razbudise.mk');

        $this->assertSame(0, $summary['author_legacy_urls_backfilled']);
        $this->assertSame(1, $summary['author_legacy_urls_unresolved']);
        $this->assertDatabaseMissing(Redirect::class, ['target_url' => '/profil/local-only-author']);
        $this->assertNull(WordpressImportRecord::query()->where('wordpress_id', 901)->value('old_url'));
    }

    public function test_post_import_cleanup_command_reports_rewrites_and_redirects(): void
    {
        $article = Article::factory()->create([
            'slug' => 'cleanup-command',
            'body_html' => '<p>No links.</p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 601,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/cleanup-command/',
            'new_url' => '/cleanup-command',
            'status' => 'imported',
            'warnings' => [],
        ]);

        $this->artisan('wordpress:finalize-import')
            ->expectsOutput('Author legacy URLs backfilled: 0')
            ->expectsOutput('Author legacy URLs unresolved: 0')
            ->expectsOutput('Rewritten articles: 0')
            ->expectsOutput('Redirects created or updated: 0')
            ->assertSuccessful();
    }

    public function test_wordpress_imported_search_sync_indexes_only_imported_published_articles(): void
    {
        Article::factory()->published()->create([
            'old_wordpress_id' => 701,
        ]);

        Article::factory()->create([
            'old_wordpress_id' => 702,
            'status' => 'draft',
            'published_at' => null,
        ]);

        Article::factory()->published()->create([
            'old_wordpress_id' => null,
        ]);

        $this->artisan('wordpress:sync-imported-search')
            ->expectsOutput('Queued 1 imported published article for the search index.')
            ->assertSuccessful();
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
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
        );
    }

    private function avifImage(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 26, 38, 52);
        imagefill($image, 0, 0, $background);
        ob_start();
        $saved = imageavif($image, null, 75);
        $contents = ob_get_clean();
        imagedestroy($image);

        if (! $saved || ! is_string($contents) || $contents === '') {
            throw new \RuntimeException('Unable to create AVIF fixture.');
        }

        return $contents;
    }
}
