<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdCreativeUploadClaim;
use App\Models\AdCustomer;
use App\Models\AdDiscountCode;
use App\Models\AdPrice;
use App\Models\Advertiser;
use App\Models\AdZone;
use App\Models\MediaAsset;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdBookingSubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_ad_products_include_prices_dimensions_and_availability_route_data(): void
    {
        config(['app.url' => 'https://preview.example.test']);

        $zone = AdZone::factory()->create([
            'key' => 'home_in_feed_1',
            'allowed_sizes' => [[300, 250]],
            'is_active' => true,
        ]);

        AdPrice::factory()->create([
            'ad_zone_id' => $zone->id,
            'duration_weeks' => 1,
            'creative_type' => 'image',
            'price_denars' => 7500,
            'is_active' => true,
        ]);

        $this->getJson('/api/ad-products')
            ->assertOk()
            ->assertJsonPath('data.0.zone_key', 'home_in_feed_1')
            ->assertJsonPath('data.0.allowed_sizes.0.0', 300)
            ->assertJsonPath('data.0.allowed_sizes.0.1', 250)
            ->assertJsonPath('data.0.prices.0.duration_weeks', 1)
            ->assertJsonPath('data.0.prices.0.price_denars', 7500)
            ->assertJsonPath('data.0.availability_url', 'https://preview.example.test/api/ad-products/home_in_feed_1/availability');
    }

    public function test_public_customer_can_submit_booking_request_with_multiple_line_items(): void
    {
        $zone = AdZone::factory()->create([
            'key' => 'home_in_feed_1',
            'allowed_sizes' => [[300, 250]],
            'is_active' => true,
        ]);

        AdPrice::factory()->create([
            'ad_zone_id' => $zone->id,
            'duration_weeks' => 2,
            'creative_type' => 'html',
            'price_denars' => 15000,
            'is_active' => true,
        ]);

        $payload = [
            'billing' => [
                'company_name' => 'Тест Компанија ДООЕЛ',
                'tax_number' => 'MK4030999999999',
                'registration_number' => '1234567',
                'address' => 'Широк Сокак 1',
                'city' => 'Битола',
                'country' => 'MK',
            ],
            'contact' => [
                'name' => 'Александар',
                'email' => 'client@example.test',
                'phone' => '+38970111222',
            ],
            'discount_code' => null,
            'items' => [
                [
                    'zone_key' => 'home_in_feed_1',
                    'duration_weeks' => 2,
                    'start_date' => now()->addWeek()->toDateString(),
                    'creative_type' => 'html',
                    'html' => '<a href="https://example.test">Ad</a>',
                    'click_url' => 'https://example.test',
                    'width' => 300,
                    'height' => 250,
                    'is_political_or_sensitive' => false,
                ],
            ],
        ];

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'rejection_reason',
                    'items' => [
                        ['rejection_reason'],
                    ],
                ],
            ])
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.rejection_reason', null)
            ->assertJsonPath('data.items.0.rejection_reason', null)
            ->assertJsonPath('data.items.0.technical_review_required', true);
    }

    public function test_public_booking_uses_editable_organization_tax_rate_for_totals(): void
    {
        SiteSetting::query()->create([
            'key' => 'organization_billing_profile',
            'value' => [
                'tax_rate' => 0.18,
            ],
            'group' => 'billing',
            'is_public' => false,
        ]);

        $zone = $this->pricedZone('html');

        $this->postJson('/api/ad-booking-requests', $this->bookingPayload($zone->key))
            ->assertCreated()
            ->assertJsonPath('data.subtotal_denars', 15000)
            ->assertJsonPath('data.tax_rate', 0.18)
            ->assertJsonPath('data.tax_denars', 2700)
            ->assertJsonPath('data.total_denars', 17700);
    }

    public function test_public_booking_creates_isolated_customer_snapshot_for_existing_tax_number(): void
    {
        $zone = $this->pricedZone('html');
        $advertiser = Advertiser::factory()->create([
            'name' => 'Original Advertiser',
            'contact_name' => 'Original Contact',
            'contact_email' => 'original@example.test',
            'website_url' => 'https://original.example.test',
        ]);
        $customer = AdCustomer::factory()->create([
            'advertiser_id' => $advertiser->id,
            'company_name' => 'Original Company',
            'tax_number' => 'MK4030999999999',
            'registration_number' => '7654321',
            'billing_address' => 'Original Address 9',
            'billing_city' => 'Скопје',
            'billing_country' => 'MK',
            'contact_name' => 'Original Customer',
            'contact_email' => 'owner@example.test',
            'contact_phone' => '+38970000000',
            'status' => 'active',
        ]);

        $payload = $this->bookingPayload($zone->key, [
            'billing' => [
                'company_name' => 'Poisoned Company',
                'tax_number' => 'MK4030999999999',
                'registration_number' => '9999999',
                'address' => 'Poisoned Address 1',
                'city' => 'Битола',
                'country' => 'MK',
            ],
            'contact' => [
                'name' => 'Attacker',
                'email' => 'attacker@example.test',
                'phone' => '+38970111999',
            ],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertCreated()
            ->assertJsonPath('data.billing.company_name', 'Poisoned Company')
            ->assertJsonPath('data.contact.email', 'attacker@example.test');

        $bookingCustomerId = AdCustomer::query()
            ->where('tax_number', 'MK4030999999999')
            ->where('company_name', 'Poisoned Company')
            ->value('id');

        $this->assertNotNull($bookingCustomerId);
        $this->assertNotSame($customer->id, $bookingCustomerId);
        $this->assertDatabaseHas('ad_customers', [
            'id' => $bookingCustomerId,
            'advertiser_id' => null,
            'status' => 'pending',
            'company_name' => 'Poisoned Company',
            'contact_email' => 'attacker@example.test',
        ]);
        $this->assertDatabaseHas('ad_booking_requests', [
            'ad_customer_id' => $bookingCustomerId,
            'billing_company_name' => 'Poisoned Company',
            'contact_email' => 'attacker@example.test',
        ]);

        $customer->refresh();
        $advertiser->refresh();

        $this->assertSame('Original Company', $customer->company_name);
        $this->assertSame('7654321', $customer->registration_number);
        $this->assertSame('Original Address 9', $customer->billing_address);
        $this->assertSame('Скопје', $customer->billing_city);
        $this->assertSame('Original Customer', $customer->contact_name);
        $this->assertSame('owner@example.test', $customer->contact_email);
        $this->assertSame('+38970000000', $customer->contact_phone);
        $this->assertSame('active', $customer->status);
        $this->assertSame($advertiser->id, $customer->advertiser_id);
        $this->assertSame('Original Advertiser', $advertiser->name);
        $this->assertSame('Original Contact', $advertiser->contact_name);
        $this->assertSame('original@example.test', $advertiser->contact_email);
    }

    public function test_public_booking_with_existing_tax_number_does_not_appear_in_existing_customer_dashboard(): void
    {
        $zone = $this->pricedZone('html');
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        $customer = AdCustomer::factory()->create([
            'user_id' => $user->id,
            'tax_number' => 'MK4030999999999',
            'contact_email' => 'owner@example.test',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/ad-booking-requests', $this->bookingPayload($zone->key));
        $response->assertCreated();

        $this->assertDatabaseMissing('ad_booking_requests', [
            'ad_customer_id' => $customer->id,
            'reference' => $response->json('data.reference'),
        ]);

        $this->postJson('/api/customer/login', [
            'email' => 'owner@example.test',
            'password' => 'secret-password',
        ])->assertOk();

        $this->getJson('/api/customer/ads')
            ->assertOk()
            ->assertJsonCount(0, 'data.bookings')
            ->assertJsonMissing(['reference' => $response->json('data.reference')]);
    }

    public function test_public_booking_rejects_overlapping_items_in_same_request(): void
    {
        $zone = $this->pricedZone('html');
        $payload = $this->bookingPayload($zone->key);
        $payload['items'][] = [
            ...$payload['items'][0],
            'start_date' => now()->addWeek()->addDays(3)->toDateString(),
        ];

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.1.start_date');

        $this->assertDatabaseCount('ad_booking_requests', 0);
        $this->assertDatabaseCount('ad_booking_line_items', 0);
    }

    public function test_public_booking_can_be_created_when_advertiser_will_send_image_later(): void
    {
        $zone = $this->pricedZone('image');
        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'image',
                'html' => null,
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertCreated();

        $this->assertDatabaseHas('ad_booking_line_items', ['image_id' => null]);
    }

    public function test_public_booking_rejects_array_image_id_without_server_error(): void
    {
        $zone = $this->pricedZone('image');
        $image = MediaAsset::factory()->create([
            'uploaded_by' => null,
            'width' => 300,
            'height' => 250,
            'metadata' => [
                'purpose' => 'ad_creative',
                'public_booking_upload_status' => 'pending',
                'public_booking_upload_token' => str_repeat('a', 48),
            ],
        ]);
        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'image',
                'html' => null,
                'image_id' => [$image->id],
                'width' => 300,
                'height' => 250,
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_id');

        $this->assertDatabaseCount('ad_booking_line_items', 0);
    }

    public function test_public_booking_rejects_object_like_image_id_without_server_error(): void
    {
        $zone = $this->pricedZone('image');
        $image = MediaAsset::factory()->create([
            'uploaded_by' => null,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative'],
        ]);
        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'image',
                'html' => null,
                'image_id' => ['id' => $image->id],
                'width' => 300,
                'height' => 250,
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_id');

        $this->assertDatabaseCount('ad_booking_line_items', 0);
    }

    public function test_public_booking_rejects_array_zone_key_without_server_error(): void
    {
        $zone = $this->pricedZone('html');
        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'zone_key' => [$zone->key],
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.zone_key');

        $this->assertDatabaseCount('ad_booking_line_items', 0);
    }

    public function test_public_booking_requires_html_for_html_and_script_creatives(): void
    {
        $zone = $this->pricedZone('html');
        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'html',
                'html' => null,
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.html');
    }

    public function test_public_booking_rejects_html_creatives_that_carry_private_image_ids(): void
    {
        $zone = $this->pricedZone('html');
        $owner = User::factory()->create();
        $image = MediaAsset::factory()->create([
            'uploaded_by' => $owner->id,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'html',
                'html' => '<a href="https://example.test">Ad</a>',
                'image_id' => $image->id,
                'image_token' => str_repeat('a', 48),
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_id');

        $this->assertDatabaseMissing('ad_booking_line_items', [
            'image_id' => $image->id,
        ]);
    }

    public function test_public_booking_rejects_script_creatives_that_carry_private_image_ids(): void
    {
        $zone = $this->pricedZone('script');
        $owner = User::factory()->create();
        $image = MediaAsset::factory()->create([
            'uploaded_by' => $owner->id,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'script',
                'html' => '<script src="https://example.test/ad.js"></script>',
                'image_id' => $image->id,
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_id');

        $this->assertDatabaseMissing('ad_booking_line_items', [
            'image_id' => $image->id,
        ]);
    }

    public function test_public_booking_rejects_inactive_zones_and_inactive_prices(): void
    {
        $inactiveZone = $this->pricedZone('html', ['is_active' => false]);
        $activeZone = AdZone::factory()->create([
            'key' => 'home_in_feed_2',
            'allowed_sizes' => [[300, 250]],
            'is_active' => true,
        ]);
        AdPrice::factory()->create([
            'ad_zone_id' => $activeZone->id,
            'duration_weeks' => 2,
            'creative_type' => 'html',
            'price_denars' => 15000,
            'is_active' => false,
        ]);

        $this->postJson('/api/ad-booking-requests', $this->bookingPayload($inactiveZone->key))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.zone_key');

        $this->postJson('/api/ad-booking-requests', $this->bookingPayload($activeZone->key))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.duration_weeks');
    }

    public function test_public_booking_validates_image_dimensions_against_zone_sizes(): void
    {
        $zone = $this->pricedZone('image');
        $image = MediaAsset::factory()->create([
            'uploaded_by' => null,
            'width' => 728,
            'height' => 90,
            'metadata' => ['purpose' => 'ad_creative'],
        ]);
        $token = $this->claimTokenFor($image);
        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'image',
                'html' => null,
                'image_id' => $image->id,
                'image_token' => $token,
                'width' => 728,
                'height' => 90,
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_id');
    }

    public function test_public_booking_stores_image_dimensions_from_media_asset_not_payload(): void
    {
        $zone = $this->pricedZone('image');
        $image = MediaAsset::factory()->create([
            'uploaded_by' => null,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative'],
        ]);
        $token = $this->claimTokenFor($image);
        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'image',
                'html' => null,
                'image_id' => $image->id,
                'image_token' => $token,
                'width' => 1,
                'height' => 1,
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertCreated()
            ->assertJsonPath('data.items.0.width', 300)
            ->assertJsonPath('data.items.0.height', 250);

        $this->assertDatabaseHas('ad_booking_line_items', [
            'image_id' => $image->id,
            'width' => 300,
            'height' => 250,
        ]);
        $this->assertSame(
            AdCreativeUploadClaim::STATUS_ATTACHED,
            AdCreativeUploadClaim::query()->sole()->status,
        );
        $this->assertArrayNotHasKey('public_booking_upload_status', $image->refresh()->metadata);
    }

    public function test_public_booking_cannot_reuse_or_hijack_a_consumed_creative_claim(): void
    {
        $zone = $this->pricedZone('image');
        $image = MediaAsset::factory()->create([
            'uploaded_by' => null,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative'],
        ]);
        $token = $this->claimTokenFor($image);
        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'image',
                'html' => null,
                'image_token' => $token,
                // A valid claim is authoritative; clients need not know the id.
                'width' => 300,
                'height' => 250,
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)->assertCreated();
        $this->assertDatabaseHas('ad_booking_line_items', ['image_id' => $image->id]);

        $payload['items'][0]['start_date'] = now()->addWeeks(5)->toDateString();
        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_token');

        $this->deleteJson('/api/ad-booking-creatives', ['upload_token' => $token])
            ->assertConflict();
        $this->assertModelExists($image);
        $this->assertDatabaseCount('ad_booking_requests', 1);
    }

    public function test_public_booking_rejects_expired_archived_and_mismatched_claims(): void
    {
        $zone = $this->pricedZone('image');
        $image = MediaAsset::factory()->create([
            'uploaded_by' => null,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative'],
        ]);
        $other = MediaAsset::factory()->create([
            'uploaded_by' => null,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative'],
        ]);
        $token = $this->claimTokenFor($image);
        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'image',
                'html' => null,
                'image_id' => $other->id,
                'image_token' => $token,
                'width' => 300,
                'height' => 250,
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_id');

        AdCreativeUploadClaim::query()->update(['expires_at' => now()->subMinute()]);
        unset($payload['items'][0]['image_id']);
        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_token');

        AdCreativeUploadClaim::query()->update(['expires_at' => now()->addDay()]);
        $image->forceFill(['archived_at' => now()])->save();
        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_token');
    }

    public function test_public_booking_rejects_user_uploaded_ad_creative_media(): void
    {
        $zone = $this->pricedZone('image');
        $user = User::factory()->create();
        $image = MediaAsset::factory()->create([
            'uploaded_by' => $user->id,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'image',
                'html' => null,
                'image_id' => $image->id,
                'width' => 300,
                'height' => 250,
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_id');
    }

    public function test_public_booking_does_not_leak_dimensions_after_private_media_scope_failure(): void
    {
        $zone = $this->pricedZone('image');
        $user = User::factory()->create();
        $image = MediaAsset::factory()->create([
            'uploaded_by' => $user->id,
            'width' => 728,
            'height' => 90,
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'image',
                'html' => null,
                'image_id' => $image->id,
                'width' => 728,
                'height' => 90,
            ]],
        ]);

        $response = $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_id');

        $errors = $response->json('errors')['items.0.image_id'];

        $this->assertCount(1, $errors);
        $this->assertStringNotContainsString('dimensions', $errors[0]);
    }

    public function test_authenticated_public_booking_rejects_another_users_ad_creative_media(): void
    {
        $zone = $this->pricedZone('image');
        $postingUser = User::factory()->create();
        $owner = User::factory()->create();
        $image = MediaAsset::factory()->create([
            'uploaded_by' => $owner->id,
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'ad_creative', 'ad_creative_upload' => true],
        ]);

        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'image',
                'html' => null,
                'image_id' => $image->id,
                'width' => 300,
                'height' => 250,
            ]],
        ]);

        $this->actingAs($postingUser)
            ->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_id');
    }

    public function test_public_booking_rejects_unscoped_media_assets_for_image_creatives(): void
    {
        $zone = $this->pricedZone('image');
        $image = MediaAsset::factory()->create([
            'width' => 300,
            'height' => 250,
            'metadata' => ['purpose' => 'article_image'],
        ]);
        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'creative_type' => 'image',
                'html' => null,
                'image_id' => $image->id,
                'width' => 300,
                'height' => 250,
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.image_id');
    }

    public function test_public_booking_restricts_click_url_to_http_or_https(): void
    {
        $zone = $this->pricedZone('html');

        $payload = $this->bookingPayload($zone->key, [
            'items' => [[
                'click_url' => 'ftp://example.test/file',
            ]],
        ]);

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.click_url');

        $payload['items'][0]['click_url'] = 'https://example.test/ad';

        $this->postJson('/api/ad-booking-requests', $payload)
            ->assertCreated();
    }

    public function test_public_booking_consumes_limited_discount_once(): void
    {
        $zone = $this->pricedZone('html');
        $discountCode = AdDiscountCode::factory()->create([
            'code' => 'ONCE10',
            'type' => 'percent',
            'value' => 10,
            'usage_limit' => 1,
            'used_count' => 0,
        ]);

        $firstPayload = $this->bookingPayload($zone->key);
        $firstPayload['discount_code'] = 'ONCE10';

        $this->postJson('/api/ad-booking-requests', $firstPayload)
            ->assertCreated()
            ->assertJsonPath('data.discount_denars', 1500);

        $this->assertSame(1, $discountCode->refresh()->used_count);

        $secondPayload = $this->bookingPayload($zone->key, [
            'items' => [[
                'start_date' => now()->addWeeks(4)->toDateString(),
            ]],
        ]);
        $secondPayload['discount_code'] = 'ONCE10';

        $this->postJson('/api/ad-booking-requests', $secondPayload)
            ->assertCreated()
            ->assertJsonPath('data.discount_denars', 0);

        $this->assertSame(1, $discountCode->refresh()->used_count);
    }

    private function pricedZone(string $creativeType, array $zoneAttributes = []): AdZone
    {
        $zone = AdZone::factory()->create([
            'key' => $zoneAttributes['key'] ?? 'home_in_feed_1',
            'allowed_sizes' => [[300, 250]],
            'is_active' => $zoneAttributes['is_active'] ?? true,
        ]);

        AdPrice::factory()->create([
            'ad_zone_id' => $zone->id,
            'duration_weeks' => 2,
            'creative_type' => $creativeType,
            'price_denars' => 15000,
            'is_active' => $zoneAttributes['price_is_active'] ?? true,
        ]);

        return $zone;
    }

    private function bookingPayload(string $zoneKey, array $overrides = []): array
    {
        $base = [
            'billing' => [
                'company_name' => 'Тест Компанија ДООЕЛ',
                'tax_number' => 'MK4030999999999',
                'registration_number' => '1234567',
                'address' => 'Широк Сокак 1',
                'city' => 'Битола',
                'country' => 'MK',
            ],
            'contact' => [
                'name' => 'Александар',
                'email' => 'client@example.test',
                'phone' => '+38970111222',
            ],
            'discount_code' => null,
            'items' => [
                [
                    'zone_key' => $zoneKey,
                    'duration_weeks' => 2,
                    'start_date' => now()->addWeek()->toDateString(),
                    'creative_type' => 'html',
                    'html' => '<a href="https://example.test">Ad</a>',
                    'click_url' => 'https://example.test',
                    'width' => 300,
                    'height' => 250,
                    'is_political_or_sensitive' => false,
                ],
            ],
        ];

        foreach ($overrides as $key => $value) {
            if ($key === 'items') {
                foreach ($value as $index => $itemOverrides) {
                    $base['items'][$index] = array_replace($base['items'][$index] ?? [], $itemOverrides);
                }

                continue;
            }

            $base[$key] = array_replace($base[$key], $value);
        }

        return $base;
    }

    private function claimTokenFor(MediaAsset $image): string
    {
        $token = Str::random(AdCreativeUploadClaim::TOKEN_LENGTH);

        AdCreativeUploadClaim::factory()->create([
            'media_asset_id' => $image->id,
            'token_hash' => AdCreativeUploadClaim::digest($token),
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
