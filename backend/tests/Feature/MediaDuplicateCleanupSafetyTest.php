<?php

namespace Tests\Feature;

use App\Models\AdBookingLineItem;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdCreativeRevision;
use App\Models\AdCreativeUploadClaim;
use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\ArticleBlock;
use App\Models\ArticleRevision;
use App\Models\Author;
use App\Models\HomepageSection;
use App\Models\MediaAsset;
use App\Models\SeoMetadata;
use App\Models\SiteSetting;
use App\Models\SponsoredContentDetail;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Media\MediaDuplicateCleanupService;
use App\Services\Wordpress\WordpressFrozenContentDecisionProvenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaDuplicateCleanupSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_preserves_every_direct_embedded_and_revision_reference_and_cleans_only_unshared_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/duplicate.jpg', 'duplicate');
        Storage::disk('public')->put('media/variants/duplicate-thumbnail.jpg', 'duplicate-thumbnail');
        Storage::disk('public')->put('media/legacy-duplicate.jpg', 'duplicate-legacy');
        Storage::disk('public')->put('media/canonical.jpg', 'canonical');
        Storage::disk('public')->put('media/variants/canonical-thumbnail.jpg', 'canonical-thumbnail');

        $sourceUrl = 'https://archive.example.test/shared-source.jpg';
        $duplicate = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/duplicate.jpg',
            'url' => '/storage/media/duplicate.jpg',
            'title' => 'Preserved duplicate title',
            'alt_text' => null,
            'metadata' => [
                'source_url' => $sourceUrl,
                'content_sha256' => str_repeat('a', 64),
                'credit' => 'Preserved duplicate credit',
                'responsive_variants' => [
                    'thumbnail' => [
                        'path' => 'media/variants/duplicate-thumbnail.jpg',
                        'url' => '/storage/media/variants/duplicate-thumbnail.jpg',
                    ],
                ],
                'legacy_paths' => ['media/legacy-duplicate.jpg'],
            ],
        ]);
        $canonical = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/canonical.jpg',
            'url' => '/storage/media/canonical.jpg',
            'width' => 1600,
            'height' => 900,
            'alt_text' => 'Canonical image',
            'metadata' => [
                'source_url' => $sourceUrl,
                'content_sha256' => str_repeat('a', 64),
                'responsive_variants' => [
                    'thumbnail' => [
                        'path' => 'media/variants/canonical-thumbnail.jpg',
                        'url' => '/storage/media/variants/canonical-thumbnail.jpg',
                    ],
                ],
            ],
        ]);

        $article = Article::factory()->published()->create([
            'featured_image_id' => $duplicate->id,
            'body_html' => '<img src="/storage/media/duplicate.jpg"><img src="/storage/media/variants/duplicate-thumbnail.jpg">',
            'onefivefour_metadata' => ['cover' => '/storage/media/duplicate.jpg'],
            'guide_metadata' => ['image_id' => $duplicate->id],
        ]);
        $author = Author::factory()->create(['avatar_id' => $duplicate->id]);
        $teamMember = TeamMember::factory()->create(['profile_image_id' => $duplicate->id]);
        $seo = SeoMetadata::query()->create([
            'og_image_id' => $duplicate->id,
            'breadcrumbs' => [['image' => '/storage/media/duplicate.jpg']],
            'schema_json' => ['image_id' => $duplicate->id, 'url' => '/storage/media/duplicate.jpg'],
        ]);
        $creative = AdCreative::query()->create([
            'campaign_id' => AdCampaign::factory()->create()->id,
            'name' => 'Embedded creative',
            'type' => 'html',
            'image_id' => $duplicate->id,
            'html' => '<img src="/storage/media/duplicate.jpg">',
        ]);
        $sponsor = SponsoredContentDetail::query()->create([
            'article_id' => Article::factory()->create()->id,
            'sponsor_name' => 'Sponsor',
            'sponsor_logo_id' => $duplicate->id,
        ]);
        $lineItem = AdBookingLineItem::factory()->create([
            'image_id' => $duplicate->id,
            'html' => '<img src="/storage/media/variants/duplicate-thumbnail.jpg">',
        ]);
        $creativeRevision = AdCreativeRevision::factory()->create([
            'ad_booking_line_item_id' => $lineItem->id,
            'image_id' => $canonical->id,
            'html' => '<img src="/storage/media/canonical.jpg">',
        ]);
        $block = ArticleBlock::query()->create([
            'article_id' => $article->id,
            'type' => 'image',
            'data' => [
                'media_asset_id' => $duplicate->id,
                'url' => '/storage/media/variants/duplicate-thumbnail.jpg',
            ],
            'sort_order' => 0,
        ]);
        $revision = ArticleRevision::query()->create([
            'article_id' => $article->id,
            'version' => 1,
            'reason' => 'saved',
            'snapshot' => [
                'article' => [
                    'featured_image_id' => $canonical->id,
                    'body_html' => '<img src="/storage/media/canonical.jpg">',
                ],
                'blocks' => [['data' => ['url' => '/storage/media/variants/canonical-thumbnail.jpg']]],
                'seo' => ['og_image_id' => $canonical->id],
                'sponsor' => ['sponsor_logo_id' => (string) $canonical->id],
            ],
        ]);
        $homepage = HomepageSection::query()->create([
            'key' => 'dedupe-safety',
            'name' => 'Dedupe safety',
            'configuration' => ['image' => '/storage/media/legacy-duplicate.jpg'],
        ]);
        $setting = SiteSetting::query()->create([
            'key' => 'dedupe-safety',
            'value' => ['image' => '/storage/media/duplicate.jpg'],
        ]);
        DB::table('wordpress_import_records')->insert([
            'wordpress_id' => 987654,
            'wordpress_type' => 'post',
            'status' => 'imported',
            'source_payload' => json_encode(['featured_image_id' => $canonical->id, 'url' => '/storage/media/canonical.jpg'], JSON_THROW_ON_ERROR),
            'warnings' => json_encode(['legacy' => '/storage/media/canonical.jpg'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $immutableBefore = [
            'article_revision' => DB::table('article_revisions')->where('id', $revision->id)->value('snapshot'),
            'ad_creative_revision_html' => DB::table('ad_creative_revisions')->where('id', $creativeRevision->id)->value('html'),
            'wordpress_source_payload' => DB::table('wordpress_import_records')->where('wordpress_id', 987654)->value('source_payload'),
            'wordpress_warnings' => DB::table('wordpress_import_records')->where('wordpress_id', 987654)->value('warnings'),
        ];

        $summary = app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertSame(1, $summary['merged_duplicate_assets']);
        $this->assertDatabaseMissing('media_assets', ['id' => $duplicate->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $canonical->id]);
        $this->assertSame('Preserved duplicate title', $canonical->fresh()->title);
        $this->assertSame('Preserved duplicate credit', $canonical->fresh()->metadata['credit']);
        $this->assertSame($canonical->id, $article->fresh()->featured_image_id);
        $this->assertSame($canonical->id, $author->fresh()->avatar_id);
        $this->assertSame($canonical->id, $teamMember->fresh()->profile_image_id);
        $this->assertSame($canonical->id, $seo->fresh()->og_image_id);
        $this->assertSame($canonical->id, $creative->fresh()->image_id);
        $this->assertSame($canonical->id, $sponsor->fresh()->sponsor_logo_id);
        $this->assertSame($canonical->id, $lineItem->fresh()->image_id);
        $this->assertSame($canonical->id, $creativeRevision->fresh()->image_id);

        foreach ([
            $article->fresh()->body_html,
            $article->fresh()->onefivefour_metadata,
            $article->fresh()->guide_metadata,
            $block->fresh()->data,
            $revision->fresh()->snapshot,
            $seo->fresh()->breadcrumbs,
            $seo->fresh()->schema_json,
            $creative->fresh()->html,
            $lineItem->fresh()->html,
            $creativeRevision->fresh()->html,
            $homepage->fresh()->configuration,
            $setting->fresh()->value,
            DB::table('wordpress_import_records')->where('wordpress_id', 987654)->value('source_payload'),
            DB::table('wordpress_import_records')->where('wordpress_id', 987654)->value('warnings'),
        ] as $rewrittenValue) {
            $encoded = is_string($rewrittenValue)
                ? $rewrittenValue
                : json_encode($rewrittenValue, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('duplicate', $encoded);
            $this->assertStringNotContainsString('legacy-duplicate', $encoded);
        }

        $this->assertSame($canonical->id, $block->fresh()->data['media_asset_id']);
        $this->assertSame($canonical->id, $revision->fresh()->snapshot['article']['featured_image_id']);
        $this->assertSame($canonical->id, $revision->fresh()->snapshot['seo']['og_image_id']);
        $this->assertSame((string) $canonical->id, $revision->fresh()->snapshot['sponsor']['sponsor_logo_id']);
        $this->assertSame(
            $immutableBefore,
            [
                'article_revision' => DB::table('article_revisions')->where('id', $revision->id)->value('snapshot'),
                'ad_creative_revision_html' => DB::table('ad_creative_revisions')->where('id', $creativeRevision->id)->value('html'),
                'wordpress_source_payload' => DB::table('wordpress_import_records')->where('wordpress_id', 987654)->value('source_payload'),
                'wordpress_warnings' => DB::table('wordpress_import_records')->where('wordpress_id', 987654)->value('warnings'),
            ],
        );
        Storage::disk('public')->assertExists('media/canonical.jpg');
        Storage::disk('public')->assertExists('media/variants/canonical-thumbnail.jpg');
        Storage::disk('public')->assertMissing('media/duplicate.jpg');
        Storage::disk('public')->assertMissing('media/variants/duplicate-thumbnail.jpg');
        Storage::disk('public')->assertMissing('media/legacy-duplicate.jpg');
        $this->assertDatabaseHas('admin_audit_logs', [
            'auditable_type' => MediaAsset::class,
            'auditable_id' => $duplicate->id,
            'action' => 'media_asset.duplicate_merged',
        ]);
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'media_asset.duplicate_merged')->count());

        $secondRun = app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertSame(0, $secondRun['merged_duplicate_assets']);
    }

    public function test_same_path_duplicate_merge_never_deletes_the_canonical_shared_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/shared-file.jpg', 'shared');

        $duplicate = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/shared-file.jpg',
            'url' => '/storage/media/shared-file.jpg',
            'alt_text' => null,
        ]);
        $canonical = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/shared-file.jpg',
            'url' => '/storage/media/shared-file.jpg',
            'width' => 1200,
            'height' => 630,
            'alt_text' => null,
        ]);
        $article = Article::factory()->published()->create([
            'featured_image_id' => $duplicate->id,
            'body_html' => '<img src="/storage/media/shared-file.jpg">',
        ]);

        app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertDatabaseMissing('media_assets', ['id' => $duplicate->id]);
        $this->assertSame($canonical->id, $article->fresh()->featured_image_id);
        $this->assertStringContainsString('/storage/media/shared-file.jpg', (string) $article->fresh()->body_html);
        Storage::disk('public')->assertExists('media/shared-file.jpg');
    }

    public function test_same_source_url_without_hash_or_verified_localization_is_reported_but_not_merged(): void
    {
        $sourceUrl = 'https://archive.example.test/reused-name.jpg';
        $first = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/first.jpg',
            'metadata' => ['source_url' => $sourceUrl],
        ]);
        $second = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/second.jpg',
            'metadata' => ['source_url' => $sourceUrl],
        ]);

        $result = app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertSame(0, $result['merged_duplicate_assets']);
        $this->assertDatabaseHas('media_assets', ['id' => $first->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $second->id]);
    }

    public function test_same_source_url_with_matching_malformed_hashes_is_not_treated_as_verified_content(): void
    {
        $sourceUrl = 'https://archive.example.test/malformed-hash.jpg';
        $first = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/malformed-first.jpg',
            'metadata' => [
                'source_url' => $sourceUrl,
                'content_sha256' => 'not-a-sha256-digest',
            ],
        ]);
        $second = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/malformed-second.jpg',
            'metadata' => [
                'source_url' => $sourceUrl,
                'content_sha256' => 'not-a-sha256-digest',
            ],
        ]);

        $result = app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertSame(0, $result['merged_duplicate_assets']);
        $this->assertDatabaseHas('media_assets', ['id' => $first->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $second->id]);
    }

    public function test_external_assets_with_the_same_path_on_different_hosts_are_not_merged(): void
    {
        $first = MediaAsset::factory()->create([
            'disk' => 'external',
            'path' => '/uploads/shared-name.jpg',
            'url' => 'https://first.example.test/uploads/shared-name.jpg',
            'alt_text' => null,
            'metadata' => [],
        ]);
        $second = MediaAsset::factory()->create([
            'disk' => 'external',
            'path' => '/uploads/shared-name.jpg',
            'url' => 'https://second.example.test/uploads/shared-name.jpg',
            'alt_text' => null,
            'metadata' => [],
        ]);

        $result = app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertSame(0, $result['duplicate_groups']);
        $this->assertSame(0, $result['merged_duplicate_assets']);
        $this->assertDatabaseHas('media_assets', ['id' => $first->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $second->id]);
    }

    public function test_external_assets_with_the_same_absolute_url_can_still_be_merged(): void
    {
        $url = 'https://archive.example.test/uploads/shared-name.jpg';
        $first = MediaAsset::factory()->create([
            'disk' => 'external',
            'path' => '/uploads/shared-name.jpg',
            'url' => $url,
            'alt_text' => null,
            'metadata' => [],
        ]);
        $second = MediaAsset::factory()->create([
            'disk' => 'external',
            'path' => '/uploads/shared-name.jpg',
            'url' => $url,
            'alt_text' => null,
            'metadata' => [],
        ]);

        $result = app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertSame(1, $result['merged_duplicate_assets']);
        $this->assertSame(1, MediaAsset::query()->where('url', $url)->count());
        $this->assertTrue(MediaAsset::query()->whereKey([$first->id, $second->id])->exists());
    }

    public function test_overlapping_source_and_storage_identities_merge_as_one_connected_component(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/connected-a.jpg', 'same-content');
        Storage::disk('public')->put('media/connected-shared.jpg', 'same-content');
        $sourceUrl = 'https://archive.example.test/connected.jpg';
        $hash = hash('sha256', 'same-content');

        $first = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/connected-a.jpg',
            'alt_text' => null,
            'metadata' => ['source_url' => $sourceUrl, 'content_sha256' => $hash],
        ]);
        $bridge = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/connected-shared.jpg',
            'alt_text' => null,
            'metadata' => ['source_url' => $sourceUrl, 'content_sha256' => $hash],
        ]);
        $canonical = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/connected-shared.jpg',
            'width' => 1600,
            'height' => 900,
            'alt_text' => null,
        ]);
        $article = Article::factory()->create(['featured_image_id' => $first->id]);

        $result = app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertSame(1, $result['duplicate_groups']);
        $this->assertSame(2, $result['merged_duplicate_assets']);
        $this->assertDatabaseMissing('media_assets', ['id' => $first->id]);
        $this->assertDatabaseMissing('media_assets', ['id' => $bridge->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $canonical->id]);
        $this->assertSame($canonical->id, $article->fresh()->featured_image_id);
        Storage::disk('public')->assertExists('media/connected-shared.jpg');
    }

    public function test_historically_referenced_asset_is_canonical_and_signed_wordpress_payload_is_unchanged(): void
    {
        $historical = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/history-shared.jpg',
            'url' => '/storage/media/history-shared.jpg',
            'alt_text' => null,
        ]);
        $duplicate = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/history-shared.jpg',
            'url' => '/storage/media/history-shared.jpg',
            'width' => 1800,
            'height' => 1200,
            'alt_text' => null,
        ]);
        $provenance = app(WordpressFrozenContentDecisionProvenance::class);
        $payload = $provenance->addReconciliationPayload([
            'id' => 7654321,
            'featured_image_id' => $historical->id,
            'content' => ['rendered' => '<p>Immutable source payload</p>'],
        ], str_repeat('a', 64));

        DB::table('wordpress_import_records')->insert([
            'wordpress_id' => 7654321,
            'wordpress_type' => 'post',
            'status' => 'imported',
            'source_payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'warnings' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payloadBefore = DB::table('wordpress_import_records')
            ->where('wordpress_id', 7654321)
            ->value('source_payload');

        $result = app(MediaDuplicateCleanupService::class)->repair(apply: true);
        $payloadAfter = DB::table('wordpress_import_records')
            ->where('wordpress_id', 7654321)
            ->value('source_payload');

        $this->assertSame(1, $result['merged_duplicate_assets']);
        $this->assertDatabaseHas('media_assets', ['id' => $historical->id]);
        $this->assertDatabaseMissing('media_assets', ['id' => $duplicate->id]);
        $this->assertSame($payloadBefore, $payloadAfter);
        $this->assertTrue($provenance->hasValidReconciliationPayload(
            json_decode((string) $payloadAfter, true, flags: JSON_THROW_ON_ERROR),
        ));
    }

    public function test_dedupe_skips_group_when_multiple_assets_have_independent_immutable_history(): void
    {
        $first = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/multi-history.jpg',
            'url' => '/storage/media/multi-history.jpg',
            'alt_text' => null,
        ]);
        $second = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/multi-history.jpg',
            'url' => '/storage/media/multi-history.jpg',
            'alt_text' => null,
        ]);

        foreach ([$first, $second] as $index => $asset) {
            ArticleRevision::query()->create([
                'article_id' => Article::factory()->create()->id,
                'version' => 1,
                'reason' => 'saved',
                'snapshot' => ['article' => ['featured_image_id' => $asset->id], 'marker' => $index],
            ]);
        }

        $result = app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertSame(0, $result['merged_duplicate_assets']);
        $this->assertDatabaseHas('media_assets', ['id' => $first->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $second->id]);
        $this->assertSame('skipped_historical_conflict', $result['items'][0]['status']);
    }

    public function test_dedupe_never_crosses_editorial_advertiser_or_upload_claim_boundaries(): void
    {
        $editorial = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/ownership-boundary.jpg',
            'url' => '/storage/media/ownership-boundary.jpg',
            'uploaded_by' => User::factory()->create()->id,
            'alt_text' => null,
            'metadata' => [],
        ]);
        $advertiser = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/ownership-boundary.jpg',
            'url' => '/storage/media/ownership-boundary.jpg',
            'uploaded_by' => null,
            'alt_text' => null,
            'metadata' => [
                'purpose' => 'ad_creative',
                'ad_creative_upload' => true,
                'public_booking_upload_token' => 'claim-secret',
                'public_booking_upload_status' => 'pending',
            ],
        ]);

        $result = app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertSame(0, $result['merged_duplicate_assets']);
        $this->assertDatabaseHas('media_assets', ['id' => $editorial->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $advertiser->id]);
        $this->assertSame('skipped_operational_boundary', $result['items'][0]['status']);
        $this->assertContains('uploaded_by', $result['items'][0]['conflicting_fields']);
        $this->assertContains('metadata.public_booking_upload_token', $result['items'][0]['conflicting_fields']);
        $this->assertStringNotContainsString('claim-secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_dedupe_skips_every_claim_bearing_asset_without_moving_or_nulling_the_claim(): void
    {
        $claimed = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/claimed-shared.jpg',
            'url' => '/storage/media/claimed-shared.jpg',
            'alt_text' => null,
            'metadata' => [],
        ]);
        $duplicate = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/claimed-shared.jpg',
            'url' => '/storage/media/claimed-shared.jpg',
            'alt_text' => null,
            'metadata' => [],
        ]);
        $claim = AdCreativeUploadClaim::factory()->create([
            'media_asset_id' => $claimed->id,
            'status' => AdCreativeUploadClaim::STATUS_EXPIRED,
            'expires_at' => now()->subMinute(),
        ]);

        $result = app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertSame(0, $result['merged_duplicate_assets']);
        $this->assertDatabaseHas('media_assets', ['id' => $claimed->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $duplicate->id]);
        $this->assertSame($claimed->id, $claim->fresh()->media_asset_id);
        $this->assertSame('skipped_operational_boundary', $result['items'][0]['status']);
        $this->assertSame(['ad_creative_upload_claims'], $result['items'][0]['conflicting_fields']);
    }
}
