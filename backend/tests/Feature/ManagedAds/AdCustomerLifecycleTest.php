<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdCustomer;
use App\Models\AdInvoice;
use App\Models\AdPrice;
use App\Models\AdZone;
use App\Models\MediaAsset;
use App\Models\Role;
use App\Models\User;
use App\Services\Ads\AdCustomerLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdCustomerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_customer_login_is_deactivated_after_three_months(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));

        $user = User::factory()->create([
            'email' => 'inactive-client@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        $customer = AdCustomer::factory()->create([
            'user_id' => $user->id,
            'contact_email' => 'inactive-client@example.test',
            'status' => 'active',
            'last_active_at' => now()->subMonths(3)->subMinute(),
        ]);

        $deactivated = app(AdCustomerLifecycleService::class)
            ->deactivateInactiveCustomers(now()->subMonths(3));

        $this->assertSame(1, $deactivated);
        $this->assertDatabaseHas('ad_customers', [
            'id' => $customer->id,
            'status' => 'former',
        ]);
        $this->assertNotNull($customer->fresh()->dashboard_deactivated_at);
        $this->assertNotNull($customer->fresh()->former_customer_at);

        $this->postJson('/api/customer/login', [
            'email' => 'inactive-client@example.test',
            'password' => 'secret-password',
        ])->assertForbidden();
    }

    public function test_customer_history_remains_visible_after_deactivation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));

        $admin = $this->userWithRole('admin');
        $customer = AdCustomer::factory()->create([
            'status' => 'active',
            'last_active_at' => now()->subMonths(4),
        ]);
        $booking = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'reference' => 'RAZ-HISTORY-0001',
                'status' => 'published',
                'payment_status' => 'paid',
            ]);
        AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->create([
                'status' => 'published',
                'start_at' => now()->subMonths(6),
                'end_at' => now()->subMonths(5),
            ]);
        $invoice = AdInvoice::factory()
            ->for($booking, 'bookingRequest')
            ->create(['number' => 'PROF-2026-000123']);

        app(AdCustomerLifecycleService::class)
            ->deactivateInactiveCustomers(now()->subMonths(3));

        $this->assertDatabaseHas('ad_booking_requests', [
            'id' => $booking->id,
            'ad_customer_id' => $customer->id,
        ]);
        $this->assertDatabaseHas('ad_invoices', [
            'id' => $invoice->id,
            'ad_booking_request_id' => $booking->id,
        ]);

        $this->actingAs($admin)
            ->get('/api/customer/invoices/'.$invoice->id.'/download')
            ->assertOk()
            ->assertSee('RAZ-HISTORY-0001', false)
            ->assertSee('PROF-2026-000123', false);
    }

    public function test_returning_inactive_customer_must_submit_new_request_after_six_months(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));

        $user = User::factory()->create([
            'email' => 'returning-client@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        $formerCustomer = AdCustomer::factory()->create([
            'user_id' => $user->id,
            'contact_email' => 'returning-client@example.test',
            'tax_number' => 'MK4030999999999',
            'status' => 'former',
            'last_active_at' => now()->subMonths(7),
            'dashboard_deactivated_at' => now()->subMonths(6),
            'former_customer_at' => now()->subMonths(6),
        ]);
        $zone = $this->pricedZone();

        $this->postJson('/api/customer/login', [
            'email' => 'returning-client@example.test',
            'password' => 'secret-password',
        ])->assertForbidden();

        $this->postJson('/api/ad-booking-requests', $this->bookingPayload($zone->key))
            ->assertCreated()
            ->assertJsonPath('data.contact.email', 'returning-client@example.test');

        $this->assertDatabaseHas('ad_customers', [
            'id' => $formerCustomer->id,
            'status' => 'former',
        ]);
        $this->assertDatabaseHas('ad_customers', [
            'tax_number' => 'MK4030999999999',
            'contact_email' => 'returning-client@example.test',
            'status' => 'pending',
        ]);
        $this->assertSame(2, AdCustomer::query()->where('tax_number', 'MK4030999999999')->count());
    }

    public function test_paid_current_or_future_line_items_block_deactivation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));

        $customer = AdCustomer::factory()->create([
            'status' => 'active',
            'last_active_at' => now()->subMonths(4),
        ]);
        $booking = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'published',
                'payment_status' => 'paid',
            ]);
        AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->create([
                'status' => 'published',
                'start_at' => now()->subDay(),
                'end_at' => now()->addWeek(),
            ]);

        $deactivated = app(AdCustomerLifecycleService::class)
            ->deactivateInactiveCustomers(now()->subMonths(3));

        $this->assertSame(0, $deactivated);
        $this->assertDatabaseHas('ad_customers', [
            'id' => $customer->id,
            'status' => 'active',
            'dashboard_deactivated_at' => null,
            'former_customer_at' => null,
        ]);
    }

    public function test_current_or_future_booking_line_items_block_deactivation_even_before_parent_booking_is_paid(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));

        $customer = AdCustomer::factory()->create([
            'status' => 'active',
            'last_active_at' => now()->subMonths(4),
        ]);
        $booking = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'status' => 'pending_review',
                'payment_status' => 'unpaid',
            ]);
        AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->create([
                'status' => 'approved',
                'start_at' => now()->addDay(),
                'end_at' => now()->addWeeks(2),
            ]);

        $deactivated = app(AdCustomerLifecycleService::class)
            ->deactivateInactiveCustomers(now()->subMonths(3));

        $this->assertSame(0, $deactivated);
        $this->assertDatabaseHas('ad_customers', [
            'id' => $customer->id,
            'status' => 'active',
            'dashboard_deactivated_at' => null,
            'former_customer_at' => null,
        ]);
    }

    public function test_deactivate_inactive_customers_command_supports_dry_run(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));

        $customer = AdCustomer::factory()->create([
            'status' => 'active',
            'last_active_at' => now()->subMonths(4),
        ]);

        $this->artisan('ads:deactivate-inactive-customers', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('Eligible inactive customers: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('ad_customers', [
            'id' => $customer->id,
            'status' => 'active',
            'dashboard_deactivated_at' => null,
            'former_customer_at' => null,
        ]);
    }

    public function test_purge_expired_draft_assets_preserves_direct_ad_creative_images(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));
        Storage::fake('public');
        Storage::disk('public')->put('uploads/direct-creative.jpg', 'image-bytes');

        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'uploads/direct-creative.jpg',
            'metadata' => ['purpose' => 'ad_creative'],
            'created_at' => now()->subMonths(2),
        ]);
        AdCreative::query()->create([
            'campaign_id' => AdCampaign::factory()->create()->id,
            'name' => 'Protected direct creative',
            'type' => 'image',
            'image_id' => $asset->id,
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);

        $purged = app(AdCustomerLifecycleService::class)
            ->purgeExpiredDraftAssets(now()->subMonth());

        $this->assertSame(0, $purged);
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertExists('uploads/direct-creative.jpg');
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::query()->create([
            'name' => $roleName,
            'label' => ucfirst($roleName),
        ]);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    private function pricedZone(): AdZone
    {
        $zone = AdZone::factory()->create([
            'key' => 'returning_customer_test_zone',
            'allowed_sizes' => [[300, 250]],
            'is_active' => true,
        ]);
        AdPrice::factory()->create([
            'ad_zone_id' => $zone->id,
            'duration_weeks' => 2,
            'creative_type' => 'html',
            'price_denars' => 18000,
            'is_active' => true,
        ]);

        return $zone;
    }

    private function bookingPayload(string $zoneKey): array
    {
        return [
            'billing' => [
                'company_name' => 'Повратник Компанија ДООЕЛ',
                'tax_number' => 'MK4030999999999',
                'registration_number' => '1234567',
                'address' => 'Широк Сокак 1',
                'city' => 'Битола',
                'country' => 'MK',
            ],
            'contact' => [
                'name' => 'Клиент Повратник',
                'email' => 'returning-client@example.test',
                'phone' => '+38970111222',
            ],
            'discount_code' => null,
            'items' => [[
                'zone_key' => $zoneKey,
                'duration_weeks' => 2,
                'start_date' => now()->addWeek()->toDateString(),
                'creative_type' => 'html',
                'html' => '<a href="https://example.test">Ad</a>',
                'click_url' => 'https://example.test',
                'width' => 300,
                'height' => 250,
                'is_political_or_sensitive' => false,
            ]],
        ];
    }
}
