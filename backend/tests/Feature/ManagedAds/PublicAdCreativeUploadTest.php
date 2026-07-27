<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdCreativeUploadClaim;
use App\Models\AdCustomer;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Ads\PendingAdCreativeCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicAdCreativeUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_advertiser_can_upload_preview_and_remove_a_creative_without_storage_details(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/ad-booking-creatives', [
            'file' => UploadedFile::fake()->image('banner-300x250.png', 300, 250)->size(145),
        ])->assertCreated()
            ->assertJsonPath('data.original_name', 'banner-300x250.png')
            ->assertJsonPath('data.width', 300)
            ->assertJsonPath('data.height', 250)
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $asset = MediaAsset::query()->sole();
        $claim = AdCreativeUploadClaim::query()->sole();
        $token = $response->json('data.upload_token');
        $this->assertSame(AdCreativeUploadClaim::STATUS_PENDING, $claim->status);
        $this->assertSame(AdCreativeUploadClaim::digest($token), $claim->token_hash);
        $this->assertArrayNotHasKey('public_booking_upload_token', $asset->metadata);
        $response->assertJsonMissingPath('data.media_id');

        $this->deleteJson('/api/ad-booking-creatives', ['upload_token' => $token])
            ->assertOk();

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseHas('ad_creative_upload_claims', [
            'id' => $claim->id,
            'media_asset_id' => null,
            'status' => AdCreativeUploadClaim::STATUS_REVOKED,
        ]);
        Storage::disk('public')->assertMissing($asset->path);
    }

    public function test_identical_anonymous_upload_reuses_blob_but_issues_isolated_claims_and_filenames(): void
    {
        Storage::fake('public');
        $file = fn (string $name) => UploadedFile::fake()->image($name, 300, 250)->size(145);

        $first = $this->postJson('/api/ad-booking-creatives', ['file' => $file('first-client.png')])
            ->assertCreated();
        $second = $this->postJson('/api/ad-booking-creatives', ['file' => $file('second-client.png')])
            ->assertCreated();

        $this->assertNotSame($first->json('data.upload_token'), $second->json('data.upload_token'));
        $this->assertSame('first-client.png', $first->json('data.original_name'));
        $this->assertSame('second-client.png', $second->json('data.original_name'));
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('ad_creative_upload_claims', 2);

        $asset = MediaAsset::query()->sole();
        $this->deleteJson('/api/ad-booking-creatives', ['upload_token' => $second->json('data.upload_token')])
            ->assertOk();
        $this->assertModelExists($asset);
        $this->assertSame(1, $asset->adCreativeUploadClaims()->where('status', 'pending')->count());

        $this->deleteJson('/api/ad-booking-creatives', ['upload_token' => $first->json('data.upload_token')])
            ->assertOk();
        $this->assertModelMissing($asset);
    }

    public function test_upload_rejects_unsupported_files_and_files_over_five_megabytes(): void
    {
        Storage::fake('public');

        $this->postJson('/api/ad-booking-creatives', [
            'file' => UploadedFile::fake()->create('creative.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->postJson('/api/ad-booking-creatives', [
            'file' => UploadedFile::fake()->image('too-large.png')->size(5121),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_failed_claim_registration_rolls_back_database_and_stored_files(): void
    {
        Storage::fake('public');
        AdCreativeUploadClaim::creating(function (): void {
            throw new \RuntimeException('Simulated claim registration failure.');
        });

        $this->postJson('/api/ad-booking-creatives', [
            'file' => UploadedFile::fake()->image('will-roll-back.png', 300, 250),
        ])->assertServerError();

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('ad_creative_media_blobs', 0);
        $this->assertDatabaseCount('ad_creative_upload_claims', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_existing_media_picker_is_available_only_to_active_customer_for_their_assets(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        AdCustomer::factory()->create(['user_id' => $owner->id, 'status' => 'active']);
        $owned = MediaAsset::factory()->create([
            'uploaded_by' => $owner->id,
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);
        MediaAsset::factory()->create([
            'uploaded_by' => $other->id,
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        $this->getJson('/api/customer/media/ad-creatives')->assertUnauthorized();

        $this->actingAs($owner)
            ->getJson('/api/customer/media/ad-creatives')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.media_id', $owned->id)
            ->assertJsonPath('data.0.upload_token', null)
            ->assertJsonMissingPath('data.0.path');

        $claim = $this->actingAs($owner)
            ->postJson("/api/customer/media/ad-creatives/{$owned->id}/claim")
            ->assertCreated()
            ->assertJsonPath('data.media_id', $owned->id)
            ->json('data.upload_token');

        $this->assertSame(AdCreativeUploadClaim::digest($claim), AdCreativeUploadClaim::query()->sole()->token_hash);

        $this->actingAs($other)
            ->postJson("/api/customer/media/ad-creatives/{$owned->id}/claim")
            ->assertForbidden();
    }

    public function test_abandoned_pending_uploads_are_pruned_but_attached_or_recent_assets_are_preserved(): void
    {
        Storage::fake('public');
        $old = MediaAsset::factory()->create([
            'path' => 'media/old.png',
            'created_at' => now()->subDays(2),
            'metadata' => ['purpose' => 'ad_creative', 'public_booking_upload_status' => 'pending'],
        ]);
        $recent = MediaAsset::factory()->create([
            'path' => 'media/recent.png',
            'metadata' => ['purpose' => 'ad_creative', 'public_booking_upload_status' => 'pending'],
        ]);
        $legacyAttached = MediaAsset::factory()->create([
            'path' => 'media/legacy-attached.png',
            'created_at' => now()->subDays(2),
            'metadata' => [
                'purpose' => 'ad_creative',
                'public_booking_upload_status' => 'attached',
                'public_booking_upload_token' => str_repeat('l', 48),
            ],
        ]);
        Storage::disk('public')->put($old->path, 'old');
        Storage::disk('public')->put($recent->path, 'recent');
        Storage::disk('public')->put($legacyAttached->path, 'attached');

        $this->assertSame(1, app(PendingAdCreativeCleanupService::class)->prune());
        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelExists($legacyAttached);
    }

    public function test_expired_claim_is_detached_and_only_unshared_public_media_is_removed(): void
    {
        Storage::fake('public');
        $response = $this->postJson('/api/ad-booking-creatives', [
            'file' => UploadedFile::fake()->image('expired.png', 300, 250),
        ])->assertCreated();
        $asset = MediaAsset::query()->sole();
        $claim = AdCreativeUploadClaim::query()->sole();
        $claim->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertSame(1, app(PendingAdCreativeCleanupService::class)->prune());
        $this->assertModelMissing($asset);
        $this->assertDatabaseHas('ad_creative_upload_claims', [
            'id' => $claim->id,
            'media_asset_id' => null,
            'status' => AdCreativeUploadClaim::STATUS_EXPIRED,
        ]);
        $this->deleteJson('/api/ad-booking-creatives', [
            'upload_token' => $response->json('data.upload_token'),
        ])->assertOk();
    }
}
