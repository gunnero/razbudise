<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdCustomer;
use App\Models\AdZone;
use App\Models\Role;
use App\Models\User;
use App\Services\Ads\AdInvoiceDocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdInvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_document_contains_reference_company_totals_and_zero_tax(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));
        Storage::fake('local');

        $booking = $this->bookingWithLineItem();

        $invoice = app(AdInvoiceDocumentService::class)->issueForBookingRequest($booking);

        Storage::disk('local')->assertExists($invoice->document_path);
        $html = Storage::disk('local')->get($invoice->document_path);

        $this->assertStringContainsString('Профактура / Барање за плаќање', $html);
        $this->assertStringContainsString('RAZ-PROFORMA-0001', $html);
        $this->assertStringContainsString('Тест Компанија ДООЕЛ', $html);
        $this->assertStringContainsString('home_after_featured', $html);
        $this->assertStringContainsString('ДДВ/данок: 0%', $html);
        $this->assertStringContainsString('Вкупно: 18.000 ден.', $html);
    }

    public function test_invoice_document_contains_razbudise_issuer_and_payment_details(): void
    {
        Storage::fake('local');

        $booking = $this->bookingWithLineItem();

        $invoice = app(AdInvoiceDocumentService::class)->issueForBookingRequest($booking);
        $html = Storage::disk('local')->get($invoice->document_path);

        $this->assertStringContainsString('Здружение за културно информирање РАЗБУДИ СЕ ДЕНЕС Битола', $html);
        $this->assertStringContainsString('ЕМБС: 7760388', $html);
        $this->assertStringContainsString('ЕДБ: 4002024573440', $html);
        $this->assertStringContainsString('БОШКО НАЈДОВСКИ бр.1Б, БИТОЛА, БИТОЛА', $html);
        $this->assertStringContainsString('НЛБ Банка АД Скопје', $html);
        $this->assertStringContainsString('210077603880171', $html);
        $this->assertStringContainsString('Дејност: 94.99', $html);
    }

    public function test_invoice_download_requires_customer_ownership_or_admin_access(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $admin = $this->userWithRole('admin');
        $booking = $this->bookingWithLineItem($owner);
        $invoice = app(AdInvoiceDocumentService::class)->issueForBookingRequest($booking);

        $this->getJson('/api/customer/invoices/'.$invoice->id.'/download')
            ->assertUnauthorized();

        $this->actingAs($otherUser)
            ->get('/api/customer/invoices/'.$invoice->id.'/download')
            ->assertForbidden();

        $this->actingAs($owner)
            ->get('/api/customer/invoices/'.$invoice->id.'/download')
            ->assertOk()
            ->assertSee('Профактура / Барање за плаќање', false);

        $this->actingAs($admin)
            ->get('/api/customer/invoices/'.$invoice->id.'/download')
            ->assertOk()
            ->assertSee('RAZ-PROFORMA-0001', false);
    }

    public function test_invoice_download_regenerates_from_safe_invoice_path(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $booking = $this->bookingWithLineItem($owner);
        $invoice = app(AdInvoiceDocumentService::class)->issueForBookingRequest($booking);

        Storage::disk('local')->put('private-notes/other-customer.html', 'SECRET OTHER CUSTOMER FILE');
        $invoice->forceFill(['document_path' => 'private-notes/other-customer.html'])->save();

        $this->actingAs($owner)
            ->get('/api/customer/invoices/'.$invoice->id.'/download')
            ->assertOk()
            ->assertSee('Профактура / Барање за плаќање', false)
            ->assertDontSee('SECRET OTHER CUSTOMER FILE', false);

        $this->assertSame('ad-invoices/'.$invoice->id.'-'.$invoice->number.'.html', $invoice->fresh()->document_path);
    }

    public function test_invoice_document_paths_do_not_collide_for_similar_numbers(): void
    {
        Storage::fake('local');

        $admin = $this->userWithRole('admin');
        $firstBooking = $this->bookingWithLineItem(reference: 'RAZ-PROFORMA-0001');
        $secondBooking = $this->bookingWithLineItem(reference: 'RAZ-PROFORMA-0002');
        $firstInvoice = app(AdInvoiceDocumentService::class)->issueForBookingRequest($firstBooking);
        $secondInvoice = app(AdInvoiceDocumentService::class)->issueForBookingRequest($secondBooking);
        $firstInvoice->forceFill(['number' => 'INV/A', 'document_path' => null])->save();
        $secondInvoice->forceFill(['number' => 'INV?A', 'document_path' => null])->save();

        $this->actingAs($admin)
            ->get('/api/customer/invoices/'.$firstInvoice->id.'/download')
            ->assertOk()
            ->assertSee('INV/A', false);

        $this->actingAs($admin)
            ->get('/api/customer/invoices/'.$secondInvoice->id.'/download')
            ->assertOk()
            ->assertSee('INV?A', false)
            ->assertDontSee('INV/A', false);

        $this->assertNotSame($firstInvoice->fresh()->document_path, $secondInvoice->fresh()->document_path);
    }

    public function test_issue_for_booking_request_is_idempotent(): void
    {
        Storage::fake('local');

        $booking = $this->bookingWithLineItem();
        $service = app(AdInvoiceDocumentService::class);

        $first = $service->issueForBookingRequest($booking);
        $second = $service->issueForBookingRequest($booking->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->number, $second->number);
        $this->assertDatabaseCount('ad_invoices', 1);
        Storage::disk('local')->assertExists($second->document_path);
    }

    public function test_issue_for_booking_request_is_idempotent_for_same_model_instance(): void
    {
        Storage::fake('local');

        $booking = $this->bookingWithLineItem();
        $service = app(AdInvoiceDocumentService::class);

        $first = $service->issueForBookingRequest($booking);
        $second = $service->issueForBookingRequest($booking);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->number, $second->number);
        $this->assertDatabaseCount('ad_invoices', 1);
        Storage::disk('local')->assertExists($second->document_path);
    }

    private function bookingWithLineItem(?User $user = null, string $reference = 'RAZ-PROFORMA-0001'): AdBookingRequest
    {
        $customer = AdCustomer::factory()->create([
            'user_id' => $user?->id,
            'company_name' => 'Тест Компанија ДООЕЛ',
            'tax_number' => 'MK4030999999999',
            'billing_address' => 'Широк Сокак 1',
            'billing_city' => 'Битола',
            'contact_name' => 'Александар',
            'contact_email' => 'client@example.test',
            'status' => 'active',
        ]);
        $booking = AdBookingRequest::factory()
            ->for($customer, 'customer')
            ->create([
                'reference' => $reference,
                'billing_company_name' => 'Тест Компанија ДООЕЛ',
                'billing_tax_number' => 'MK4030999999999',
                'billing_address' => 'Широк Сокак 1',
                'billing_city' => 'Битола',
                'contact_name' => 'Александар',
                'contact_email' => 'client@example.test',
                'status' => 'approved',
                'subtotal_denars' => 20000,
                'discount_denars' => 2000,
                'tax_rate' => 0,
                'tax_denars' => 0,
                'total_denars' => 18000,
            ]);
        $zone = AdZone::query()->firstOrCreate(
            ['key' => 'home_after_featured'],
            [
                'name' => 'Home after featured',
                'allowed_sizes' => [[728, 90]],
                'page_context' => 'global',
                'is_active' => true,
            ],
        );
        AdBookingLineItem::factory()
            ->for($booking, 'bookingRequest')
            ->for($zone, 'zone')
            ->create([
                'duration_weeks' => 2,
                'start_at' => '2026-07-06 00:00:00',
                'end_at' => '2026-07-20 00:00:00',
                'status' => 'approved',
                'price_denars' => 20000,
            ]);

        return $booking->fresh(['customer', 'lineItems.zone']);
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
}
