<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Services\Media\MediaAssetRenameService;
use App\Services\Media\MediaAssetReplacementService;
use App\Services\Media\MediaAssetStorageService;
use App\Services\Media\MediaAssetUploader;
use App\Services\Media\MediaSeoFilenameSuggester;
use App\Services\Media\MediaVariantGenerator;
use App\Services\Operations\AdminAuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class MediaAssetAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_partial_variant_generation_rolls_back_upload_row_and_every_new_file(): void
    {
        Storage::fake('public');
        $variants = $this->failingVariantGenerator();
        $uploader = new MediaAssetUploader($variants, app(MediaSeoFilenameSuggester::class));

        try {
            $uploader->upload(UploadedFile::fake()->image('atomic-upload.jpg', 900, 600));
            $this->fail('Expected partial media variant generation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated derivative failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_failed_partial_variant_generation_restores_replacement_row_and_removes_every_new_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/original.jpg', 'original-bytes');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/original.jpg',
            'url' => Storage::disk('public')->url('media/original.jpg'),
            'mime_type' => 'image/jpeg',
            'width' => 640,
            'height' => 480,
            'size_bytes' => strlen('original-bytes'),
            'metadata' => ['original_name' => 'original.jpg'],
        ]);
        $before = $asset->fresh()->getAttributes();
        $variants = $this->failingVariantGenerator();
        $uploader = new MediaAssetUploader($variants, app(MediaSeoFilenameSuggester::class));
        $replacement = new MediaAssetReplacementService(
            $uploader,
            $variants,
            app(MediaAssetStorageService::class),
            app(MediaAssetRenameService::class),
            app(MediaSeoFilenameSuggester::class),
            app(AdminAuditLogger::class),
        );

        try {
            $replacement->replace(
                $asset,
                UploadedFile::fake()->image('atomic-replacement.png', 1200, 800),
            );
            $this->fail('Expected partial media variant generation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated derivative failure.', $exception->getMessage());
        }

        $this->assertSame($before, $asset->fresh()->getAttributes());
        $this->assertSame(['media/original.jpg'], Storage::disk('public')->allFiles());
        $this->assertSame('original-bytes', Storage::disk('public')->get('media/original.jpg'));
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_generator_cleans_its_own_partial_derivatives_when_called_directly(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/direct-source.jpg', 'source-bytes');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/direct-source.jpg',
            'url' => Storage::disk('public')->url('media/direct-source.jpg'),
            'mime_type' => 'image/jpeg',
            'width' => 900,
            'height' => 600,
        ]);
        $generator = new class extends MediaVariantGenerator
        {
            private int $writes = 0;

            protected function writeVariant(MediaAsset $asset, string $variantPath, int $targetWidth): ?array
            {
                $this->writes++;
                Storage::disk($asset->disk ?: 'public')->put($variantPath, 'partial-'.$this->writes);

                if ($this->writes === 2) {
                    throw new RuntimeException('Simulated direct derivative failure.');
                }

                return [$targetWidth, 100];
            }
        };

        try {
            $generator->generate($asset);
            $this->fail('Expected direct media variant generation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated direct derivative failure.', $exception->getMessage());
        }

        $this->assertSame(['media/direct-source.jpg'], Storage::disk('public')->allFiles());
    }

    public function test_direct_generator_preflight_preserves_preexisting_derivative_bytes_before_any_write(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/preflight-source.jpg', 'source-bytes');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/preflight-source.jpg',
            'url' => Storage::disk('public')->url('media/preflight-source.jpg'),
            'mime_type' => 'image/jpeg',
            'width' => 900,
            'height' => 600,
        ]);
        $generator = app(MediaVariantGenerator::class);
        $plannedPaths = $generator->plannedPaths((string) $asset->path);
        $preexistingPath = $plannedPaths['medium'];
        Storage::disk('public')->put($preexistingPath, 'preexisting-derivative-bytes');

        try {
            $generator->generate($asset);
            $this->fail('Expected generator preflight to reject the existing derivative.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('target paths already exist', $exception->getMessage());
        }

        $this->assertSame(
            'preexisting-derivative-bytes',
            Storage::disk('public')->get($preexistingPath),
        );
        Storage::disk('public')->assertMissing($plannedPaths['thumbnail']);
        Storage::disk('public')->assertMissing($plannedPaths['large']);
        $this->assertSame(
            ['media/preflight-source.jpg', $preexistingPath],
            Storage::disk('public')->allFiles(),
        );
    }

    public function test_replacement_conflict_never_deletes_a_preexisting_derivative(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/conflict-original.jpg', 'original-bytes');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/conflict-original.jpg',
            'url' => Storage::disk('public')->url('media/conflict-original.jpg'),
            'mime_type' => 'image/jpeg',
            'width' => 640,
            'height' => 480,
            'metadata' => ['original_name' => 'conflict-original.jpg'],
        ]);
        $before = $asset->fresh()->getAttributes();
        $file = UploadedFile::fake()->image('conflicting-replacement.png', 1200, 800);
        $newPath = $file->hashName('media');
        $variants = app(MediaVariantGenerator::class);
        $conflictingPath = collect($variants->plannedPaths($newPath))->first();
        $this->assertIsString($conflictingPath);
        Storage::disk('public')->put($conflictingPath, 'belongs-to-another-operation');
        $replacement = new MediaAssetReplacementService(
            app(MediaAssetUploader::class),
            $variants,
            app(MediaAssetStorageService::class),
            app(MediaAssetRenameService::class),
            app(MediaSeoFilenameSuggester::class),
            app(AdminAuditLogger::class),
        );

        try {
            $replacement->replace($asset, $file);
            $this->fail('Expected a preexisting derivative conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('replacement_file', $exception->errors());
        }

        $this->assertSame($before, $asset->fresh()->getAttributes());
        $this->assertSame(
            'belongs-to-another-operation',
            Storage::disk('public')->get($conflictingPath),
        );
        Storage::disk('public')->assertMissing($newPath);
        Storage::disk('public')->assertExists('media/conflict-original.jpg');
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_registration_conflict_preserves_the_preexisting_derivative_without_creating_a_row(): void
    {
        Storage::fake('public');
        $sourcePath = 'media/registration-conflict.jpg';
        Storage::disk('public')->put($sourcePath, 'new-source');
        $variants = app(MediaVariantGenerator::class);
        $conflictingPath = collect($variants->plannedPaths($sourcePath))->first();
        $this->assertIsString($conflictingPath);
        Storage::disk('public')->put($conflictingPath, 'existing-derivative');

        try {
            app(MediaAssetUploader::class)->registerStoredPath($sourcePath);
            $this->fail('Expected a preexisting derivative conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        $this->assertDatabaseCount('media_assets', 0);
        Storage::disk('public')->assertMissing($sourcePath);
        $this->assertSame('existing-derivative', Storage::disk('public')->get($conflictingPath));
    }

    private function failingVariantGenerator(): MediaVariantGenerator
    {
        return new class extends MediaVariantGenerator
        {
            public function generate(MediaAsset $asset): array
            {
                $firstDerivative = collect($this->plannedPaths((string) $asset->path))->first();

                if (is_string($firstDerivative)) {
                    Storage::disk($asset->disk ?: 'public')->put($firstDerivative, 'partial-derivative');
                }

                throw new RuntimeException('Simulated derivative failure.');
            }
        };
    }
}
