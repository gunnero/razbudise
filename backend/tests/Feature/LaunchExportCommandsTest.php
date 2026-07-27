<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdImpression;
use App\Models\AdZone;
use App\Models\Article;
use App\Models\MediaAsset;
use App\Models\Redirect;
use App\Models\WordpressImportRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LaunchExportCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_wordpress_review_export_command_prints_csv_rows(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 8801,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/broken-export/',
            'status' => 'failed',
            'source_payload' => ['id' => 8801],
            'warnings' => [],
            'error_message' => 'Missing title',
        ]);

        $this->artisan('wordpress:review-export')
            ->expectsOutput('issue,wordpress_id,title,slug,old_url,new_url,error_message,warnings')
            ->expectsOutputToContain('failed_import,8801')
            ->assertSuccessful();
    }

    public function test_redirect_qa_export_command_prints_csv_rows(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 8802,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/missing-export-old/',
            'new_url' => '/missing-export-new',
            'status' => 'imported',
            'warnings' => [],
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/temp-export/',
            'target_url' => '/temp-export',
            'status_code' => 302,
            'is_active' => true,
        ]);

        $exitCode = Artisan::call('redirects:qa-export');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('issue,source_url,target_url,status_code,is_active,wordpress_id,details', $output);
        $this->assertStringContainsString('missing,https://old.example.test/missing-export-old/,/missing-export-new', $output);
        $this->assertStringContainsString('non_301,https://old.example.test/temp-export/,/temp-export,302', $output);
        $this->assertStringContainsString('Create a 301 redirect for WordPress post 8802', $output);
        $this->assertStringContainsString('Change this active redirect to HTTP 301 before launch', $output);
    }

    public function test_ad_campaign_export_command_prints_csv_rows(): void
    {
        $campaign = AdCampaign::factory()->create([
            'name' => 'CSV launch campaign',
            'status' => 'active',
            'start_at' => '2026-06-01 00:00:00',
            'end_at' => '2026-06-30 23:59:59',
        ]);

        AdImpression::query()->create([
            'campaign_id' => $campaign->id,
            'occurred_at' => '2026-06-05 10:00:00',
        ]);

        $this->artisan('ads:campaign-export', [
            '--from' => '2026-06-01',
            '--to' => '2026-06-30',
        ])
            ->expectsOutput('campaign_id,campaign,advertiser,status,impressions,clicks,ctr_percent')
            ->expectsOutputToContain('CSV launch campaign')
            ->assertSuccessful();
    }

    public function test_ad_zone_inventory_export_command_prints_csv_rows(): void
    {
        config(['ads.required_zone_keys' => ['home_top_billboard']]);
        $zone = AdZone::factory()->create([
            'key' => 'home_top_billboard',
            'name' => 'Home top billboard',
            'page_context' => 'home',
        ]);

        $campaign = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'zone_id' => $zone->id,
            'name' => 'Homepage billboard',
            'type' => 'image',
            'width' => 728,
            'height' => 90,
            'is_fallback' => false,
            'is_active' => true,
        ]);

        $this->artisan('ads:zone-inventory-export')
            ->expectsOutput('zone_key,zone_name,page_context,is_active,allowed_sizes,active_creatives,eligible_creatives,fallback_creatives,global_fallback_creatives,has_launch_delivery,delivery_status')
            ->expectsOutputToContain('home_top_billboard')
            ->assertSuccessful();
    }

    public function test_ad_zone_inventory_export_fails_when_required_zone_cannot_deliver(): void
    {
        config(['ads.required_zone_keys' => ['home_top_billboard']]);
        AdZone::factory()->create([
            'key' => 'home_top_billboard',
            'name' => 'Home top billboard',
            'page_context' => 'home',
            'is_active' => true,
        ]);

        $this->artisan('ads:zone-inventory-export')
            ->expectsOutputToContain('home_top_billboard')
            ->assertFailed();
    }

    public function test_media_quality_export_command_prints_csv_rows(): void
    {
        MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/missing-export.jpg',
            'url' => '/storage/media/missing-export.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => null,
            'width' => null,
            'height' => null,
            'metadata' => [],
        ]);

        $this->artisan('media:quality-export')
            ->expectsOutput('issue,media_asset_id,path,url,message')
            ->expectsOutputToContain('missing_alt_text')
            ->assertSuccessful();
    }

    public function test_wordpress_old_link_export_command_prints_csv_rows(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 8803,
            'body_html' => '<p><a href="https://old.example.test/wp-content/uploads/file.pdf">File</a></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 8803,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/file-post/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $this->artisan('wordpress:old-link-export')
            ->expectsOutput('article_id,wordpress_id,slug,source_url,literal,status,message')
            ->expectsOutputToContain('https://old.example.test/wp-content/uploads/file.pdf')
            ->assertSuccessful();
    }

    public function test_wordpress_repair_old_links_command_prints_failed_rows_with_reason(): void
    {
        Storage::fake('public');
        Http::fake([
            'old.example.test/wp-content/uploads/missing-command.jpg' => Http::response('', 404),
        ]);

        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 8804,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/missing-command.jpg"></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 8804,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/missing-command/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $exitCode = Artisan::call('wordpress:repair-old-links', ['--apply' => true, '--limit' => 1]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString($article->slug, $output);
        $this->assertStringContainsString('HTTP 404 from source media URL.', $output);
    }

    public function test_wordpress_repair_old_links_command_can_print_progress_and_accept_download_timeout(): void
    {
        Http::fake([
            'old.example.test/wp-content/uploads/progress-command.jpg' => Http::response('', 404),
        ]);

        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 8808,
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/progress-command.jpg"></p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 8808,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/progress-command/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => [],
        ]);

        $exitCode = Artisan::call('wordpress:repair-old-links', [
            '--apply' => true,
            '--limit' => 1,
            '--progress-every' => 1,
            '--download-timeout' => 8,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Download timeout: 8 seconds', $output);
        $this->assertStringContainsString('Progress: checked 1/1 articles; references 1; failed downloads 1; current '.$article->slug, $output);
    }

    public function test_wordpress_remove_old_link_command_removes_a_specific_missing_reference(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 8805,
            'slug' => 'article-with-missing-old-media',
            'body_html' => '<p>Intro.</p><figure><img src="https://old.example.test/wp-content/uploads/missing-command-1024x683.jpg"></figure><p>Outro.</p>',
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 8805,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/article-with-missing-old-media/',
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => ['inline_image_download_failed', 'old_link_repair_failed', 'manual_review'],
        ]);

        $this->artisan('wordpress:remove-old-link', [
            'article' => $article->slug,
            'sourceUrl' => 'https://old.example.test/wp-content/uploads/missing-command.jpg',
            '--apply' => true,
        ])
            ->expectsOutput('Mode: apply')
            ->expectsOutput('Article: article-with-missing-old-media')
            ->expectsOutput('Removed references: 1')
            ->expectsOutput('Remaining old upload references: no')
            ->assertSuccessful();

        $article->refresh();

        $this->assertStringContainsString('<p>Intro.</p>', $article->body_html);
        $this->assertStringContainsString('<p>Outro.</p>', $article->body_html);
        $this->assertStringNotContainsString('/wp-content/uploads/missing-command', $article->body_html);
        $this->assertSame([], $article->wordpressImportRecord->fresh()->warnings);
    }

    public function test_wordpress_remove_failed_old_links_command_removes_all_known_failed_references(): void
    {
        $firstArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 8806,
            'slug' => 'first-known-failed-media',
            'body_html' => '<p>First.</p><img src="https://old.example.test/wp-content/uploads/first-missing-300x200.jpg">',
        ]);
        $secondArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 8807,
            'slug' => 'second-known-failed-media',
            'body_html' => '<p>Second.</p><img src="https://old.example.test/wp-content/uploads/second-missing.jpg">',
        ]);

        foreach ([$firstArticle, $secondArticle] as $article) {
            WordpressImportRecord::query()->create([
                'wordpress_id' => $article->old_wordpress_id,
                'wordpress_type' => 'post',
                'importable_type' => Article::class,
                'importable_id' => $article->id,
                'old_url' => 'https://old.example.test/'.$article->slug.'/',
                'new_url' => '/'.$article->slug,
                'status' => 'imported',
                'warnings' => ['inline_image_download_failed', 'old_link_repair_failed', 'manual_review'],
            ]);
        }

        $this->artisan('wordpress:remove-failed-old-links', ['--apply' => true])
            ->expectsOutput('Mode: apply')
            ->expectsOutput('Sources checked: 2')
            ->expectsOutput('Articles touched: 2')
            ->expectsOutput('Removed references: 2')
            ->expectsOutput('Remaining failed sources: 0')
            ->assertSuccessful();

        $this->assertStringNotContainsString('wp-content/uploads', (string) $firstArticle->fresh()->body_html);
        $this->assertStringNotContainsString('wp-content/uploads', (string) $secondArticle->fresh()->body_html);
        $this->assertSame([], $firstArticle->wordpressImportRecord->fresh()->warnings);
        $this->assertSame([], $secondArticle->wordpressImportRecord->fresh()->warnings);
    }
}
