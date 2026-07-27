<?php

namespace Tests\Feature;

use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Models\AdCustomer;
use App\Models\MediaAsset;
use App\Models\Role;
use App\Models\User;
use App\Services\Media\MediaAssetUploader;
use Database\Seeders\RazbudiseRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaAssetUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_upload_creates_public_media_asset_with_metadata(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('razbudi-se.jpg', 1200, 630)->size(256);

        $asset = app(MediaAssetUploader::class)->upload($file, [
            'alt_text' => 'Разбуди Се насловна фотографија',
            'caption' => 'Фотографија за тест објава',
        ]);

        $this->assertInstanceOf(MediaAsset::class, $asset);
        $this->assertSame('public', $asset->disk);
        $this->assertStringStartsWith('media/', $asset->path);
        $this->assertSame('image/jpeg', $asset->mime_type);
        $this->assertSame('Разбуди Се насловна фотографија', $asset->alt_text);
        $this->assertSame('Фотографија за тест објава', $asset->caption);
        $this->assertSame(1200, $asset->width);
        $this->assertSame(630, $asset->height);
        $this->assertGreaterThan(0, $asset->size_bytes);
        $this->assertStringContainsString('/storage/media/', $asset->url);
        $this->assertArrayHasKey('responsive_variants', $asset->metadata);
        $this->assertArrayHasKey('thumbnail', $asset->metadata['responsive_variants']);
        $this->assertArrayHasKey('medium', $asset->metadata['responsive_variants']);
        $this->assertArrayHasKey('large', $asset->metadata['responsive_variants']);

        Storage::disk('public')->assertExists($asset->path);
        Storage::disk('public')->assertExists($asset->metadata['responsive_variants']['thumbnail']['path']);
        Storage::disk('public')->assertExists($asset->metadata['responsive_variants']['medium']['path']);
        Storage::disk('public')->assertExists($asset->metadata['responsive_variants']['large']['path']);
    }

    public function test_media_asset_api_upload_stores_image_and_returns_resource(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('avatar.png', 600, 600)->size(128);
        $user = $this->userWithRole('author');

        $this->actingAs($user)->postJson('/api/media-assets', [
            'file' => $file,
            'alt_text' => 'Авторска фотографија',
            'caption' => 'Профил фотографија',
            'credit' => 'Фото: Разбуди се',
            'source_url' => 'https://razbudise.mk/media/avatar',
            'focal_point_x' => 42,
            'focal_point_y' => 58,
        ])
            ->assertCreated()
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonPath('data.alt_text', 'Авторска фотографија')
            ->assertJsonPath('data.caption', 'Профил фотографија')
            ->assertJsonPath('data.credit', 'Фото: Разбуди се')
            ->assertJsonPath('data.source_url', 'https://razbudise.mk/media/avatar')
            ->assertJsonPath('data.focal_point.x', 42)
            ->assertJsonPath('data.focal_point.y', 58)
            ->assertJsonPath('data.width', 600)
            ->assertJsonPath('data.height', 600)
            ->assertJsonPath('data.responsive_urls.thumbnail', fn (?string $url) => str_contains((string) $url, '/storage/media/'));

        $asset = MediaAsset::query()->firstOrFail();

        $this->assertSame($user->id, $asset->uploaded_by);
        $this->assertSame('Фото: Разбуди се', $asset->metadata['credit']);
        $this->assertSame('https://razbudise.mk/media/avatar', $asset->metadata['source_url']);
        $this->assertSame(['x' => 42, 'y' => 58], $asset->metadata['focal_point']);
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_media_asset_api_rejects_excessive_image_dimensions_and_removes_upload(): void
    {
        Storage::fake('public');
        $user = $this->userWithRole('author');
        $file = UploadedFile::fake()->createWithContent(
            'oversized.gif',
            $this->gifHeader(8_000, 6_000),
        );

        $this->actingAs($user)->postJson('/api/media-assets', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file')
            ->assertJsonPath(
                'errors.file.0',
                'Image dimensions exceed limits. Maximum side is 12000 pixels and maximum image area is 40000000 pixels.',
            );

        $this->assertSame(0, MediaAsset::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_editor_with_media_permission_can_use_the_editorial_upload_endpoint(): void
    {
        Storage::fake('public');
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)->postJson('/api/media-assets', [
            'file' => UploadedFile::fake()->image('editorial-cover.jpg', 1200, 630),
            'alt_text' => 'Редакциска насловна фотографија',
        ])
            ->assertCreated()
            ->assertJsonPath('data.alt_text', 'Редакциска насловна фотографија');

        $this->assertSame($editor->id, MediaAsset::query()->sole()->uploaded_by);
    }

    public function test_unrelated_role_and_ad_customer_cannot_use_the_editorial_upload_endpoint(): void
    {
        Storage::fake('public');
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->postJson('/api/media-assets', [
            'file' => UploadedFile::fake()->image('accounting.jpg'),
        ])->assertForbidden();

        $customer = User::factory()->create();
        AdCustomer::factory()->create([
            'user_id' => $customer->id,
            'status' => 'active',
        ]);

        $this->actingAs($customer)->postJson('/api/media-assets', [
            'file' => UploadedFile::fake()->image('customer-ad.jpg'),
        ])->assertForbidden();

        $this->assertSame(0, MediaAsset::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_authentication_without_an_active_media_permission_cannot_upload_editorial_media(): void
    {
        Storage::fake('public');
        $inactiveAuthor = $this->userWithRole('author');
        $inactiveAuthor->update(['status' => 'inactive']);

        $this->assertFalse($inactiveAuthor->can('create', MediaAsset::class));

        $this->actingAs($inactiveAuthor)->postJson('/api/media-assets', [
            'file' => UploadedFile::fake()->image('inactive-author.jpg'),
        ])->assertForbidden();

        $this->assertSame(0, MediaAsset::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_editorial_upload_rejects_ad_creative_purpose_and_uses_named_throttle(): void
    {
        Storage::fake('public');
        $author = $this->userWithRole('author');

        $this->actingAs($author)->postJson('/api/media-assets', [
            'file' => UploadedFile::fake()->image('hidden-ad.jpg'),
            'purpose' => 'ad_creative',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purpose');

        $route = Route::getRoutes()->getByName('api.media-assets.store');

        $this->assertNotNull($route);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('throttle:media-uploads', $route->gatherMiddleware());
        $this->assertSame(0, MediaAsset::query()->count());
    }

    public function test_media_asset_admin_preview_url_supports_frontend_public_and_storage_assets(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001/']);

        $frontendAsset = MediaAsset::factory()->create([
            'path' => 'demo/cover.png',
            'url' => '/brand/demo/cover.png',
        ]);

        $storageAsset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/uploaded.png',
            'url' => null,
        ]);

        $this->assertSame('http://localhost:3001/brand/demo/cover.png', $frontendAsset->admin_preview_url);
        $this->assertStringContainsString('/storage/media/uploaded.png', $storageAsset->admin_preview_url);
    }

    public function test_media_asset_api_lists_assets_with_filters_and_responsive_urls(): void
    {
        $author = $this->userWithRole('author');

        MediaAsset::factory()->create([
            'path' => 'media/photo.jpg',
            'url' => 'https://cdn.example.test/photo.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => 'Насловна фотографија',
            'metadata' => [
                'credit' => 'Фото: Разбуди се',
            ],
        ]);

        MediaAsset::factory()->create([
            'path' => 'documents/file.pdf',
            'url' => 'https://cdn.example.test/file.pdf',
            'mime_type' => 'application/pdf',
            'alt_text' => 'Документ',
        ]);

        $this->actingAs($author)->getJson('/api/media-assets?mime_type=image/jpeg&query=photo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.path')
            ->assertJsonMissingPath('data.0.source_url')
            ->assertJsonPath('data.0.responsive_urls.original', 'https://cdn.example.test/photo.jpg')
            ->assertJsonPath('data.0.responsive_urls.large', 'https://cdn.example.test/photo.jpg');
    }

    public function test_media_asset_api_caps_page_size(): void
    {
        $author = $this->userWithRole('author');

        $this->actingAs($author)
            ->getJson('/api/media-assets?per_page=5000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_media_asset_index_requires_an_authorized_editorial_user_and_named_throttle(): void
    {
        MediaAsset::factory()->create(['path' => 'media/private-library.jpg']);

        $this->getJson('/api/media-assets')->assertUnauthorized();

        $accountant = $this->userWithRole('accountant');
        $this->actingAs($accountant)->getJson('/api/media-assets')->assertForbidden();

        $author = $this->userWithRole('author');
        $this->actingAs($author)->getJson('/api/media-assets')->assertOk();

        $route = Route::getRoutes()->getByName('api.media-assets.index');

        $this->assertNotNull($route);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('throttle:media-library', $route->gatherMiddleware());
    }

    public function test_editorial_media_users_cannot_browse_anonymous_or_other_user_ad_creatives(): void
    {
        $editorialAsset = MediaAsset::factory()->create([
            'metadata' => ['purpose' => 'article_image'],
        ]);
        $anonymousAdCreative = MediaAsset::factory()->create([
            'uploaded_by' => null,
            'metadata' => ['purpose' => 'ad_creative', 'public_booking_upload_status' => 'pending'],
        ]);
        $otherUser = User::factory()->create();
        $otherAdCreative = MediaAsset::factory()->create([
            'uploaded_by' => $otherUser->id,
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        foreach (['author', 'contributor', 'photographer'] as $role) {
            $user = $this->userWithRole($role);
            $ownAdCreative = MediaAsset::factory()->create([
                'uploaded_by' => $user->id,
                'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
            ]);

            $this->actingAs($user);

            $visibleIds = MediaAssetResource::getEloquentQuery()->pluck('id');

            $this->assertTrue($visibleIds->contains($editorialAsset->id));
            $this->assertTrue($visibleIds->contains($ownAdCreative->id));
            $this->assertFalse($visibleIds->contains($anonymousAdCreative->id));
            $this->assertFalse($visibleIds->contains($otherAdCreative->id));
            $this->assertTrue($user->can('view', $editorialAsset));
            $this->assertTrue($user->can('view', $ownAdCreative));
            $this->assertFalse($user->can('view', $anonymousAdCreative));
            $this->assertFalse($user->can('view', $otherAdCreative));
        }
    }

    public function test_ad_managers_and_administrators_can_browse_ad_creatives_in_filament(): void
    {
        $editorialAsset = MediaAsset::factory()->create([
            'metadata' => ['purpose' => 'article_image'],
        ]);
        $anonymousAdCreative = MediaAsset::factory()->create([
            'uploaded_by' => null,
            'metadata' => ['purpose' => 'ad_creative', 'public_booking_upload_status' => 'pending'],
        ]);
        $otherAdCreative = MediaAsset::factory()->create([
            'uploaded_by' => User::factory()->create()->id,
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        foreach (['ad_manager', 'admin', 'super_admin'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user);

            $visibleIds = MediaAssetResource::getEloquentQuery()->pluck('id');

            $this->assertTrue($visibleIds->contains($editorialAsset->id));
            $this->assertTrue($visibleIds->contains($anonymousAdCreative->id));
            $this->assertTrue($visibleIds->contains($otherAdCreative->id));
            $this->assertTrue($user->can('view', $anonymousAdCreative));
            $this->assertTrue($user->can('view', $otherAdCreative));
        }
    }

    private function gifHeader(int $width, int $height): string
    {
        return 'GIF89a'.pack('v', $width).pack('v', $height)."\x80\x00\x00\x00\x00\x00\xff\xff\xff\x2c\x00\x00\x00\x00";
    }

    private function userWithRole(string $roleName): User
    {
        $this->seed(RazbudiseRolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
