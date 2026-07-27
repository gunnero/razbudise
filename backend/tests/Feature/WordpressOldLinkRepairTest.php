<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\MediaAsset;
use App\Models\WordpressImportRecord;
use App\Services\Wordpress\WordpressContentSanitizer;
use App\Services\Wordpress\WordpressOldLinkRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WordpressOldLinkRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_old_upload_links_without_mutating_articles(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 991,
            'body_html' => '<p><a href="https://old.example.test/wp-content/uploads/2026/old.pdf">Download</a></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 991,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $summary = app(WordpressOldLinkRepairService::class)->repair(apply: false);

        $this->assertSame(1, $summary['references_found']);
        $this->assertSame(0, $summary['rewritten_articles']);
        $this->assertSame('https://old.example.test/wp-content/uploads/2026/old.pdf', $summary['items'][0]['source_url']);
        $this->assertStringContainsString('old.example.test/wp-content/uploads', $article->fresh()->body_html);
    }

    public function test_dry_run_ignores_external_wordpress_upload_links(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 990,
            'body_html' => '<p><a href="https://publisher.example/wp-content/uploads/report.pdf">External report</a></p><img src="https://www.old.example.test/wp-content/uploads/local.jpg">',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 990,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $summary = app(WordpressOldLinkRepairService::class)->repair(apply: false);

        $this->assertSame(1, $summary['references_found']);
        $this->assertSame('https://www.old.example.test/wp-content/uploads/local.jpg', $summary['items'][0]['source_url']);
        $this->assertStringContainsString('https://publisher.example/wp-content/uploads/report.pdf', $article->fresh()->body_html);
    }

    public function test_apply_downloads_old_upload_media_and_rewrites_article_body(): void
    {
        Storage::fake('public');
        Http::fake([
            'old.example.test/wp-content/uploads/2026/photo.jpg' => Http::response($this->transparentPng(), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 992,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/photo.jpg" alt="Стара фотографија"></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 992,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['manual_review'],
        ]);

        $summary = app(WordpressOldLinkRepairService::class)->repair(apply: true);
        $asset = MediaAsset::query()->where('metadata->source_url', 'https://old.example.test/wp-content/uploads/2026/photo.jpg')->firstOrFail();

        $this->assertSame(1, $summary['references_found']);
        $this->assertSame(1, $summary['downloaded_media']);
        $this->assertSame(1, $summary['rewritten_articles']);
        $this->assertStringContainsString((string) $asset->url, $article->fresh()->body_html);
        $this->assertStringNotContainsString('old.example.test/wp-content/uploads', $article->fresh()->body_html);
        $this->assertSame('Стара фотографија', $asset->alt_text);
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_apply_records_failed_repair_warning_without_discarding_source_link(): void
    {
        Http::fake([
            'old.example.test/wp-content/uploads/2026/missing.jpg' => Http::response('', 404),
        ]);

        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 993,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/missing.jpg"></p>',
        ]);

        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 993,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $summary = app(WordpressOldLinkRepairService::class)->repair(apply: true);

        $this->assertSame(1, $summary['failed_downloads']);
        $this->assertStringContainsString('missing.jpg', $article->fresh()->body_html);
        $this->assertContains('old_link_repair_failed', $record->fresh()->warnings);
        $this->assertContains('manual_review', $record->fresh()->warnings);
    }

    public function test_apply_reports_failed_download_attempt_urls_and_reason(): void
    {
        Http::fake([
            'old.example.test/wp-content/uploads/2026/missing-report.jpg' => Http::response('', 404),
        ]);

        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 990,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/missing-report.jpg"></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 990,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $summary = app(WordpressOldLinkRepairService::class)->repair(apply: true);

        $this->assertSame('failed', $summary['items'][0]['status']);
        $this->assertSame(['https://old.example.test/wp-content/uploads/2026/missing-report.jpg'], $summary['items'][0]['attempted_urls']);
        $this->assertSame(
            ['https://old.example.test/wp-content/uploads/2026/missing-report.jpg' => 'HTTP 404 from source media URL.'],
            $summary['items'][0]['failure_reasons'],
        );
        $this->assertStringContainsString('HTTP 404', $summary['items'][0]['message']);
    }

    public function test_apply_groups_repeated_failed_sources_in_summary(): void
    {
        Http::fake([
            'old.example.test/wp-content/uploads/2026/shared-missing.jpg' => Http::response('', 404),
        ]);

        $firstArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 987,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/shared-missing.jpg"></p>',
        ]);
        $secondArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 988,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/shared-missing.jpg"></p>',
        ]);

        foreach ([$firstArticle, $secondArticle] as $article) {
            WordpressImportRecord::query()->create([
                'wordpress_id' => $article->old_wordpress_id,
                'wordpress_type' => 'post',
                'importable_type' => Article::class,
                'importable_id' => $article->id,
                'old_url' => 'https://old.example.test/story/',
                'new_url' => '/'.$article->slug,
                'status' => 'imported',
                'warnings' => [],
            ]);
        }

        $summary = app(WordpressOldLinkRepairService::class)->repair(apply: true);

        $this->assertSame(2, $summary['failed_downloads']);
        $this->assertCount(1, $summary['broken_sources']);
        $this->assertSame('https://old.example.test/wp-content/uploads/2026/shared-missing.jpg', $summary['broken_sources'][0]['source_url']);
        $this->assertSame(2, $summary['broken_sources'][0]['occurrence_count']);
        $this->assertSame(2, $summary['broken_sources'][0]['article_count']);
        $this->assertContains($firstArticle->slug, $summary['broken_sources'][0]['sample_articles']);
        $this->assertSame('HTTP 404 from source media URL.', $summary['broken_sources'][0]['reason']);
    }

    public function test_apply_can_skip_known_failed_articles_to_continue_repair_window(): void
    {
        Storage::fake('public');
        Http::fake([
            'old.example.test/wp-content/uploads/2026/reachable.jpg' => Http::response($this->transparentPng(), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $knownBrokenArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 985,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/still-missing.jpg"></p>',
        ]);
        $repairableArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 986,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/reachable.jpg"></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 985,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $knownBrokenArticle->id,
            'old_url' => 'https://old.example.test/known-broken/',
            'new_url' => '/'.$knownBrokenArticle->slug,
            'status' => 'imported',
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 986,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $repairableArticle->id,
            'old_url' => 'https://old.example.test/repairable/',
            'new_url' => '/'.$repairableArticle->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $summary = app(WordpressOldLinkRepairService::class)->repair(apply: true, limit: 1, skipKnownFailed: true);

        $this->assertSame(1, $summary['articles_checked']);
        $this->assertSame(1, $summary['skipped_known_failed_articles']);
        $this->assertSame(1, $summary['rewritten_articles']);
        $this->assertSame(0, $summary['failed_downloads']);
        $this->assertStringContainsString('/wp-content/uploads/', $knownBrokenArticle->fresh()->body_html);
        $this->assertStringNotContainsString('/wp-content/uploads/', $repairableArticle->fresh()->body_html);
    }

    public function test_repair_reports_progress_checkpoints_without_changing_summary(): void
    {
        $firstArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 984,
            'body_html' => '<p>No old media here.</p>',
        ]);
        $secondArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 983,
            'body_html' => '<p><a href="https://old.example.test/wp-content/uploads/2026/menu.pdf">Menu</a></p>',
        ]);

        foreach ([$firstArticle, $secondArticle] as $article) {
            WordpressImportRecord::query()->create([
                'wordpress_id' => $article->old_wordpress_id,
                'wordpress_type' => 'post',
                'importable_type' => Article::class,
                'importable_id' => $article->id,
                'old_url' => 'https://old.example.test/progress/',
                'new_url' => '/'.$article->slug,
                'status' => 'imported',
                'warnings' => [],
            ]);
        }

        $events = [];
        $summary = app(WordpressOldLinkRepairService::class)->repair(
            apply: false,
            progressEvery: 1,
            onProgress: function (array $progress) use (&$events): void {
                $events[] = $progress;
            },
        );

        $this->assertSame(2, $summary['articles_checked']);
        $this->assertSame(1, $summary['references_found']);
        $this->assertCount(2, $events);
        $this->assertSame(1, $events[0]['articles_checked']);
        $this->assertSame(2, $events[0]['total_articles']);
        $this->assertSame($firstArticle->slug, $events[0]['slug']);
        $this->assertSame(0, $events[0]['references_found']);
        $this->assertSame(2, $events[1]['articles_checked']);
        $this->assertSame($secondArticle->slug, $events[1]['slug']);
        $this->assertSame(1, $events[1]['references_found']);
    }

    public function test_apply_clears_stale_repair_warning_after_old_upload_links_are_fixed(): void
    {
        Storage::fake('public');
        Http::fake([
            'old.example.test/wp-content/uploads/2026/recovered.jpg' => Http::response($this->transparentPng(), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 998,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/recovered.jpg"></p>',
        ]);

        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 998,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);

        app(WordpressOldLinkRepairService::class)->repair(apply: true);

        $this->assertStringNotContainsString('/wp-content/uploads/', $article->fresh()->body_html);
        $this->assertSame([], $record->fresh()->warnings);
    }

    public function test_manual_replacement_swaps_only_the_targeted_old_upload_reference_with_existing_media(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 980,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/photo-300x200.jpg"></p><p><a href="https://old.example.test/wp-content/uploads/2026/menu.pdf">Menu</a></p>',
        ]);
        $otherArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 981,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/photo-300x200.jpg"></p>',
        ]);

        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 980,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/manual-replacement/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 981,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $otherArticle->id,
            'old_url' => 'https://old.example.test/other-manual-replacement/',
            'new_url' => '/'.$otherArticle->slug,
            'status' => 'imported',
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);
        $replacement = MediaAsset::factory()->create([
            'url' => '/storage/media/replacement-photo.jpg',
        ]);

        $result = app(WordpressOldLinkRepairService::class)->replaceOldUploadReference(
            $article,
            'https://old.example.test/wp-content/uploads/2026/photo.jpg',
            $replacement,
        );

        $this->assertSame(1, $result['replaced_references']);
        $this->assertTrue($result['remaining_old_upload_references']);
        $this->assertStringContainsString('/storage/media/replacement-photo.jpg', $article->fresh()->body_html);
        $this->assertStringContainsString('https://old.example.test/wp-content/uploads/2026/menu.pdf', $article->fresh()->body_html);
        $this->assertStringContainsString('https://old.example.test/wp-content/uploads/2026/photo-300x200.jpg', $otherArticle->fresh()->body_html);
        $this->assertContains('old_link_repair_failed', $record->fresh()->warnings);
    }

    public function test_manual_replacement_clears_failed_warning_and_removes_article_from_broken_report_when_fixed(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 979,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/only-broken.jpg"></p>',
        ]);
        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 979,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/only-broken/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);
        $replacement = MediaAsset::factory()->create([
            'url' => '/storage/media/fixed-photo.jpg',
        ]);
        $service = app(WordpressOldLinkRepairService::class);

        $this->assertNotEmpty($service->brokenUploadReportRows());

        $result = $service->replaceOldUploadReference(
            $article,
            'https://old.example.test/wp-content/uploads/2026/only-broken.jpg',
            $replacement,
        );

        $this->assertSame(1, $result['replaced_references']);
        $this->assertFalse($result['remaining_old_upload_references']);
        $this->assertStringNotContainsString('/wp-content/uploads/', $article->fresh()->body_html);
        $this->assertSame([], $record->fresh()->warnings);
        $this->assertSame([], $service->brokenUploadReportRows());
    }

    public function test_manual_removal_deletes_only_the_targeted_broken_reference(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 978,
            'body_html' => '<p>Before</p><figure><img src="https://old.example.test/wp-content/uploads/2026/remove-me.jpg" alt="Remove me"></figure><p><img src="https://old.example.test/wp-content/uploads/2026/keep-me.jpg"></p>',
        ]);
        $otherArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 977,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/remove-me.jpg"></p>',
        ]);
        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 978,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/manual-removal/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 977,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $otherArticle->id,
            'old_url' => 'https://old.example.test/other-manual-removal/',
            'new_url' => '/'.$otherArticle->slug,
            'status' => 'imported',
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);

        $result = app(WordpressOldLinkRepairService::class)->removeOldUploadReference(
            $article,
            'https://old.example.test/wp-content/uploads/2026/remove-me.jpg',
        );

        $this->assertSame(1, $result['removed_references']);
        $this->assertTrue($result['remaining_old_upload_references']);
        $this->assertStringNotContainsString('remove-me.jpg', $article->fresh()->body_html);
        $this->assertStringContainsString('keep-me.jpg', $article->fresh()->body_html);
        $this->assertStringContainsString('remove-me.jpg', $otherArticle->fresh()->body_html);
        $this->assertContains('old_link_repair_failed', $record->fresh()->warnings);
    }

    public function test_manual_removal_clears_failed_warning_when_no_old_uploads_remain(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 976,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/remove-only.jpg"></p>',
        ]);
        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 976,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/remove-only/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['old_link_repair_failed', 'manual_review'],
        ]);

        $result = app(WordpressOldLinkRepairService::class)->removeOldUploadReference(
            $article,
            'https://old.example.test/wp-content/uploads/2026/remove-only.jpg',
        );

        $this->assertSame(1, $result['removed_references']);
        $this->assertFalse($result['remaining_old_upload_references']);
        $this->assertStringNotContainsString('/wp-content/uploads/', $article->fresh()->body_html);
        $this->assertSame([], $record->fresh()->warnings);
    }

    public function test_manual_removal_clears_resolved_inline_image_review_warnings_when_no_old_uploads_remain(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 975,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/remove-inline-warning.jpg"></p>',
        ]);
        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 975,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/remove-inline-warning/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['inline_image_download_failed', 'old_link_repair_failed', 'manual_review'],
        ]);

        app(WordpressOldLinkRepairService::class)->removeOldUploadReference(
            $article,
            'https://old.example.test/wp-content/uploads/2026/remove-inline-warning.jpg',
        );

        $this->assertStringNotContainsString('/wp-content/uploads/', $article->fresh()->body_html);
        $this->assertSame([], $record->fresh()->warnings);
    }

    public function test_manual_removal_preserves_manual_review_when_other_review_warnings_remain(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 974,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/remove-with-embed-warning.jpg"></p>',
        ]);
        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 974,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/remove-with-embed-warning/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['broken_embed', 'inline_image_download_failed', 'old_link_repair_failed', 'unsafe_html_removed', 'trusted_iframe_hardened', 'trusted_embed_script_removed', 'manual_review'],
        ]);

        app(WordpressOldLinkRepairService::class)->removeOldUploadReference(
            $article,
            'https://old.example.test/wp-content/uploads/2026/remove-with-embed-warning.jpg',
        );

        $this->assertSame(['broken_embed', 'unsafe_html_removed', 'trusted_iframe_hardened', 'trusted_embed_script_removed', 'manual_review'], $record->fresh()->warnings);
    }

    public function test_resolved_media_review_warning_command_clears_review_rows_without_old_upload_refs(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 973,
            'body_html' => '<p>Image was removed after editorial review.</p>',
        ]);
        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 973,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/resolved-inline-warning/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['inline_image_download_failed', 'manual_review'],
        ]);

        $this->artisan('wordpress:resolve-media-review-warnings')
            ->expectsOutput('Mode: dry run')
            ->expectsOutput('Records checked: 1')
            ->expectsOutput('Records fixable: 1')
            ->expectsOutput('Records updated: 0')
            ->assertSuccessful();
        $this->assertSame(['inline_image_download_failed', 'manual_review'], $record->fresh()->warnings);

        $this->artisan('wordpress:resolve-media-review-warnings --apply')
            ->expectsOutput('Mode: apply')
            ->expectsOutput('Records checked: 1')
            ->expectsOutput('Records fixable: 1')
            ->expectsOutput('Records updated: 1')
            ->assertSuccessful();

        $this->assertSame([], $record->fresh()->warnings);
    }

    public function test_resolved_media_review_warning_command_skips_records_with_old_upload_refs(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 972,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/still-broken.jpg"></p>',
        ]);
        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 972,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/still-broken/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['inline_image_download_failed', 'manual_review'],
        ]);

        $this->artisan('wordpress:resolve-media-review-warnings --apply')
            ->expectsOutput('Records fixable: 0')
            ->expectsOutput('Skipped with old upload refs: 1')
            ->assertSuccessful();

        $this->assertSame(['inline_image_download_failed', 'manual_review'], $record->fresh()->warnings);
    }

    public function test_download_media_skips_oversized_wordpress_responses(): void
    {
        Storage::fake('public');
        Http::fake([
            'old.example.test/wp-content/uploads/2026/huge.jpg' => Http::response('fake-image-body', 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => '8000001',
            ]),
        ]);

        $asset = app(WordpressContentSanitizer::class)->downloadMedia('https://old.example.test/wp-content/uploads/2026/huge.jpg');

        $this->assertNull($asset);
        $this->assertSame(0, MediaAsset::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_apply_falls_back_to_resized_literal_when_canonical_original_is_oversized(): void
    {
        Storage::fake('public');
        Http::fake([
            'old.example.test/wp-content/uploads/2026/large-photo.jpg' => Http::response('too-large', 200, [
                'Content-Type' => 'image/jpeg',
                'Content-Length' => '8000001',
            ]),
            'old.example.test/wp-content/uploads/2026/large-photo-300x200.jpg' => Http::response($this->transparentPng(), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 999,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/large-photo-300x200.jpg"></p>',
        ]);

        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 999,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $summary = app(WordpressOldLinkRepairService::class)->repair(apply: true);
        $asset = MediaAsset::query()->first();

        $this->assertNotNull($asset);
        $this->assertSame(1, $summary['downloaded_media']);
        $this->assertSame(0, $summary['failed_downloads']);
        $this->assertSame('https://old.example.test/wp-content/uploads/2026/large-photo-300x200.jpg', $asset->metadata['source_url']);
        $this->assertStringContainsString((string) $asset->url, $article->fresh()->body_html);
        $this->assertStringNotContainsString('/wp-content/uploads/', $article->fresh()->body_html);
        $this->assertSame([], $record->fresh()->warnings);
    }

    public function test_old_link_export_returns_flat_rows(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 994,
            'body_html' => '<p><a href="/wp-content/uploads/2026/menu.pdf">Menu</a></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 994,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $rows = app(WordpressOldLinkRepairService::class)->exportRows();

        $this->assertSame([
            'article_id' => $article->id,
            'wordpress_id' => 994,
            'title' => $article->title,
            'slug' => $article->slug,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'source_url' => 'https://old.example.test/wp-content/uploads/2026/menu.pdf',
            'literal' => '/wp-content/uploads/2026/menu.pdf',
            'reason' => 'unresolved_old_wordpress_upload',
            'status' => 'pending',
            'message' => 'Old WordPress upload reference is unresolved.',
        ], $rows[0]);
    }

    public function test_resized_wordpress_image_variants_use_canonical_source_url_for_downloads(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 995,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/photo-300x200.jpg"></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 995,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $rows = app(WordpressOldLinkRepairService::class)->exportRows();

        $this->assertSame('https://old.example.test/wp-content/uploads/2026/photo.jpg', $rows[0]['source_url']);
        $this->assertSame('https://old.example.test/wp-content/uploads/2026/photo-300x200.jpg', $rows[0]['literal']);
    }

    public function test_resized_cyrillic_wordpress_image_variants_keep_utf8_source_url(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 997,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/Првиот-чекор-на-промената-240x300.jpg"></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 997,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $rows = app(WordpressOldLinkRepairService::class)->exportRows();

        $this->assertSame('https://old.example.test/wp-content/uploads/2026/Првиот-чекор-на-промената.jpg', $rows[0]['source_url']);
    }

    public function test_raw_old_upload_references_trim_smart_quote_punctuation(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 971,
            'body_html' => '<p>Фото https://old.example.test/wp-content/uploads/2026/photo.jpg”</p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 971,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/smart-quote/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $rows = app(WordpressOldLinkRepairService::class)->exportRows();

        $this->assertSame('https://old.example.test/wp-content/uploads/2026/photo.jpg', $rows[0]['source_url']);
        $this->assertSame('https://old.example.test/wp-content/uploads/2026/photo.jpg', $rows[0]['literal']);
    }

    public function test_srcset_wordpress_upload_entries_are_exported_individually(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 996,
            'body_html' => '<p><img src="/storage/local.jpg" srcset="https://old.example.test/wp-content/uploads/2026/photo-300x200.jpg 300w, https://old.example.test/wp-content/uploads/2026/photo-768x512.jpg 768w"></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 996,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/story/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $rows = app(WordpressOldLinkRepairService::class)->exportRows();

        $this->assertCount(2, $rows);
        $this->assertSame('https://old.example.test/wp-content/uploads/2026/photo.jpg', $rows[0]['source_url']);
        $this->assertSame('https://old.example.test/wp-content/uploads/2026/photo-300x200.jpg', $rows[0]['literal']);
        $this->assertSame('https://old.example.test/wp-content/uploads/2026/photo-768x512.jpg', $rows[1]['literal']);
    }

    private function transparentPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAF/gL+Xjz1WQAAAABJRU5ErkJggg==', true) ?: '';
    }
}
