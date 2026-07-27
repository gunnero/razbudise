<?php

namespace Tests\Feature;

use App\Filament\Tables\MediaAssetTableFilters;
use App\Models\AdCreativeUploadClaim;
use App\Models\Article;
use App\Models\HomepageSection;
use App\Models\MediaAsset;
use App\Services\Media\MediaAssetUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class MediaAssetUsageQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_expression_uses_postgresql_safe_json_casts_and_character_functions(): void
    {
        $sql = $this->usageExpressionFor('pgsql');

        $this->assertStringContainsString('cast(homepage_sections.configuration as text)', $sql);
        $this->assertStringContainsString('strpos(cast(homepage_sections.configuration as text), media_reference_alias.reference) > 0', $sql);
        $this->assertStringContainsString('chr(9)', $sql);
        $this->assertStringContainsString('cast(media_assets.id as text)', $sql);
        $this->assertStringNotContainsString('char(9)', $sql);
        $this->assertStringNotContainsString('homepage_sections.configuration like', $sql);
        $this->assertStringNotContainsString(' like ', $sql);
    }

    public function test_usage_expression_uses_driver_specific_literal_contains_operators(): void
    {
        $mysql = $this->usageExpressionFor('mysql');
        $sqlite = $this->usageExpressionFor('sqlite');

        $this->assertStringContainsString('instr(cast(articles.body_html as char), media_reference_alias.reference) > 0', $mysql);
        $this->assertStringContainsString('char(9)', $mysql);
        $this->assertStringContainsString('instr(cast(articles.body_html as text), media_reference_alias.reference) > 0', $sqlite);
        $this->assertStringContainsString('char(9)', $sqlite);
    }

    public function test_default_gallery_query_does_not_select_the_exhaustive_usage_expression(): void
    {
        $sql = MediaAssetTableFilters::prepareQuery(MediaAsset::query())->toSql();

        $this->assertStringNotContainsString('usage_count', $sql);
        $this->assertStringNotContainsString('media_asset_reference_aliases', $sql);
        $this->assertLessThan(1000, strlen($sql));
    }

    public function test_used_filter_contains_only_one_copy_of_the_usage_expression(): void
    {
        $sql = MediaAssetTableFilters::prepareQuery(MediaAsset::query())
            ->whereUsed()
            ->toSql();

        $this->assertSame(
            1,
            substr_count($sql, 'select count(*) from articles where articles.featured_image_id = media_assets.id'),
        );
        $this->assertLessThan(70000, strlen($sql));
    }

    public function test_usage_aliases_with_sql_wildcard_characters_are_matched_literally(): void
    {
        $asset = MediaAsset::factory()->create([
            'path' => 'media/photo_%one.jpg',
            'url' => '/storage/media/photo_%one.jpg',
        ]);
        Article::factory()->create([
            'body_html' => '<img src="/storage/media/photoXXone.jpg">',
        ]);

        $usage = app(MediaAssetUsageService::class);

        $this->assertSame(0, $usage->summary($asset)['total']);
        $this->assertSame(0, MediaAsset::query()->withUsageCount()->findOrFail($asset->id)->usage_count);
        $this->assertFalse(MediaAsset::query()->whereUsed()->whereKey($asset)->exists());

        Article::factory()->create([
            'body_html' => '<img src="/storage/media/photo_%one.jpg">',
        ]);

        $this->assertSame(1, $usage->summary($asset)['total']);
        $this->assertSame(1, MediaAsset::query()->withUsageCount()->findOrFail($asset->id)->usage_count);
    }

    public function test_embedded_id_keys_with_underscore_wildcards_are_matched_literally(): void
    {
        $asset = MediaAsset::factory()->create();
        HomepageSection::query()->create([
            'key' => 'malformed-media-id-key',
            'name' => 'Malformed media ID key',
            'configuration' => ['mediaXassetXid' => $asset->id],
        ]);

        $usage = app(MediaAssetUsageService::class);

        $this->assertSame(0, $usage->summary($asset)['total']);
        $this->assertSame(0, MediaAsset::query()->withUsageCount()->findOrFail($asset->id)->usage_count);
        $this->assertFalse(MediaAsset::query()->whereUsed()->whereKey($asset)->exists());

        HomepageSection::query()->create([
            'key' => 'exact-media-id-key',
            'name' => 'Exact media ID key',
            'configuration' => ['media_asset_id' => $asset->id],
        ]);

        $this->assertSame(1, $usage->summary($asset)['total']);
        $this->assertSame(1, MediaAsset::query()->withUsageCount()->findOrFail($asset->id)->usage_count);
    }

    public function test_visible_page_usage_counts_are_hydrated_from_fast_relational_queries(): void
    {
        $used = MediaAsset::factory()->create([
            'path' => 'media/visible-page.jpg',
            'url' => '/storage/media/visible-page.jpg',
        ]);
        $unused = MediaAsset::factory()->create();
        Article::factory()->create([
            'featured_image_id' => $used->id,
            'body_html' => '<img src="/storage/media/visible-page.jpg">',
        ]);
        HomepageSection::query()->create([
            'key' => 'visible-page-usage',
            'name' => 'Visible page usage',
            'configuration' => ['image' => ['media_asset_id' => $used->id]],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(MediaAssetUsageService::class)->hydrateUsageCounts(collect([$used, $unused]));

        $queriesAfterHydration = count(DB::getQueryLog());

        $this->assertSame(1, $used->getAttributes()['usage_count']);
        $this->assertSame(0, $unused->getAttributes()['usage_count']);
        $this->assertLessThanOrEqual(25, $queriesAfterHydration);

        $this->assertSame(1, $used->usage_count);
        $this->assertSame(0, $unused->usage_count);
        $this->assertCount($queriesAfterHydration, DB::getQueryLog());
    }

    public function test_active_ad_upload_claims_count_as_protected_media_usage(): void
    {
        $asset = MediaAsset::factory()->create();
        AdCreativeUploadClaim::factory()->create(['media_asset_id' => $asset->id]);

        $usage = app(MediaAssetUsageService::class);
        $usage->hydrateUsageCounts(collect([$asset]));

        $this->assertSame(1, $usage->summary($asset)['relations']['ad_creative_upload_claims']);
        $this->assertSame(1, $asset->getAttributes()['usage_count']);
        $this->assertTrue(MediaAsset::query()->whereUsed()->whereKey($asset)->exists());
    }

    private function usageExpressionFor(string $driver): string
    {
        $method = new ReflectionMethod(MediaAssetUsageService::class, 'usageCountExpression');

        return $method->invoke(app(MediaAssetUsageService::class), $driver);
    }
}
