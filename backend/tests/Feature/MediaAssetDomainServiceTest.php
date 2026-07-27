<?php

namespace Tests\Feature;

use App\Models\AdBookingLineItem;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdCreativeRevision;
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
use App\Services\Media\MediaAssetDeletionGuard;
use App\Services\Media\MediaAssetLifecycleService;
use App\Services\Media\MediaAssetMetadataService;
use App\Services\Media\MediaAssetRenameService;
use App\Services\Media\MediaAssetReplacementService;
use App\Services\Media\MediaAssetUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MediaAssetDomainServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_editorial_metadata_update_preserves_private_metadata_and_writes_redacted_audit(): void
    {
        $actor = User::factory()->create();
        $asset = MediaAsset::factory()->create([
            'metadata' => [
                'credit' => 'Old credit',
                'public_booking_upload_token' => 'do-not-audit-this-token',
                'content_sha256' => str_repeat('a', 64),
            ],
        ]);

        $updated = app(MediaAssetMetadataService::class)->update($asset, [
            'display_name' => 'Внатрешно име',
            'title' => 'Битола навечер',
            'alt_text' => 'Поглед кон Широк Сокак навечер',
            'caption' => 'Широк Сокак во лето.',
            'description' => 'Фотографија користена за локални културни приказни.',
            'photo_credit' => 'Фото: Разбуди се',
            'photographer' => 'Елена Наумовска',
            'source_name' => 'Архива на Разбуди се',
            'source_url' => 'https://razbudise.mk/',
            'license' => 'Сопствена фотографија',
            'copyright_notice' => '© Разбуди се',
            'focal_point_x' => 40,
            'focal_point_y' => 55,
            'seo_filename_suggestion' => 'bitola-sirok-sokak-navecer.jpg',
            'usage_notes' => 'Не користи за платени кампањи.',
        ], $actor);

        $this->assertSame('Внатрешно име', $updated->getRawOriginal('display_name'));
        $this->assertSame('Внатрешно име', $updated->display_name);
        $this->assertSame('Битола навечер', $updated->title);
        $this->assertSame('Фото: Разбуди се', $updated->photo_credit);
        $this->assertSame(['x' => 40, 'y' => 55], $updated->metadata['focal_point']);
        $this->assertSame('do-not-audit-this-token', $updated->metadata['public_booking_upload_token']);

        $audit = AdminAuditLog::query()->sole();

        $this->assertSame('media_asset.metadata_updated', $audit->action);
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame('Old credit', $audit->before['credit']);
        $this->assertSame('Фото: Разбуди се', $audit->after['credit']);
        $this->assertStringNotContainsString('do-not-audit-this-token', json_encode($audit->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(str_repeat('a', 64), json_encode($audit->toArray(), JSON_THROW_ON_ERROR));

        app(MediaAssetMetadataService::class)->update($updated, [
            'display_name' => 'Внатрешно име',
            'title' => 'Битола навечер',
        ], $actor);

        $this->assertSame(1, AdminAuditLog::query()->count());
    }

    public function test_safe_rename_keeps_identity_references_and_compatibility_aliases(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/original.jpg', 'original-bytes');
        Storage::disk('public')->put('media/variants/original-thumbnail.jpg', 'thumb-bytes');
        $oldUrl = Storage::disk('public')->url('media/original.jpg');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/original.jpg',
            'url' => $oldUrl,
            'metadata' => [
                'original_name' => 'camera-upload.jpg',
                'responsive_variants' => [
                    'thumbnail' => [
                        'path' => 'media/variants/original-thumbnail.jpg',
                        'url' => Storage::disk('public')->url('media/variants/original-thumbnail.jpg'),
                        'width' => 320,
                        'height' => 180,
                    ],
                ],
            ],
        ]);
        $article = Article::factory()->published()->create([
            'featured_image_id' => $asset->id,
            'body_html' => '<p><img src="'.$oldUrl.'"></p>',
        ]);
        ArticleBlock::query()->create([
            'article_id' => $article->id,
            'type' => 'image',
            'data' => ['url' => $oldUrl],
            'sort_order' => 0,
        ]);

        $renamed = app(MediaAssetRenameService::class)->rename($asset, 'bitola navecer.jpg', User::factory()->create());

        $this->assertSame($asset->id, $renamed->id);
        $this->assertSame('media/bitola-navecer.jpg', $renamed->path);
        $this->assertSame('camera-upload.jpg', $renamed->metadata['original_name']);
        $this->assertSame(
            'media/variants/bitola-navecer-thumbnail.jpg',
            $renamed->metadata['responsive_variants']['thumbnail']['path'],
        );
        $legacyReferences = collect($renamed->metadata['legacy_paths']);
        $this->assertContains('media/original.jpg', $legacyReferences->pluck('path')->all());
        $this->assertContains('media/variants/original-thumbnail.jpg', $legacyReferences->pluck('path')->all());
        $this->assertContains($oldUrl, $legacyReferences->pluck('url')->all());
        Storage::disk('public')->assertExists('media/bitola-navecer.jpg');
        Storage::disk('public')->assertExists('media/variants/bitola-navecer-thumbnail.jpg');
        Storage::disk('public')->assertExists('media/original.jpg');
        Storage::disk('public')->assertExists('media/variants/original-thumbnail.jpg');
        $this->assertSame($asset->id, $article->fresh()->featured_image_id);
        $this->assertStringContainsString($renamed->url, (string) $article->fresh()->body_html);
        $this->assertSame($renamed->url, $article->blocks()->firstOrFail()->data['url']);
        $this->assertDatabaseHas('admin_audit_logs', [
            'auditable_type' => MediaAsset::class,
            'auditable_id' => $asset->id,
            'action' => 'media_asset.file_renamed',
        ]);
    }

    public function test_safe_rename_rejects_unsafe_or_incomplete_changes_without_mutation(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/source.jpg', 'source');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/source.jpg',
            'url' => Storage::disk('public')->url('media/source.jpg'),
            'metadata' => [
                'responsive_variants' => [
                    'thumbnail' => [
                        'path' => 'media/variants/missing-thumbnail.jpg',
                        'url' => Storage::disk('public')->url('media/variants/missing-thumbnail.jpg'),
                    ],
                ],
            ],
        ]);

        foreach (['../escape.jpg', 'different.png', 'safe-name.jpg'] as $filename) {
            try {
                app(MediaAssetRenameService::class)->rename($asset->fresh(), $filename);
                $this->fail('Expected a safe rename validation failure.');
            } catch (ValidationException) {
                $this->assertSame('media/source.jpg', $asset->fresh()->path);
                $this->assertSame(0, AdminAuditLog::query()->count());
                Storage::disk('public')->assertMissing('media/safe-name.jpg');
            }
        }
    }

    public function test_safe_rename_reserves_the_target_path_across_different_source_assets(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/source-for-reservation.jpg', 'source');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/source-for-reservation.jpg',
            'url' => Storage::disk('public')->url('media/source-for-reservation.jpg'),
            'metadata' => [],
        ]);
        $lock = Cache::lock(
            'media-asset-rename-target:'.hash('sha256', "public\0media/reserved-name.jpg"),
            300,
        );
        $this->assertTrue($lock->get());

        try {
            app(MediaAssetRenameService::class)->rename($asset, 'reserved-name.jpg');
            $this->fail('Expected the reserved media target to reject a concurrent rename.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('filename', $exception->errors());
        } finally {
            $lock->release();
        }

        $this->assertSame('media/source-for-reservation.jpg', $asset->fresh()->path);
        Storage::disk('public')->assertExists('media/source-for-reservation.jpg');
        Storage::disk('public')->assertMissing('media/reserved-name.jpg');
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_safe_rename_rewrites_every_guarded_embedded_reference_store(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/all-stores.jpg', 'all-stores');
        $oldUrl = Storage::disk('public')->url('media/all-stores.jpg');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/all-stores.jpg',
            'url' => $oldUrl,
        ]);
        $article = Article::factory()->create([
            'body_html' => '<img src="'.$oldUrl.'">',
            'onefivefour_metadata' => ['image' => $oldUrl],
            'guide_metadata' => ['image' => $oldUrl],
        ]);
        $block = ArticleBlock::query()->create([
            'article_id' => $article->id,
            'type' => 'image',
            'data' => ['url' => $oldUrl],
            'sort_order' => 0,
        ]);
        $revision = ArticleRevision::query()->create([
            'article_id' => $article->id,
            'version' => 1,
            'reason' => 'saved',
            'snapshot' => ['article' => ['body_html' => '<img src="'.$oldUrl.'">']],
        ]);
        $seo = SeoMetadata::query()->create([
            'breadcrumbs' => [['image' => $oldUrl]],
            'schema_json' => ['image' => $oldUrl],
        ]);
        $homepage = HomepageSection::query()->create([
            'key' => 'rename-all-stores',
            'name' => 'Rename stores',
            'configuration' => ['image' => $oldUrl],
        ]);
        $setting = SiteSetting::query()->create([
            'key' => 'rename-all-stores',
            'value' => ['image' => $oldUrl],
        ]);
        $creative = AdCreative::query()->create([
            'campaign_id' => AdCampaign::factory()->create()->id,
            'name' => 'HTML creative',
            'type' => 'html',
            'html' => '<img src="'.$oldUrl.'">',
        ]);
        $lineItem = AdBookingLineItem::factory()->create(['html' => '<img src="'.$oldUrl.'">']);
        $creativeRevision = AdCreativeRevision::factory()->create([
            'ad_booking_line_item_id' => $lineItem->id,
            'html' => '<img src="'.$oldUrl.'">',
        ]);
        DB::table('wordpress_import_records')->insert([
            'wordpress_id' => 1234567,
            'wordpress_type' => 'post',
            'status' => 'imported',
            'source_payload' => json_encode(['image' => $oldUrl], JSON_THROW_ON_ERROR),
            'warnings' => json_encode(['image' => $oldUrl], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $immutableBefore = [
            'article_revision' => DB::table('article_revisions')->where('id', $revision->id)->value('snapshot'),
            'ad_creative_revision_html' => DB::table('ad_creative_revisions')->where('id', $creativeRevision->id)->value('html'),
            'wordpress_source_payload' => DB::table('wordpress_import_records')->where('wordpress_id', 1234567)->value('source_payload'),
            'wordpress_warnings' => DB::table('wordpress_import_records')->where('wordpress_id', 1234567)->value('warnings'),
        ];

        $renamed = app(MediaAssetRenameService::class)->rename($asset, 'renamed-all-stores.jpg');

        foreach ([
            $article->fresh()->body_html,
            $article->fresh()->onefivefour_metadata,
            $article->fresh()->guide_metadata,
            $block->fresh()->data,
            $seo->fresh()->breadcrumbs,
            $seo->fresh()->schema_json,
            $homepage->fresh()->configuration,
            $setting->fresh()->value,
            $creative->fresh()->html,
            $lineItem->fresh()->html,
        ] as $rewrittenValue) {
            $decodedJson = is_string($rewrittenValue) ? json_decode($rewrittenValue, true) : null;
            $encoded = is_array($decodedJson)
                ? json_encode($decodedJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                : (is_string($rewrittenValue)
                    ? $rewrittenValue
                    : json_encode($rewrittenValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $this->assertStringNotContainsString($oldUrl, $encoded);
            $this->assertStringContainsString($renamed->url, $encoded);
        }

        $this->assertSame(
            $immutableBefore,
            [
                'article_revision' => DB::table('article_revisions')->where('id', $revision->id)->value('snapshot'),
                'ad_creative_revision_html' => DB::table('ad_creative_revisions')->where('id', $creativeRevision->id)->value('html'),
                'wordpress_source_payload' => DB::table('wordpress_import_records')->where('wordpress_id', 1234567)->value('source_payload'),
                'wordpress_warnings' => DB::table('wordpress_import_records')->where('wordpress_id', 1234567)->value('warnings'),
            ],
        );

        $legacyReferences = collect($renamed->metadata['legacy_paths']);
        $this->assertContains($oldUrl, $legacyReferences->pluck('url')->all());
    }

    public function test_safe_replacement_preserves_asset_identity_rewrites_current_content_and_audits(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/old.jpg', 'old-bytes');
        Storage::disk('public')->put('media/variants/old-large.jpg', 'old-large-bytes');
        $oldUrl = Storage::disk('public')->url('media/old.jpg');
        $oldLargeUrl = Storage::disk('public')->url('media/variants/old-large.jpg');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/old.jpg',
            'url' => $oldUrl,
            'metadata' => [
                'original_name' => 'old-camera.jpg',
                'public_booking_upload_token' => 'private-token',
                // Deliberately omit thumbnail and medium. Variant replacement
                // must match stable names, never numeric array positions.
                'responsive_variants' => [
                    'large' => [
                        'path' => 'media/variants/old-large.jpg',
                        'url' => $oldLargeUrl,
                    ],
                ],
            ],
        ]);
        $article = Article::factory()->published()->create([
            'featured_image_id' => $asset->id,
            'body_html' => '<img src="'.$oldUrl.'"><img src="'.$oldLargeUrl.'">',
        ]);
        $replacement = UploadedFile::fake()->image('replacement.png', 900, 600)->size(128);

        $updated = app(MediaAssetReplacementService::class)->replace(
            $asset,
            $replacement,
            User::factory()->create(),
        );

        $this->assertSame($asset->id, $updated->id);
        $this->assertSame('image/png', $updated->mime_type);
        $this->assertSame(900, $updated->width);
        $this->assertSame(600, $updated->height);
        $this->assertSame('replacement.png', $updated->metadata['original_name']);
        $this->assertSame('private-token', $updated->metadata['public_booking_upload_token']);
        $legacyReferences = collect($updated->metadata['legacy_paths']);
        $this->assertContains('media/old.jpg', $legacyReferences->pluck('path')->all());
        $this->assertContains($oldUrl, $legacyReferences->pluck('url')->all());
        $this->assertContains($oldLargeUrl, $legacyReferences->pluck('url')->all());
        $this->assertArrayHasKey('thumbnail', $updated->metadata['responsive_variants']);
        Storage::disk('public')->assertExists($updated->path);
        Storage::disk('public')->assertExists('media/old.jpg');
        $this->assertSame($asset->id, $article->fresh()->featured_image_id);
        $this->assertStringContainsString($updated->url, (string) $article->fresh()->body_html);
        $this->assertStringContainsString(
            $updated->metadata['responsive_variants']['large']['url'],
            (string) $article->fresh()->body_html,
        );
        $this->assertStringNotContainsString(
            $updated->metadata['responsive_variants']['thumbnail']['url'],
            (string) $article->fresh()->body_html,
        );
        $audit = AdminAuditLog::query()->where('action', 'media_asset.file_replaced')->sole();
        $this->assertStringNotContainsString('private-token', json_encode($audit->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_usage_summary_and_query_scopes_cover_relations_inline_content_and_public_configuration(): void
    {
        $asset = MediaAsset::factory()->create([
            'path' => 'media/shared.jpg',
            'url' => '/storage/media/shared.jpg',
        ]);
        Article::factory()->create(['featured_image_id' => $asset->id]);
        Author::factory()->create(['avatar_id' => $asset->id]);
        TeamMember::factory()->create(['profile_image_id' => $asset->id]);
        SeoMetadata::query()->create(['og_image_id' => $asset->id]);
        AdCreative::query()->create([
            'campaign_id' => AdCampaign::factory()->create()->id,
            'name' => 'Shared creative',
            'type' => 'image',
            'image_id' => $asset->id,
        ]);
        SponsoredContentDetail::query()->create([
            'article_id' => Article::factory()->create()->id,
            'sponsor_name' => 'Sponsor',
            'sponsor_logo_id' => $asset->id,
        ]);
        $lineItem = AdBookingLineItem::factory()->create(['image_id' => $asset->id]);
        AdCreativeRevision::factory()->create([
            'ad_booking_line_item_id' => $lineItem->id,
            'image_id' => $asset->id,
        ]);
        Article::factory()->create(['body_html' => '<img src="/storage/media/shared.jpg">']);
        ArticleRevision::query()->create([
            'article_id' => Article::factory()->create()->id,
            'version' => 1,
            'reason' => 'saved',
            'snapshot' => ['article' => ['featured_image_id' => $asset->id, 'body_html' => '<img src="/storage/media/shared.jpg">']],
        ]);
        HomepageSection::query()->create([
            'key' => 'media-usage',
            'name' => 'Media usage',
            'configuration' => ['cards' => [['image_url' => '/storage/media/shared.jpg']]],
            'is_active' => true,
        ]);
        SiteSetting::query()->create([
            'key' => 'media-usage',
            'value' => ['image_url' => '/storage/media/shared.jpg'],
            'is_public' => true,
        ]);

        $summary = app(MediaAssetUsageService::class)->summary($asset);

        $this->assertSame(1, $summary['relations']['featured_articles']);
        $this->assertSame(1, $summary['relations']['author_avatars']);
        $this->assertSame(1, $summary['relations']['team_member_profiles']);
        $this->assertSame(1, $summary['relations']['seo_images']);
        $this->assertSame(1, $summary['relations']['ad_creatives']);
        $this->assertSame(1, $summary['relations']['sponsor_logos']);
        $this->assertSame(1, $summary['relations']['ad_booking_items']);
        $this->assertSame(1, $summary['relations']['ad_creative_revisions']);
        $this->assertSame(1, $summary['relations']['inline_articles']);
        $this->assertSame(1, $summary['relations']['article_revisions']);
        $this->assertSame(1, $summary['relations']['homepage_sections']);
        $this->assertSame(1, $summary['relations']['site_settings']);
        $this->assertGreaterThanOrEqual(12, $summary['total'], json_encode($summary, JSON_THROW_ON_ERROR));

        $used = MediaAsset::query()->withUsageCount()->whereKey($asset)->sole();

        $this->assertGreaterThanOrEqual(12, $used->usage_count);
        $this->assertTrue(MediaAsset::query()->whereUsed()->whereKey($asset)->exists());
        $this->assertFalse(MediaAsset::query()->whereUsed(false)->whereKey($asset)->exists());
        $this->assertFalse(MediaAsset::query()->whereUnused()->whereKey($asset)->exists());
    }

    public function test_usage_query_scopes_match_responsive_and_legacy_aliases_in_embedded_json(): void
    {
        $asset = MediaAsset::factory()->create([
            'path' => 'media/alias-original.jpg',
            'url' => '/storage/media/alias-original.jpg',
            'metadata' => [
                'responsive_variants' => [
                    'medium' => [
                        'path' => 'media/variants/alias-medium.jpg',
                        'url' => '/storage/media/variants/alias-medium.jpg',
                    ],
                ],
                'legacy_paths' => [[
                    'path' => 'media/legacy-alias.jpg',
                    'url' => 'https://cdn.example.test/media/legacy-alias.jpg',
                ]],
            ],
        ]);
        SeoMetadata::query()->create([
            'schema_json' => ['image' => '/storage/media/variants/alias-medium.jpg'],
        ]);
        SiteSetting::query()->create([
            'key' => 'legacy-alias-usage',
            'value' => ['image' => 'https://cdn.example.test/media/legacy-alias.jpg'],
        ]);

        $withUsage = MediaAsset::query()->withUsageCount()->whereKey($asset)->sole();

        $this->assertSame(2, $withUsage->usage_count);
        $this->assertTrue(MediaAsset::query()->whereUsed()->whereKey($asset)->exists());
        $this->assertFalse(MediaAsset::query()->whereUnused()->whereKey($asset)->exists());
        $this->assertDatabaseHas('media_asset_reference_aliases', [
            'media_asset_id' => $asset->id,
            'reference_hash' => hash('sha256', '/storage/media/variants/alias-medium.jpg'),
        ]);
        $this->assertSame(2, app(MediaAssetUsageService::class)->summary($asset)['total']);
    }

    public function test_article_usage_includes_embedded_seo_schema_and_breadcrumb_references(): void
    {
        $asset = MediaAsset::factory()->create([
            'path' => 'media/seo-embedded.jpg',
            'url' => '/storage/media/seo-embedded.jpg',
        ]);
        $schemaArticle = Article::factory()->create();
        $breadcrumbArticle = Article::factory()->create();

        SeoMetadata::query()->create([
            'seoable_type' => Article::class,
            'seoable_id' => $schemaArticle->id,
            'schema_json' => ['image' => '/storage/media/seo-embedded.jpg'],
        ]);
        SeoMetadata::query()->create([
            'seoable_type' => Article::class,
            'seoable_id' => $breadcrumbArticle->id,
            'breadcrumbs' => [['image' => ['media_asset_id' => $asset->id]]],
        ]);

        $usage = app(MediaAssetUsageService::class);
        $articleIds = $usage->usedInArticles($asset)->pluck('id');

        $this->assertSame(2, $usage->summary($asset)['relations']['seo_embedded_data']);
        $this->assertEqualsCanonicalizing(
            [$schemaArticle->id, $breadcrumbArticle->id],
            $articleIds->all(),
        );
    }

    public function test_usage_query_scopes_match_the_deletion_guard_for_exact_embedded_media_ids(): void
    {
        $unused = MediaAsset::factory()->create();
        MediaAsset::factory()->count(8)->create();
        $used = MediaAsset::factory()->create();

        $this->assertSame(1, $unused->id);
        $this->assertSame(10, $used->id);

        HomepageSection::query()->create([
            'key' => 'exact-media-id-usage',
            'name' => 'Exact media ID usage',
            'configuration' => ['cards' => [['media_asset_id' => $used->id]]],
            'is_active' => true,
        ]);

        $usage = app(MediaAssetUsageService::class);

        $this->assertSame(0, $usage->summary($unused)['total']);
        $this->assertSame(1, $usage->summary($used)['total']);
        $this->assertSame(0, MediaAsset::query()->withUsageCount()->findOrFail($unused->id)->usage_count);
        $this->assertSame(1, MediaAsset::query()->withUsageCount()->findOrFail($used->id)->usage_count);
        $this->assertTrue(MediaAsset::query()->whereUnused()->whereKey($unused)->exists());
        $this->assertFalse(MediaAsset::query()->whereUsed()->whereKey($unused)->exists());
        $this->assertTrue(MediaAsset::query()->whereUsed()->whereKey($used)->exists());
        $this->assertFalse(MediaAsset::query()->whereUnused()->whereKey($used)->exists());

        $guard = app(MediaAssetDeletionGuard::class);

        $this->assertSame(0, $guard->assertCanDelete($unused)['total']);

        try {
            $guard->assertCanDelete($used);
            $this->fail('Embedded media ID usage should prevent deletion.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('media_asset', $exception->errors());
        }
    }

    public function test_string_embedded_media_ids_match_the_gallery_query_and_deletion_guard(): void
    {
        $asset = MediaAsset::factory()->create();

        HomepageSection::query()->create([
            'key' => 'string-media-id-usage',
            'name' => 'String media ID usage',
            'configuration' => ['cards' => [['media_asset_id' => (string) $asset->id]]],
            'is_active' => true,
        ]);

        $usage = app(MediaAssetUsageService::class);

        $this->assertSame(1, $usage->summary($asset)['total']);
        $this->assertSame(1, MediaAsset::query()->withUsageCount()->findOrFail($asset->id)->usage_count);
        $this->assertTrue(MediaAsset::query()->whereUsed()->whereKey($asset)->exists());
        $this->assertFalse(MediaAsset::query()->whereUnused()->whereKey($asset)->exists());

        try {
            app(MediaAssetDeletionGuard::class)->assertCanDelete($asset);
            $this->fail('A string embedded media ID must prevent deletion.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('media_asset', $exception->errors());
        }
    }

    public function test_published_and_workflow_usage_prevents_deletion_while_archive_preserves_the_asset(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/live.jpg', 'live');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/live.jpg',
            'url' => Storage::disk('public')->url('media/live.jpg'),
        ]);
        Article::factory()->published()->create(['featured_image_id' => $asset->id]);

        try {
            $asset->delete();
            $this->fail('Published media deletion should be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('media_asset', $exception->errors());
        }

        $archived = app(MediaAssetLifecycleService::class)->archive($asset->fresh(), User::factory()->create());

        $this->assertNotNull($archived->archived_at);
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertExists('media/live.jpg');
        $this->assertDatabaseHas('admin_audit_logs', [
            'auditable_type' => MediaAsset::class,
            'auditable_id' => $asset->id,
            'action' => 'media_asset.archived',
        ]);
    }

    public function test_unused_asset_delete_removes_original_variants_and_rename_aliases_after_commit(): void
    {
        Storage::fake('public');

        foreach (['media/unused.jpg', 'media/variants/unused-thumbnail.jpg', 'media/legacy-unused.jpg'] as $path) {
            Storage::disk('public')->put($path, 'bytes');
        }

        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/unused.jpg',
            'url' => Storage::disk('public')->url('media/unused.jpg'),
            'metadata' => [
                'responsive_variants' => [
                    'thumbnail' => ['path' => 'media/variants/unused-thumbnail.jpg'],
                ],
                'legacy_paths' => ['media/legacy-unused.jpg'],
            ],
        ]);

        app(MediaAssetLifecycleService::class)->delete($asset);

        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertMissing('media/unused.jpg');
        Storage::disk('public')->assertMissing('media/variants/unused-thumbnail.jpg');
        Storage::disk('public')->assertMissing('media/legacy-unused.jpg');
        $this->assertDatabaseHas('admin_audit_logs', [
            'auditable_type' => MediaAsset::class,
            'auditable_id' => $asset->id,
            'action' => 'media_asset.deleted',
        ]);
    }
}
