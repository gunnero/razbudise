<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdCustomer;
use App\Models\AdPrice;
use App\Models\AdZone;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Ads\AdBookingPublishingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerAdDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_dashboard_requires_login(): void
    {
        $this->getJson('/api/customer/ads')
            ->assertUnauthorized();
    }

    public function test_paid_customer_can_login_and_only_see_own_bookings(): void
    {
        $user = User::factory()->create([
            'email' => 'client@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        $otherUser = User::factory()->create();
        $zone = AdZone::factory()->create(['key' => 'home_in_feed_1']);

        $customer = AdCustomer::factory()->create([
            'user_id' => $user->id,
            'contact_email' => 'client@example.test',
            'status' => 'active',
        ]);
        $otherCustomer = AdCustomer::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'active',
        ]);

        $ownBooking = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'reference' => 'RAZ-OWN-0001',
                'status' => 'paid',
                'payment_status' => 'paid',
            ]);
        $otherBooking = AdBookingRequest::factory()
            ->for($otherCustomer, 'customer')
            ->create([
                'reference' => 'RAZ-OTHER-0001',
                'status' => 'paid',
                'payment_status' => 'paid',
            ]);

        AdBookingLineItem::factory()
            ->for($ownBooking, 'bookingRequest')
            ->for($zone, 'zone')
            ->create(['status' => 'approved']);
        AdBookingLineItem::factory()
            ->for($otherBooking, 'bookingRequest')
            ->for($zone, 'zone')
            ->create(['status' => 'approved']);

        $this->postJson('/api/customer/login', [
            'email' => 'client@example.test',
            'password' => 'secret-password',
        ])->assertOk();

        $this->getJson('/api/customer/ads')
            ->assertOk()
            ->assertJsonCount(1, 'data.bookings')
            ->assertJsonPath('data.bookings.0.reference', 'RAZ-OWN-0001')
            ->assertJsonMissing(['reference' => 'RAZ-OTHER-0001']);
    }

    public function test_customer_dashboard_shows_booking_and_line_item_rejection_reasons(): void
    {
        $user = User::factory()->create([
            'email' => 'rejected-client@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        $customer = AdCustomer::factory()->create([
            'user_id' => $user->id,
            'contact_email' => 'rejected-client@example.test',
            'status' => 'active',
        ]);
        $booking = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'reference' => 'RAZ-REJECTED-0001',
                'status' => 'pending_review',
            ]);
        AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->for(AdZone::factory()->create(['key' => 'article_after_title']), 'zone')
            ->create(['status' => 'pending_review']);

        app(AdBookingPublishingService::class)->rejectBooking(
            $booking,
            'Creative must include the sponsor disclosure.',
            User::factory()->create(),
        );

        $this->actingAs($user)
            ->getJson('/api/customer/ads')
            ->assertOk()
            ->assertJsonPath('data.bookings.0.reference', 'RAZ-REJECTED-0001')
            ->assertJsonPath('data.bookings.0.rejection_reason', 'Creative must include the sponsor disclosure.')
            ->assertJsonPath('data.bookings.0.items.0.rejection_reason', 'Creative must include the sponsor disclosure.');
    }

    public function test_customer_dashboard_serializes_line_items_without_lazy_loading_parent_booking(): void
    {
        [$user, $lineItem] = $this->customerLineItem('lazy-load-client@example.test');
        AdBookingLineItem::factory()
            ->for($lineItem->bookingRequest, 'bookingRequest')
            ->for($lineItem->zone, 'zone')
            ->create(['status' => 'approved']);

        $parentBookingLookups = 0;

        DB::listen(function (QueryExecuted $query) use (&$parentBookingLookups, $lineItem): void {
            $sql = str_replace(['`', '"'], '', strtolower($query->sql));

            if (
                str_contains($sql, 'from ad_booking_requests')
                && str_contains($sql, 'ad_booking_requests.id = ?')
                && in_array($lineItem->ad_booking_request_id, $query->bindings, true)
            ) {
                $parentBookingLookups++;
            }
        });

        $this->actingAs($user)
            ->getJson('/api/customer/ads')
            ->assertOk();

        $this->assertSame(0, $parentBookingLookups);
    }

    public function test_customer_csrf_bootstrap_token_allows_session_login_with_csrf_enabled(): void
    {
        $this->app['env'] = 'production';

        $user = User::factory()->create([
            'email' => 'csrf-client@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        AdCustomer::factory()->create([
            'user_id' => $user->id,
            'contact_email' => 'csrf-client@example.test',
            'status' => 'active',
        ]);

        $this->postJson('/api/customer/login', [
            'email' => 'csrf-client@example.test',
            'password' => 'secret-password',
        ])->assertStatus(419);

        $token = $this->getJson('/api/customer/csrf-token')
            ->assertOk()
            ->assertJsonStructure(['data' => ['csrf_token']])
            ->json('data.csrf_token');

        $this->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/customer/login', [
                'email' => 'csrf-client@example.test',
                'password' => 'secret-password',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'csrf-client@example.test');
    }

    public function test_inactive_or_deactivated_customers_cannot_use_customer_dashboard(): void
    {
        $inactiveUser = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        AdCustomer::factory()->create([
            'user_id' => $inactiveUser->id,
            'status' => 'inactive',
            'dashboard_deactivated_at' => null,
        ]);

        $deactivatedUser = User::factory()->create([
            'email' => 'deactivated@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        AdCustomer::factory()->create([
            'user_id' => $deactivatedUser->id,
            'status' => 'active',
            'dashboard_deactivated_at' => now(),
        ]);

        $this->postJson('/api/customer/login', [
            'email' => 'inactive@example.test',
            'password' => 'secret-password',
        ])->assertForbidden();

        $this->postJson('/api/customer/login', [
            'email' => 'deactivated@example.test',
            'password' => 'secret-password',
        ])->assertForbidden();

        $this->actingAs($inactiveUser)
            ->getJson('/api/customer/ads')
            ->assertForbidden();

        $this->actingAs($deactivatedUser)
            ->getJson('/api/customer/ads')
            ->assertForbidden();
    }

    public function test_former_customers_cannot_login_view_dashboard_or_request_renewal(): void
    {
        [$user, $lineItem] = $this->customerLineItem('former@example.test');
        $lineItem->bookingRequest->customer->forceFill([
            'former_customer_at' => now(),
        ])->save();

        $this->postJson('/api/customer/login', [
            'email' => 'former@example.test',
            'password' => 'password',
        ])->assertForbidden();

        $this->actingAs($user)
            ->getJson('/api/customer/ads')
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/customer/bookings/'.$lineItem->id.'/renew')
            ->assertForbidden();

        $this->assertDatabaseCount('ad_booking_requests', 1);
    }

    public function test_customer_logout_route_is_explicitly_throttled(): void
    {
        $route = app('router')->getRoutes()->match(
            request()->create('/api/customer/logout', 'POST'),
        );

        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
    }

    public function test_customer_renewal_creates_reviewable_booking_record_and_denies_cross_customer_access(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        [$otherUser, $otherLineItem] = $this->customerLineItem('other@example.test');
        $this->actingAs($user);

        $this->postJson('/api/customer/bookings/'.$lineItem->id.'/renew', [
            'duration_weeks' => 2,
        ])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'renewal_request_received');

        $this->assertDatabaseHas('ad_booking_requests', [
            'ad_customer_id' => $lineItem->bookingRequest->ad_customer_id,
            'status' => 'needs_payment',
            'payment_status' => 'unpaid',
        ]);
        $this->assertDatabaseCount('ad_booking_requests', 3);

        $this->actingAs($otherUser)
            ->postJson('/api/customer/bookings/'.$lineItem->id.'/renew')
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/customer/bookings/'.$otherLineItem->id.'/renew')
            ->assertForbidden();
    }

    public function test_customer_renewal_rejects_occupied_next_slot_and_duplicate_renewal_holds(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        $this->actingAs($user);

        $this->postJson('/api/customer/bookings/'.$lineItem->id.'/renew', [
            'duration_weeks' => 2,
        ])
            ->assertAccepted();

        $this->postJson('/api/customer/bookings/'.$lineItem->id.'/renew', [
            'duration_weeks' => 2,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');

        $this->assertDatabaseCount('ad_booking_requests', 2);

        [$otherUser, $otherLineItem] = $this->customerLineItem('occupied@example.test');
        $blockingBooking = AdBookingRequest::factory()->create([
            'status' => 'paid',
            'payment_status' => 'paid',
            'hold_expires_at' => now()->addDay(),
        ]);
        AdBookingLineItem::factory()
            ->for($blockingBooking, 'bookingRequest')
            ->for($otherLineItem->zone, 'zone')
            ->create([
                'status' => 'approved',
                'start_at' => $otherLineItem->end_at,
                'end_at' => $otherLineItem->end_at->copy()->addWeeks((int) $otherLineItem->duration_weeks),
            ]);

        $this->actingAs($otherUser)
            ->postJson('/api/customer/bookings/'.$otherLineItem->id.'/renew', [
                'duration_weeks' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    public function test_customer_renewal_denies_pending_or_rejected_source_line_items(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        $lineItem->bookingRequest->forceFill([
            'status' => 'pending_review',
            'payment_status' => 'unpaid',
        ])->save();
        $lineItem->forceFill(['status' => 'pending_review'])->save();

        $this->actingAs($user)
            ->postJson('/api/customer/bookings/'.$lineItem->id.'/renew')
            ->assertForbidden();

        $lineItem->bookingRequest->forceFill([
            'status' => 'paid',
            'payment_status' => 'paid',
        ])->save();
        $lineItem->forceFill(['status' => 'rejected'])->save();

        $this->actingAs($user)
            ->postJson('/api/customer/bookings/'.$lineItem->id.'/renew')
            ->assertForbidden();

        $this->assertDatabaseCount('ad_booking_requests', 1);
    }

    public function test_cross_customer_creative_revision_is_denied(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        [$otherUser] = $this->customerLineItem('other@example.test');

        $this->actingAs($otherUser)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'html',
                'html' => '<a href="https://example.test">Updated</a>',
                'click_url' => 'https://example.test',
                'width' => 300,
                'height' => 250,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'html',
                'html' => '<a href="https://example.test">Updated</a>',
                'click_url' => 'https://example.test',
                'width' => 300,
                'height' => 250,
            ])
            ->assertCreated()
            ->assertJsonPath('data.technical_review_required', true);
    }

    public function test_cross_customer_creative_revision_is_denied_before_payload_validation(): void
    {
        [, $lineItem] = $this->customerLineItem('client@example.test');
        [$otherUser] = $this->customerLineItem('other@example.test');

        $this->actingAs($otherUser)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'html',
                'click_url' => 'ftp://example.test/file',
                'width' => 300,
                'height' => 250,
            ])
            ->assertForbidden()
            ->assertJsonMissingValidationErrors(['html', 'click_url']);
    }

    public function test_customer_creative_revision_requires_creative_payload_for_type(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        $this->actingAs($user);

        $this->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
            'creative_type' => 'html',
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('html');

        $this->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
            'creative_type' => 'image',
            'click_url' => 'https://example.test',
            'width' => 300,
            'height' => 250,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_id');
    }

    public function test_customer_creative_revision_rejects_array_image_id_without_server_error(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        $lineItem->zone->forceFill(['allowed_sizes' => [[300, 250]]])->save();
        $image = MediaAsset::factory()->create([
            'uploaded_by' => $user->id,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative'],
        ]);

        $this->actingAs($user)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'image',
                'image_id' => [$image->id],
                'click_url' => 'https://example.test',
                'width' => 300,
                'height' => 250,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_id');

        $this->assertDatabaseMissing('ad_creative_revisions', [
            'ad_booking_line_item_id' => $lineItem->id,
        ]);
    }

    public function test_customer_creative_revision_rejects_object_like_image_id_without_server_error(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        $lineItem->zone->forceFill(['allowed_sizes' => [[300, 250]]])->save();
        $image = MediaAsset::factory()->create([
            'uploaded_by' => $user->id,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative'],
        ]);

        $this->actingAs($user)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'image',
                'image_id' => ['id' => $image->id],
                'click_url' => 'https://example.test',
                'width' => 300,
                'height' => 250,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_id');

        $this->assertDatabaseMissing('ad_creative_revisions', [
            'ad_booking_line_item_id' => $lineItem->id,
        ]);
    }

    public function test_customer_image_creative_revision_stores_dimensions_from_media_asset_not_payload(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        $lineItem->zone->forceFill(['allowed_sizes' => [[300, 250]]])->save();
        $image = MediaAsset::factory()->create([
            'uploaded_by' => $user->id,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative'],
        ]);

        $this->actingAs($user)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'image',
                'image_id' => $image->id,
                'click_url' => 'https://example.test',
                'width' => 1,
                'height' => 1,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('ad_creative_revisions', [
            'ad_booking_line_item_id' => $lineItem->id,
            'image_id' => $image->id,
            'width' => 300,
            'height' => 250,
        ]);
    }

    public function test_customer_creative_revision_rejects_unowned_or_unscoped_media_assets(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        $otherUser = User::factory()->create();
        $lineItem->zone->forceFill(['allowed_sizes' => [[300, 250]]])->save();
        $unscopedOwnImage = MediaAsset::factory()->create([
            'uploaded_by' => $user->id,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'article_image'],
        ]);
        $otherUserImage = MediaAsset::factory()->create([
            'uploaded_by' => $otherUser->id,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative'],
        ]);

        foreach ([$unscopedOwnImage, $otherUserImage] as $image) {
            $this->actingAs($user)
                ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                    'creative_type' => 'image',
                    'image_id' => $image->id,
                    'click_url' => 'https://example.test',
                    'width' => 300,
                    'height' => 250,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('image_id');
        }
    }

    public function test_customer_creative_revision_does_not_leak_dimensions_after_media_scope_failure(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        $otherUser = User::factory()->create();
        $lineItem->zone->forceFill(['allowed_sizes' => [[300, 250]]])->save();
        $otherUserImage = MediaAsset::factory()->create([
            'uploaded_by' => $otherUser->id,
            'width' => 728,
            'height' => 90,
            'metadata' => ['purpose' => 'ad_creative'],
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'image',
                'image_id' => $otherUserImage->id,
                'click_url' => 'https://example.test',
                'width' => 728,
                'height' => 90,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_id');

        $this->assertCount(1, $response->json('errors.image_id'));
        $this->assertStringNotContainsString('dimensions', $response->json('errors.image_id.0'));
    }

    public function test_customer_html_creative_revision_rejects_another_users_image_id(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        $otherUser = User::factory()->create();
        $image = MediaAsset::factory()->create([
            'uploaded_by' => $otherUser->id,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        $this->actingAs($user)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'html',
                'html' => '<a href="https://example.test">Updated</a>',
                'image_id' => $image->id,
                'click_url' => 'https://example.test',
                'width' => 300,
                'height' => 250,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_id');

        $this->assertDatabaseMissing('ad_creative_revisions', [
            'image_id' => $image->id,
        ]);
    }

    public function test_customer_script_creative_revision_rejects_another_users_image_id(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        $otherUser = User::factory()->create();
        $image = MediaAsset::factory()->create([
            'uploaded_by' => $otherUser->id,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        $this->actingAs($user)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'script',
                'html' => '<script src="https://example.test/ad.js"></script>',
                'image_id' => $image->id,
                'click_url' => 'https://example.test',
                'width' => 300,
                'height' => 250,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_id');

        $this->assertDatabaseMissing('ad_creative_revisions', [
            'image_id' => $image->id,
        ]);
    }

    public function test_customer_creative_revision_restricts_click_url_to_http_or_https(): void
    {
        [$user, $lineItem] = $this->customerLineItem('client@example.test');

        $this->actingAs($user)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'html',
                'html' => '<a href="https://example.test">Updated</a>',
                'click_url' => 'ftp://example.test/file',
                'width' => 300,
                'height' => 250,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('click_url');

        $this->actingAs($user)
            ->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
                'creative_type' => 'html',
                'html' => '<a href="https://example.test">Updated</a>',
                'click_url' => 'https://example.test/ad',
                'width' => 300,
                'height' => 250,
            ])
            ->assertCreated();
    }

    public function test_authenticated_customer_cannot_use_editorial_media_upload_for_ad_creative(): void
    {
        Storage::fake('public');

        [$user] = $this->customerLineItem('client@example.test');

        $this->actingAs($user)
            ->postJson('/api/media-assets', [
                'file' => UploadedFile::fake()->image('ad-creative.png', 300, 250)->size(128),
                'alt_text' => 'Ad creative',
                'purpose' => 'ad_creative',
            ])
            ->assertForbidden();

        $this->assertSame(0, MediaAsset::query()->count());
    }

    public function test_logged_in_customer_can_upload_ad_creative_media_and_submit_revision(): void
    {
        Storage::fake('public');

        [$user, $lineItem] = $this->customerLineItem('client@example.test');
        $lineItem->zone->forceFill(['allowed_sizes' => [[300, 250]]])->save();

        $this->postJson('/api/customer/login', [
            'email' => 'client@example.test',
            'password' => 'password',
        ])->assertOk();

        $uploadResponse = $this->postJson('/api/customer/media/ad-creatives', [
            'file' => UploadedFile::fake()->image('customer-ad.png', 300, 250)->size(128),
            'alt_text' => 'Customer ad creative',
        ])
            ->assertCreated()
            ->assertJsonPath('data.width', 300)
            ->assertJsonPath('data.height', 250);

        $asset = MediaAsset::query()->findOrFail($uploadResponse->json('data.id'));

        $this->assertSame($user->id, $asset->uploaded_by);
        $this->assertSame('ad_creative', $asset->metadata['purpose'] ?? null);
        $this->assertTrue($asset->metadata['ad_creative_upload'] ?? false);

        $this->postJson('/api/customer/creatives/'.$lineItem->id.'/revision', [
            'creative_type' => 'image',
            'image_id' => $asset->id,
            'click_url' => 'https://example.test',
            'width' => 1,
            'height' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.technical_review_required', false);

        $this->assertDatabaseHas('ad_creative_revisions', [
            'ad_booking_line_item_id' => $lineItem->id,
            'image_id' => $asset->id,
            'width' => 300,
            'height' => 250,
        ]);
    }

    public function test_inactive_customer_cannot_upload_ad_creative_media(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'email' => 'inactive-upload@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        AdCustomer::factory()->create([
            'user_id' => $user->id,
            'status' => 'inactive',
        ]);

        $this->actingAs($user)
            ->postJson('/api/customer/media/ad-creatives', [
                'file' => UploadedFile::fake()->image('customer-ad.png', 300, 250)->size(128),
            ])
            ->assertForbidden();
    }

    public function test_former_customer_cannot_upload_ad_creative_media(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'email' => 'former-upload@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        AdCustomer::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'former_customer_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/customer/media/ad-creatives', [
                'file' => UploadedFile::fake()->image('customer-ad.png', 300, 250)->size(128),
            ])
            ->assertForbidden();
    }

    public function test_dashboard_deactivated_customer_cannot_upload_ad_creative_media(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'email' => 'deactivated-upload@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        AdCustomer::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'dashboard_deactivated_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/customer/media/ad-creatives', [
                'file' => UploadedFile::fake()->image('customer-ad.png', 300, 250)->size(128),
            ])
            ->assertForbidden();
    }

    public function test_public_media_index_requires_authentication(): void
    {
        MediaAsset::factory()->create([
            'path' => 'media/public-image.jpg',
            'metadata' => ['purpose' => 'article_image'],
        ]);
        MediaAsset::factory()->create([
            'path' => 'media/customer-ad.png',
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        $this->getJson('/api/media-assets')
            ->assertUnauthorized();
    }

    public function test_active_customer_cannot_use_generic_media_index(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        AdCustomer::factory()->create([
            'user_id' => $owner->id,
            'status' => 'active',
        ]);
        $normalMedia = MediaAsset::factory()->create([
            'path' => 'media/public-image.jpg',
            'metadata' => ['purpose' => 'article_image'],
        ]);
        $ownNonAdMedia = MediaAsset::factory()->create([
            'uploaded_by' => $owner->id,
            'path' => 'media/own-article-image.png',
            'metadata' => ['purpose' => 'article_image'],
        ]);
        $ownAdCreative = MediaAsset::factory()->create([
            'uploaded_by' => $owner->id,
            'path' => 'media/own-ad.png',
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);
        $otherAdCreative = MediaAsset::factory()->create([
            'uploaded_by' => $otherUser->id,
            'path' => 'media/other-ad.png',
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        $this->actingAs($owner)
            ->getJson('/api/media-assets')
            ->assertForbidden();

        $this->assertFalse($owner->can('viewAny', MediaAsset::class));
        $this->assertTrue($owner->can('view', $ownAdCreative));
        $this->assertFalse($owner->can('view', $normalMedia));
        $this->assertFalse($owner->can('view', $ownNonAdMedia));
        $this->assertFalse($owner->can('view', $otherAdCreative));
    }

    private function customerLineItem(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $customer = AdCustomer::factory()->create([
            'user_id' => $user->id,
            'contact_email' => $email,
            'status' => 'active',
        ]);
        $booking = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'paid',
                'payment_status' => 'paid',
            ]);
        $lineItem = AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->for($zone = AdZone::factory()->create(['key' => 'home_in_feed_'.fake()->unique()->numberBetween(10, 999)]), 'zone')
            ->create(['status' => 'approved']);

        foreach ([1 => 9000, 2 => 16000, 4 => 30000] as $durationWeeks => $priceDenars) {
            AdPrice::factory()->create([
                'ad_zone_id' => $zone->id,
                'duration_weeks' => $durationWeeks,
                'creative_type' => $lineItem->creative_type,
                'price_denars' => $priceDenars,
                'is_active' => true,
            ]);
        }

        return [$user, $lineItem];
    }
}
