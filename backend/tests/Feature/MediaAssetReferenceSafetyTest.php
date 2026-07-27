<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\Article;
use App\Models\Author;
use App\Models\MediaAsset;
use App\Models\SeoMetadata;
use App\Models\SponsoredContentDetail;
use App\Models\TeamMember;
use App\Services\Media\MediaAssetRenameService;
use App\Services\Media\MediaAssetReplacementService;
use App\Services\Media\MediaAssetUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MediaAssetReferenceSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rename_rewrites_author_team_and_legacy_absolute_references_without_changing_relations(): void
    {
        Storage::fake('public');

        foreach ([
            'media/current.jpg',
            'media/variants/current-large.jpg',
            'media/legacy/previous-large.jpg',
            'media/legacy/path-only-large.jpg',
        ] as $path) {
            Storage::disk('public')->put($path, 'image-bytes');
        }

        $currentUrl = Storage::disk('public')->url('media/current.jpg');
        $currentLargeUrl = Storage::disk('public')->url('media/variants/current-large.jpg');
        $legacyAbsoluteUrl = 'https://legacy.razbudise.test/storage/media/legacy/previous-large.jpg';
        $legacyPathOnlyUrl = Storage::disk('public')->url('media/legacy/path-only-large.jpg');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/current.jpg',
            'url' => $currentUrl,
            'metadata' => [
                'responsive_variants' => [
                    'large' => [
                        'path' => 'media/variants/current-large.jpg',
                        'url' => $currentLargeUrl,
                    ],
                ],
                'legacy_paths' => [
                    [
                        'variant' => 'large',
                        'path' => 'media/legacy/previous-large.jpg',
                        'url' => $legacyAbsoluteUrl,
                    ],
                    'media/legacy/path-only-large.jpg',
                ],
            ],
        ]);
        $author = Author::factory()->create([
            'avatar_id' => $asset->id,
            'short_bio' => '<img src="'.$legacyPathOnlyUrl.'">',
            'full_bio' => '<img src="'.$legacyAbsoluteUrl.'">',
        ]);
        $member = TeamMember::factory()->create([
            'profile_image_id' => $asset->id,
            'short_bio' => '<img src="'.$currentUrl.'">',
            'full_bio' => '<img src="'.$currentLargeUrl.'">',
            'social_links' => ['portrait' => $legacyAbsoluteUrl],
            'metadata' => ['media' => ['url' => $legacyPathOnlyUrl]],
        ]);
        $seo = SeoMetadata::query()->create(['og_image_id' => $asset->id]);
        $creative = AdCreative::query()->create([
            'campaign_id' => AdCampaign::factory()->create()->id,
            'name' => 'Reference-safe creative',
            'type' => 'image',
            'image_id' => $asset->id,
        ]);

        $renamed = app(MediaAssetRenameService::class)->rename($asset, 'editorial-portrait.jpg');
        $newLargeUrl = $renamed->metadata['responsive_variants']['large']['url'];

        $this->assertSame($asset->id, $author->fresh()->avatar_id);
        $this->assertSame($asset->id, $member->fresh()->profile_image_id);
        $this->assertSame($asset->id, $seo->fresh()->og_image_id);
        $this->assertSame($asset->id, $creative->fresh()->image_id);
        $this->assertSame($newLargeUrl, $this->embeddedUrl($author->fresh()->short_bio));
        $this->assertSame($newLargeUrl, $this->embeddedUrl($author->fresh()->full_bio));
        $this->assertSame($renamed->url, $this->embeddedUrl($member->fresh()->short_bio));
        $this->assertSame($newLargeUrl, $this->embeddedUrl($member->fresh()->full_bio));
        $this->assertSame($newLargeUrl, $member->fresh()->social_links['portrait']);
        $this->assertSame($newLargeUrl, $member->fresh()->metadata['media']['url']);

        $legacy = collect($renamed->metadata['legacy_paths']);
        $this->assertSame(
            'large',
            $legacy->firstWhere('url', $legacyAbsoluteUrl)['variant'] ?? null,
        );
        $this->assertSame(
            'large',
            $legacy->firstWhere('path', 'media/variants/current-large.jpg')['variant'] ?? null,
        );
        $this->assertDatabaseHas('media_asset_reference_aliases', [
            'media_asset_id' => $asset->id,
            'reference_hash' => hash('sha256', $legacyAbsoluteUrl),
        ]);
        $this->assertDatabaseHas('media_asset_reference_aliases', [
            'media_asset_id' => $asset->id,
            'reference_hash' => hash('sha256', $legacyPathOnlyUrl),
        ]);

        $usage = app(MediaAssetUsageService::class)->summary($renamed);
        $this->assertSame(2, $usage['relations']['author_biographies']);
        $this->assertSame(2, $usage['relations']['team_member_biographies']);
        $this->assertSame(1, $usage['relations']['team_member_social_links']);
        $this->assertSame(1, $usage['relations']['team_member_metadata']);
        $this->assertTrue(MediaAsset::query()->whereUsed()->whereKey($asset->id)->exists());

        $this->expectException(ValidationException::class);
        $renamed->delete();
    }

    public function test_replacement_maps_current_and_legacy_variants_by_stable_name_and_preserves_all_media_relations(): void
    {
        Storage::fake('public');

        foreach ([
            'media/old.jpg',
            'media/variants/old-large.jpg',
            'media/legacy/very-old-large.jpg',
        ] as $path) {
            Storage::disk('public')->put($path, 'old-image');
        }

        $oldUrl = Storage::disk('public')->url('media/old.jpg');
        $oldLargeUrl = Storage::disk('public')->url('media/variants/old-large.jpg');
        $legacyLargeUrl = 'https://legacy.razbudise.test/storage/media/legacy/very-old-large.jpg';
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/old.jpg',
            'url' => $oldUrl,
            'metadata' => [
                // Deliberately only keep the large variant. Mapping by numeric
                // position would incorrectly send it to the new thumbnail.
                'responsive_variants' => [
                    'large' => [
                        'path' => 'media/variants/old-large.jpg',
                        'url' => $oldLargeUrl,
                    ],
                ],
                'legacy_paths' => [[
                    'variant' => 'large',
                    'path' => 'media/legacy/very-old-large.jpg',
                    'url' => $legacyLargeUrl,
                ]],
            ],
        ]);
        $article = Article::factory()->create([
            'featured_image_id' => $asset->id,
            'body_html' => implode('', [
                '<img src="'.$oldUrl.'">',
                '<img src="'.$oldLargeUrl.'">',
                '<img src="'.$legacyLargeUrl.'">',
            ]),
        ]);
        $author = Author::factory()->create(['avatar_id' => $asset->id]);
        $member = TeamMember::factory()->create(['profile_image_id' => $asset->id]);
        $seo = SeoMetadata::query()->create(['og_image_id' => $asset->id]);
        $creative = AdCreative::query()->create([
            'campaign_id' => AdCampaign::factory()->create()->id,
            'name' => 'Replacement relation',
            'type' => 'image',
            'image_id' => $asset->id,
        ]);
        $sponsor = SponsoredContentDetail::query()->create([
            'article_id' => Article::factory()->create()->id,
            'sponsor_name' => 'Razbudise test',
            'sponsor_logo_id' => $asset->id,
        ]);

        $updated = app(MediaAssetReplacementService::class)->replace(
            $asset,
            UploadedFile::fake()->image('new.png', 1600, 900),
        );
        $body = (string) $article->fresh()->body_html;
        $newLargeUrl = $updated->metadata['responsive_variants']['large']['url'];
        $newThumbnailUrl = $updated->metadata['responsive_variants']['thumbnail']['url'];

        $this->assertStringContainsString($updated->url, $body);
        $this->assertSame(2, substr_count($body, $newLargeUrl));
        $this->assertStringNotContainsString($newThumbnailUrl, $body);
        $this->assertStringNotContainsString($oldUrl, $body);
        $this->assertStringNotContainsString($oldLargeUrl, $body);
        $this->assertStringNotContainsString($legacyLargeUrl, $body);
        $this->assertSame($asset->id, $article->fresh()->featured_image_id);
        $this->assertSame($asset->id, $author->fresh()->avatar_id);
        $this->assertSame($asset->id, $member->fresh()->profile_image_id);
        $this->assertSame($asset->id, $seo->fresh()->og_image_id);
        $this->assertSame($asset->id, $creative->fresh()->image_id);
        $this->assertSame($asset->id, $sponsor->fresh()->sponsor_logo_id);
        $this->assertSame(
            'large',
            collect($updated->metadata['legacy_paths'])->firstWhere('url', $legacyLargeUrl)['variant'] ?? null,
        );
        $this->assertDatabaseHas('media_asset_reference_aliases', [
            'media_asset_id' => $asset->id,
            'reference_hash' => hash('sha256', $legacyLargeUrl),
        ]);
    }

    public function test_external_replacement_rewrites_and_retains_absolute_url_aliases_without_owning_external_paths(): void
    {
        Storage::fake('public');

        $externalUrl = 'https://old-cdn.razbudise.test/uploads/portrait.jpg';
        $olderUrl = 'https://older-cdn.razbudise.test/uploads/portrait.jpg';
        $asset = MediaAsset::factory()->create([
            'disk' => 'external',
            'path' => '/uploads/portrait.jpg',
            'url' => $externalUrl,
            'metadata' => [
                'legacy_paths' => [[
                    'path' => '/uploads/older-portrait.jpg',
                    'url' => $olderUrl,
                ]],
            ],
        ]);
        $article = Article::factory()->create([
            'body_html' => '<img src="'.$externalUrl.'"><img src="'.$olderUrl.'">',
        ]);

        $updated = app(MediaAssetReplacementService::class)->replace(
            $asset,
            UploadedFile::fake()->image('local-replacement.jpg', 900, 600),
        );

        $this->assertSame('public', $updated->disk);
        $this->assertSame(2, substr_count((string) $article->fresh()->body_html, $updated->url));
        $this->assertStringNotContainsString($externalUrl, (string) $article->fresh()->body_html);
        $this->assertStringNotContainsString($olderUrl, (string) $article->fresh()->body_html);
        $this->assertContains($externalUrl, collect($updated->metadata['legacy_paths'])->pluck('url')->all());
        $this->assertContains($olderUrl, collect($updated->metadata['legacy_paths'])->pluck('url')->all());
        $this->assertNotContains(
            '/uploads/portrait.jpg',
            collect($updated->metadata['legacy_paths'])->pluck('path')->filter()->all(),
        );
        $this->assertDatabaseHas('media_asset_reference_aliases', [
            'media_asset_id' => $asset->id,
            'reference_hash' => hash('sha256', $externalUrl),
        ]);
    }

    private function embeddedUrl(?string $html): string
    {
        preg_match('/src="([^"]+)"/', (string) $html, $matches);

        return (string) ($matches[1] ?? '');
    }
}
