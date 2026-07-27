<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Services\Media\MediaAssetMetadataService;
use App\Services\Media\MediaAssetReplacementService;
use App\Services\Media\MediaAssetUploader;
use App\Services\Media\MediaDuplicateCleanupService;
use App\Services\Media\MediaSeoFilenameSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaMetadataConservatismTest extends TestCase
{
    use RefreshDatabase;

    public function test_dedupe_keeps_both_rows_when_verified_duplicates_have_conflicting_editorial_metadata(): void
    {
        $sourceUrl = 'https://archive.example.test/bitola.jpg';
        $hash = hash('sha256', 'same-binary');
        $first = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/bitola-one.jpg',
            'title' => 'Широк Сокак навечер',
            'alt_text' => 'Луѓе на Широк Сокак навечер',
            'metadata' => [
                'source_url' => $sourceUrl,
                'content_sha256' => $hash,
            ],
        ]);
        $second = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/bitola-two.jpg',
            'title' => 'Летна програма во Битола',
            'alt_text' => 'Публика за време на летен настан',
            'metadata' => [
                'source_url' => $sourceUrl,
                'content_sha256' => $hash,
            ],
        ]);

        $result = app(MediaDuplicateCleanupService::class)->repair(apply: true);

        $this->assertSame(1, $result['duplicate_groups']);
        $this->assertSame(0, $result['mergeable_duplicate_assets']);
        $this->assertSame(1, $result['skipped_conflicting_groups']);
        $this->assertSame(2, $result['skipped_conflicting_assets']);
        $this->assertSame(0, $result['merged_duplicate_assets']);
        $this->assertSame('skipped_editorial_conflict', $result['items'][0]['status']);
        $this->assertEqualsCanonicalizing(
            ['title', 'alt_text'],
            $result['items'][0]['conflicting_fields'],
        );
        $this->assertDatabaseHas('media_assets', ['id' => $first->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $second->id]);
    }

    public function test_filename_suggestion_uses_one_descriptive_source_with_macedonian_safe_transliteration(): void
    {
        $suggestion = app(MediaSeoFilenameSuggester::class)->suggestFrom([
            'title' => 'Ѓорѓи и Љубица на Широк Сокак во Битола',
            'alt_text' => 'Unrelated words must not be appended to the title',
            'caption' => 'More unrelated keywords',
        ], 'media/camera-upload.jpg', 'image/jpeg');

        $this->assertSame('gjorgji-i-ljubica-na-shirok-sokak-vo-bitola.jpg', $suggestion);
    }

    public function test_filename_suggestion_removes_repetition_limits_words_and_rejects_generic_names(): void
    {
        $suggester = app(MediaSeoFilenameSuggester::class);

        $this->assertSame(
            'bitola-kultura-nastani-intervju-mladi-programa-leto-vecher.webp',
            $suggester->suggestFrom([
                'title' => 'Битола Битола култура настани интервју млади програма лето вечер',
            ], 'media/asset.webp'),
        );
        $this->assertNull($suggester->suggestFrom([
            'original_name' => 'IMG_4021.JPG',
        ], 'media/8e1d4c0a32b9492ca14e23174ac22490.jpg', 'image/jpeg'));
    }

    public function test_upload_and_later_metadata_edits_generate_and_refresh_the_suggestion(): void
    {
        Storage::fake('public');
        $asset = app(MediaAssetUploader::class)->upload(
            UploadedFile::fake()->image('IMG_4021.JPG', 1200, 630),
            ['title' => 'Културна вечер во Битола'],
        );

        $this->assertSame('kulturna-vecher-vo-bitola.jpg', $asset->seo_filename_suggestion);
        $this->assertTrue($asset->metadata['seo_filename_suggestion_generated']);

        $updated = app(MediaAssetMetadataService::class)->update($asset, [
            'title' => 'Летна изложба на Широк Сокак',
        ]);

        $this->assertSame('letna-izlozhba-na-shirok-sokak.jpg', $updated->seo_filename_suggestion);
        $this->assertTrue($updated->metadata['seo_filename_suggestion_generated']);
    }

    public function test_an_authorized_manual_filename_suggestion_is_preserved_across_later_metadata_edits(): void
    {
        Storage::fake('public');
        $asset = app(MediaAssetUploader::class)->upload(
            UploadedFile::fake()->image('IMG_4021.JPG', 1200, 630),
            [
                'title' => 'Културна вечер во Битола',
                'seo_filename_suggestion' => 'custom-editorial-name.jpg',
            ],
        );

        $this->assertSame('custom-editorial-name.jpg', $asset->seo_filename_suggestion);
        $this->assertArrayNotHasKey('seo_filename_suggestion_generated', $asset->metadata);

        $updated = app(MediaAssetMetadataService::class)->update($asset, [
            'title' => 'Летна изложба на Широк Сокак',
            'seo_filename_suggestion' => 'custom-editorial-name.jpg',
        ]);

        $this->assertSame('custom-editorial-name.jpg', $updated->seo_filename_suggestion);
        $this->assertArrayNotHasKey('seo_filename_suggestion_generated', $updated->metadata);
    }

    public function test_form_generated_suggestion_remains_generated_when_metadata_changes_before_save(): void
    {
        Storage::fake('public');
        $asset = app(MediaAssetUploader::class)->upload(
            UploadedFile::fake()->image('IMG_4021.JPG', 1200, 630),
            ['title' => 'Културна вечер во Битола'],
        );

        $updated = app(MediaAssetMetadataService::class)->update($asset, [
            'title' => 'Летна изложба на Широк Сокак',
            'seo_filename_suggestion' => 'letna-izlozhba-na-shirok-sokak.jpg',
        ]);

        $this->assertSame('letna-izlozhba-na-shirok-sokak.jpg', $updated->seo_filename_suggestion);
        $this->assertTrue($updated->metadata['seo_filename_suggestion_generated']);
    }

    public function test_hydrated_suggestion_for_a_legacy_blank_asset_is_recorded_as_generated(): void
    {
        $asset = MediaAsset::factory()->create([
            'path' => 'media/IMG_4021.JPG',
            'mime_type' => 'image/jpeg',
            'title' => 'Културна вечер во Битола',
            'seo_filename_suggestion' => null,
            'metadata' => [],
        ]);

        $updated = app(MediaAssetMetadataService::class)->update($asset, [
            'seo_filename_suggestion' => 'kulturna-vecher-vo-bitola.jpg',
        ]);

        $this->assertSame('kulturna-vecher-vo-bitola.jpg', $updated->seo_filename_suggestion);
        $this->assertTrue($updated->metadata['seo_filename_suggestion_generated']);
    }

    public function test_replacement_refreshes_only_generated_suggestion_to_the_new_file_extension(): void
    {
        Storage::fake('public');
        $generated = app(MediaAssetUploader::class)->upload(
            UploadedFile::fake()->image('IMG_4021.JPG', 1200, 630),
            ['title' => 'Културна вечер во Битола'],
        );
        $manual = app(MediaAssetUploader::class)->upload(
            UploadedFile::fake()->image('IMG_4022.JPG', 1200, 630),
            [
                'title' => 'Летна изложба во Битола',
                'seo_filename_suggestion' => 'approved-editorial-name.jpg',
            ],
        );

        $generated = app(MediaAssetReplacementService::class)->replace(
            $generated,
            UploadedFile::fake()->image('replacement.png', 1200, 630),
        );
        $manual = app(MediaAssetReplacementService::class)->replace(
            $manual,
            UploadedFile::fake()->image('replacement.png', 1200, 630),
        );

        $this->assertSame('kulturna-vecher-vo-bitola.png', $generated->seo_filename_suggestion);
        $this->assertTrue($generated->metadata['seo_filename_suggestion_generated']);
        $this->assertSame('approved-editorial-name.jpg', $manual->seo_filename_suggestion);
        $this->assertArrayNotHasKey('seo_filename_suggestion_generated', $manual->metadata);
    }
}
