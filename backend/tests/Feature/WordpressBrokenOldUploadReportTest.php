<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\WordpressImportRecord;
use App\Services\Wordpress\WordpressOldLinkRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WordpressBrokenOldUploadReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_grouped_report_is_repeatable_and_groups_duplicate_sources_for_manual_review(): void
    {
        $firstArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 7101,
            'slug' => 'shared-broken-first',
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/shared-broken-300x200.jpg" srcset="https://old.example.test/wp-content/uploads/2026/shared-broken-768x512.jpg 768w"></p>',
        ]);
        $secondArticle = Article::factory()->published()->create([
            'old_wordpress_id' => 7102,
            'slug' => 'shared-broken-second',
            'body_html' => '<p><a href="https://old.example.test/wp-content/uploads/2026/shared-broken.jpg">Download</a></p>',
        ]);

        $this->createImportRecord($firstArticle, 'https://old.example.test/first/', ['old_link_repair_failed', 'manual_review']);
        $this->createImportRecord($secondArticle, 'https://old.example.test/second/');

        $service = app(WordpressOldLinkRepairService::class);

        $firstRun = $service->brokenUploadReportRows();
        $secondRun = $service->brokenUploadReportRows();

        $this->assertSame($firstRun, $secondRun);
        $this->assertCount(1, $firstRun);

        $this->assertSame([
            'article_id' => [$firstArticle->id, $secondArticle->id],
            'wordpress_id' => [7101, 7102],
            'slug' => ['shared-broken-first', 'shared-broken-second'],
            'old_url' => ['https://old.example.test/first/', 'https://old.example.test/second/'],
            'new_url' => ['/shared-broken-first', '/shared-broken-second'],
            'source_url' => 'https://old.example.test/wp-content/uploads/2026/shared-broken.jpg',
            'literal_url' => [
                'https://old.example.test/wp-content/uploads/2026/shared-broken-300x200.jpg',
                'https://old.example.test/wp-content/uploads/2026/shared-broken-768x512.jpg',
                'https://old.example.test/wp-content/uploads/2026/shared-broken.jpg',
            ],
            'reason' => 'old_link_repair_failed',
            'status' => 'old_link_repair_failed',
            'message' => 'One or more matching old WordPress upload repairs previously failed; source links remain for manual review.',
            'occurrence_count' => 3,
            'article_count' => 2,
        ], $firstRun[0]);
    }

    public function test_old_link_export_command_can_print_grouped_manual_review_csv(): void
    {
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 7201,
            'slug' => 'command-broken-upload',
            'body_html' => '<p><img src="https://old.example.test/wp-content/uploads/2026/command-broken.jpg"></p>',
        ]);

        $this->createImportRecord($article, 'https://old.example.test/command-broken/', ['old_link_repair_failed', 'manual_review']);

        $exitCode = Artisan::call('wordpress:old-link-export', ['--grouped' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('article_id,wordpress_id,slug,old_url,new_url,source_url,literal_url,reason,status,message,occurrence_count,article_count', $output);
        $this->assertStringContainsString('command-broken-upload', $output);
        $this->assertStringContainsString('old_link_repair_failed', $output);
        $this->assertStringContainsString('https://old.example.test/wp-content/uploads/2026/command-broken.jpg', $output);
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function createImportRecord(Article $article, string $oldUrl, array $warnings = []): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => $article->old_wordpress_id,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => $oldUrl,
            'new_url' => '/'.$article->slug,
            'status' => 'imported',
            'warnings' => $warnings,
        ]);
    }
}
