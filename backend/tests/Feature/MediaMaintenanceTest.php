<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\Article;
use App\Models\Author;
use App\Models\MediaAsset;
use App\Models\SeoMetadata;
use App\Models\SponsoredContentDetail;
use App\Services\Media\MediaCleanupService;
use App\Services\Media\MediaQualityReportService;
use App\Services\Media\MediaVariantGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_regenerate_variants_command_updates_existing_images(): void
    {
        Storage::fake('public');

        $path = UploadedFile::fake()->image('old-cover.jpg', 1200, 630)->store('media', 'public');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'mime_type' => 'image/jpeg',
            'width' => 1200,
            'height' => 630,
            'metadata' => [],
        ]);

        $this->artisan('media:regenerate-variants')
            ->expectsOutput('Regenerated variants for 1 media asset.')
            ->assertSuccessful();

        $variants = $asset->fresh()->metadata['responsive_variants'] ?? [];

        $this->assertArrayHasKey('thumbnail', $variants);
        $this->assertArrayHasKey('medium', $variants);
        $this->assertArrayHasKey('large', $variants);
        Storage::disk('public')->assertExists($variants['thumbnail']['path']);
    }

    public function test_variant_generator_skips_excessive_actual_dimensions_before_gd_decode(): void
    {
        Storage::fake('public');
        $path = 'media/oversized-header.gif';
        Storage::disk('public')->put($path, $this->gifHeader(8_000, 6_000));
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => $path,
            'mime_type' => 'image/gif',
            'width' => 1,
            'height' => 1,
        ]);

        $variants = app(MediaVariantGenerator::class)->generate($asset);

        $this->assertSame([], $variants);
        $this->assertSame([$path], Storage::disk('public')->allFiles());
    }

    public function test_media_quality_report_finds_missing_metadata_and_files(): void
    {
        Storage::fake('public');

        MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/missing.jpg',
            'url' => Storage::disk('public')->url('media/missing.jpg'),
            'mime_type' => 'image/jpeg',
            'alt_text' => null,
            'width' => null,
            'height' => null,
            'metadata' => [],
        ]);

        $summary = app(MediaQualityReportService::class)->summary();

        $this->assertSame(5, $summary['totals']['issues']);
        $this->assertSame(1, $summary['totals']['missing_alt_text']);
        $this->assertSame(1, $summary['totals']['missing_dimensions']);
        $this->assertSame(1, $summary['totals']['missing_file']);
        $this->assertSame(1, $summary['totals']['missing_variants']);
        $this->assertSame(1, $summary['totals']['missing_credit']);
    }

    public function test_media_quality_report_flags_stale_or_unsafe_responsive_variant_metadata(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/source.jpg', 'source-image');
        Storage::disk('public')->put('media/variants/source-thumbnail.jpg', 'thumbnail-image');
        Storage::disk('public')->put('media/variants/source-large.jpg', 'large-image');

        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/source.jpg',
            'url' => Storage::disk('public')->url('media/source.jpg'),
            'mime_type' => 'image/jpeg',
            'alt_text' => 'Stored source image',
            'width' => 1200,
            'height' => 630,
            'metadata' => [
                'credit' => 'RazbudiSe archive',
                'responsive_variants' => [
                    'thumbnail' => [
                        'path' => 'media/variants/source-thumbnail.jpg',
                        'width' => 320,
                        'height' => 168,
                    ],
                    'medium' => [
                        'path' => 'media/variants/source-medium-missing.jpg',
                        'width' => 768,
                        'height' => 403,
                    ],
                    'large' => [
                        'path' => 'media/variants/source-large.jpg',
                        'width' => -1,
                        'height' => 630,
                    ],
                ],
            ],
        ]);

        $summary = app(MediaQualityReportService::class)->summary();
        $issue = collect($summary['issues'])->firstWhere('code', 'missing_variants');

        $this->assertSame(1, $summary['totals']['missing_variants']);
        $this->assertSame($asset->id, $issue['media_asset_id']);
        $this->assertContains('medium:missing_file', $issue['variant_issues']);
        $this->assertContains('large:invalid_dimensions', $issue['variant_issues']);
    }

    public function test_media_quality_report_does_not_require_image_metadata_for_documents(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/programme.pdf', 'pdf-body');

        MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/programme.pdf',
            'url' => Storage::disk('public')->url('media/programme.pdf'),
            'mime_type' => 'application/pdf',
            'alt_text' => null,
            'width' => null,
            'height' => null,
            'metadata' => ['source' => 'wordpress_old_link_repair'],
        ]);

        $summary = app(MediaQualityReportService::class)->summary();

        $this->assertSame(0, $summary['totals']['issues']);
        $this->assertSame(0, $summary['totals']['missing_alt_text']);
        $this->assertSame(0, $summary['totals']['missing_dimensions']);
    }

    public function test_media_quality_report_finds_duplicate_source_urls_and_storage_paths(): void
    {
        MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/duplicate.jpg',
            'url' => '/storage/media/duplicate.jpg',
            'mime_type' => 'image/jpeg',
            'metadata' => ['source_url' => 'https://old.example.test/uploads/duplicate.jpg'],
        ]);

        MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/duplicate.jpg',
            'url' => '/storage/media/duplicate-copy.jpg',
            'mime_type' => 'image/jpeg',
            'metadata' => ['source_url' => 'https://old.example.test/uploads/duplicate.jpg'],
        ]);

        $summary = app(MediaQualityReportService::class)->summary();
        $codes = collect($summary['issues'])->pluck('code')->all();

        $this->assertSame(1, $summary['totals']['duplicate_source_urls']);
        $this->assertSame(1, $summary['totals']['duplicate_storage_paths']);
        $this->assertContains('duplicate_source_url', $codes);
        $this->assertContains('duplicate_storage_path', $codes);
    }

    public function test_media_dedupe_dry_run_reports_duplicates_without_mutating(): void
    {
        [$externalAsset, $localAsset] = $this->createDuplicateWordpressMediaPair();

        $this->artisan('media:dedupe')
            ->expectsOutput('Mode: dry run')
            ->expectsOutput('Duplicate groups: 1')
            ->expectsOutput('Mergeable duplicate assets: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas(MediaAsset::class, ['id' => $externalAsset->id]);
        $this->assertDatabaseHas(MediaAsset::class, ['id' => $localAsset->id]);
    }

    public function test_media_dedupe_apply_moves_references_to_local_asset_and_deletes_duplicate(): void
    {
        [$externalAsset, $localAsset] = $this->createDuplicateWordpressMediaPair();
        $article = Article::factory()->create(['featured_image_id' => $externalAsset->id]);
        $author = Author::factory()->create(['avatar_id' => $externalAsset->id]);
        $seoMetadata = SeoMetadata::query()->create(['og_image_id' => $externalAsset->id]);
        $creative = AdCreative::query()->create([
            'campaign_id' => AdCampaign::factory()->create()->id,
            'name' => 'Homepage billboard',
            'type' => 'image',
            'image_id' => $externalAsset->id,
        ]);
        $sponsoredArticle = Article::factory()->create();
        $sponsoredDetail = SponsoredContentDetail::query()->create([
            'article_id' => $sponsoredArticle->id,
            'sponsor_name' => 'Sponsor',
            'sponsor_logo_id' => $externalAsset->id,
        ]);

        $this->artisan('media:dedupe --apply')
            ->expectsOutput('Mode: apply')
            ->expectsOutput('Duplicate groups: 1')
            ->expectsOutput('Merged duplicate assets: 1')
            ->expectsOutput('References updated: 5')
            ->assertSuccessful();

        $this->assertDatabaseMissing(MediaAsset::class, ['id' => $externalAsset->id]);
        $this->assertDatabaseHas(MediaAsset::class, ['id' => $localAsset->id]);
        $this->assertSame($localAsset->id, $article->fresh()->featured_image_id);
        $this->assertSame($localAsset->id, $author->fresh()->avatar_id);
        $this->assertSame($localAsset->id, $seoMetadata->fresh()->og_image_id);
        $this->assertSame($localAsset->id, $creative->fresh()->image_id);
        $this->assertSame($localAsset->id, $sponsoredDetail->fresh()->sponsor_logo_id);
        $this->assertSame(0, app(MediaQualityReportService::class)->summary()['totals']['duplicate_source_urls']);
    }

    public function test_media_cleanup_dry_run_reports_fixable_metadata_without_mutating(): void
    {
        Storage::fake('public');

        $path = UploadedFile::fake()->image('missing-alt.jpg', 1200, 630)->store('media', 'public');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'mime_type' => 'image/jpeg',
            'alt_text' => null,
            'caption' => 'Фото: Архива',
            'width' => null,
            'height' => null,
            'metadata' => [],
        ]);

        $summary = app(MediaCleanupService::class)->repair(apply: false);

        $this->assertSame(1, $summary['assets_checked']);
        $this->assertSame(1, $summary['fixable_assets']);
        $this->assertSame(1, $summary['missing_alt_text']);
        $this->assertNull($asset->fresh()->alt_text);
    }

    public function test_media_cleanup_dry_run_does_not_mark_missing_files_as_variant_fixable(): void
    {
        Storage::fake('public');

        MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/missing-variant-source.jpg',
            'url' => Storage::disk('public')->url('media/missing-variant-source.jpg'),
            'mime_type' => 'image/jpeg',
            'alt_text' => 'Missing source file',
            'width' => 1200,
            'height' => 630,
            'metadata' => ['credit' => 'RazbudiSe archive'],
        ]);

        $summary = app(MediaCleanupService::class)->repair(apply: false);

        $this->assertSame(1, $summary['assets_checked']);
        $this->assertSame(0, $summary['fixable_assets']);
        $this->assertSame(0, $summary['missing_variants']);
    }

    public function test_media_cleanup_apply_fills_safe_metadata_and_generates_variants(): void
    {
        Storage::fake('public');

        $path = UploadedFile::fake()->image('wordpress-cover.jpg', 1200, 630)->store('media', 'public');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'mime_type' => 'image/jpeg',
            'alt_text' => null,
            'caption' => 'Стара насловна фотографија',
            'width' => null,
            'height' => null,
            'metadata' => [
                'source' => 'wordpress_rest',
                'source_url' => 'https://old.example.test/wp-content/uploads/cover.jpg',
            ],
        ]);

        $summary = app(MediaCleanupService::class)->repair(apply: true);
        $freshAsset = $asset->fresh();

        $this->assertSame(1, $summary['fixed_assets']);
        $this->assertSame('Стара насловна фотографија', $freshAsset->alt_text);
        $this->assertSame(1200, $freshAsset->width);
        $this->assertSame(630, $freshAsset->height);
        $this->assertSame('RazbudiSe archive', $freshAsset->metadata['credit']);
        $this->assertArrayHasKey('thumbnail', $freshAsset->metadata['responsive_variants']);
        Storage::disk('public')->assertExists($freshAsset->metadata['responsive_variants']['thumbnail']['path']);
    }

    public function test_media_cleanup_preserves_long_caption_fallback_alt_text(): void
    {
        Storage::fake('public');

        $altColumn = collect(Schema::getColumns('media_assets'))->firstWhere('name', 'alt_text');
        $longCaption = str_repeat('Долг опис за медиум. ', 35);
        $path = UploadedFile::fake()->image('long-caption-cover.jpg', 1200, 630)->store('media', 'public');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'mime_type' => 'image/jpeg',
            'alt_text' => null,
            'caption' => $longCaption,
            'width' => 1200,
            'height' => 630,
            'metadata' => [
                'source' => 'wordpress_rest',
                'source_url' => 'https://old.example.test/wp-content/uploads/long-caption-cover.jpg',
                'responsive_variants' => ['existing' => true],
                'credit' => 'RazbudiSe archive',
            ],
        ]);

        $this->assertSame('text', $altColumn['type_name'] ?? null);

        $summary = app(MediaCleanupService::class)->repair(apply: true);
        $freshAsset = $asset->fresh();

        $this->assertSame(1, $summary['fixed_assets']);
        $this->assertGreaterThan(255, mb_strlen((string) $freshAsset->alt_text));
        $this->assertSame(trim($longCaption), $freshAsset->alt_text);
    }

    public function test_media_cleanup_command_prints_dry_run_summary(): void
    {
        Storage::fake('public');

        $path = UploadedFile::fake()->image('command-cover.jpg', 1200, 630)->store('media', 'public');
        MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'mime_type' => 'image/jpeg',
            'alt_text' => null,
            'caption' => 'Command caption',
            'metadata' => [],
        ]);

        $this->artisan('media:cleanup-quality')
            ->expectsOutput('Mode: dry run')
            ->expectsOutput('Fixable assets: 1')
            ->assertSuccessful();
    }

    public function test_media_localize_featured_images_downloads_external_wordpress_featured_media(): void
    {
        Storage::fake('public');
        Http::fake([
            'old.example.test/wp-content/uploads/2026/cover.jpg' => Http::response($this->transparentPng(), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $externalAsset = MediaAsset::factory()->create([
            'disk' => 'external',
            'path' => '/wp-content/uploads/2026/cover.jpg',
            'url' => 'https://old.example.test/wp-content/uploads/2026/cover.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => 'Стара насловна',
            'caption' => 'Фото: Архива',
            'width' => 1200,
            'height' => 630,
            'metadata' => [
                'wordpress_id' => 123,
                'source' => 'wordpress_rest',
                'source_url' => 'https://old.example.test/wp-content/uploads/2026/cover.jpg',
            ],
        ]);

        $article = Article::factory()->published()->create([
            'featured_image_id' => $externalAsset->id,
        ]);

        $this->artisan('media:localize-featured-images --apply')
            ->expectsOutput('Mode: apply')
            ->expectsOutput('Candidate external featured images: 1')
            ->expectsOutput('Localized media assets: 1')
            ->expectsOutput('Article references updated: 1')
            ->expectsOutput('Failed downloads: 0')
            ->assertSuccessful();

        $localizedAsset = MediaAsset::query()
            ->where('metadata->source_url', 'https://old.example.test/wp-content/uploads/2026/cover.jpg')
            ->where('disk', 'public')
            ->firstOrFail();

        $this->assertSame($localizedAsset->id, $article->fresh()->featured_image_id);
        $this->assertSame('/storage/'.$localizedAsset->path, $localizedAsset->url);
        $this->assertSame('Стара насловна', $localizedAsset->alt_text);
        $this->assertSame('Фото: Архива', $localizedAsset->caption);
        Storage::disk('public')->assertExists($localizedAsset->path);
    }

    public function test_media_localize_featured_images_can_clear_failed_external_featured_media(): void
    {
        Http::fake([
            'old.example.test/wp-content/uploads/2026/missing.jpg' => Http::response('', 404),
        ]);

        $externalAsset = MediaAsset::factory()->create([
            'disk' => 'external',
            'path' => '/wp-content/uploads/2026/missing.jpg',
            'url' => 'https://old.example.test/wp-content/uploads/2026/missing.jpg',
            'mime_type' => 'image/jpeg',
            'metadata' => [
                'wordpress_id' => 456,
                'source' => 'wordpress_rest',
                'source_url' => 'https://old.example.test/wp-content/uploads/2026/missing.jpg',
            ],
        ]);

        $article = Article::factory()->published()->create([
            'featured_image_id' => $externalAsset->id,
        ]);

        $this->artisan('media:localize-featured-images --apply --clear-failed')
            ->expectsOutput('Mode: apply')
            ->expectsOutput('Candidate external featured images: 1')
            ->expectsOutput('Localized media assets: 0')
            ->expectsOutput('Article references updated: 0')
            ->expectsOutput('Failed downloads: 1')
            ->expectsOutput('Failed featured references cleared: 1')
            ->assertSuccessful();

        $freshAsset = $externalAsset->fresh();

        $this->assertNull($article->fresh()->featured_image_id);
        $this->assertSame('HTTP 404 from source media URL.', $freshAsset->metadata['featured_image_localization_error']);
        $this->assertArrayHasKey('featured_image_localization_failed_at', $freshAsset->metadata);
    }

    /**
     * @return array{0: MediaAsset, 1: MediaAsset}
     */
    private function createDuplicateWordpressMediaPair(): array
    {
        Storage::fake('public');
        Storage::disk('public')->put('wordpress/local-copy.jpg', 'image-bytes');

        $sourceUrl = 'https://razbudise.mk/wp-content/uploads/2026/06/local-copy.jpg';

        $externalAsset = MediaAsset::factory()->create([
            'disk' => 'external',
            'path' => '/wp-content/uploads/2026/06/local-copy.jpg',
            'url' => $sourceUrl,
            'mime_type' => 'image/jpeg',
            'alt_text' => null,
            'metadata' => [
                'source' => 'wordpress_rest',
                'source_url' => $sourceUrl,
            ],
        ]);

        $localAsset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'wordpress/local-copy.jpg',
            'url' => '/storage/wordpress/local-copy.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => 'Local asset',
            'width' => 1200,
            'height' => 630,
            'metadata' => [
                'source' => 'wordpress_old_link_repair',
                'source_url' => $sourceUrl,
                'credit' => 'RazbudiSe archive',
                'responsive_variants' => [
                    'thumbnail' => [
                        'path' => 'wordpress/responsive/local-copy-thumbnail.webp',
                        'url' => '/storage/wordpress/responsive/local-copy-thumbnail.webp',
                        'width' => 320,
                    ],
                ],
            ],
        ]);

        return [$externalAsset, $localAsset];
    }

    private function transparentPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAF/gL+Xjz1WQAAAABJRU5ErkJggg==', true) ?: '';
    }

    private function gifHeader(int $width, int $height): string
    {
        return 'GIF89a'.pack('v', $width).pack('v', $height)."\x80\x00\x00\x00\x00\x00\xff\xff\xff\x2c\x00\x00\x00\x00";
    }
}
