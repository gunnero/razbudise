<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceInvoice;
use App\Services\Finance\FinanceInvoiceDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceInvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_invoice_document_contains_razbudise_billing_details(): void
    {
        Storage::fake('local');

        $invoice = FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-000001',
            'type' => 'invoice',
            'status' => 'issued',
            'customer_name' => 'Тест Клиент',
            'description' => 'Услуга за промоција',
            'total_denars' => 12000,
        ]);

        $service = app(FinanceInvoiceDocumentService::class);
        $html = $service->renderHtml($invoice);

        $this->assertStringContainsString('Здружение за културно информирање РАЗБУДИ СЕ ДЕНЕС Битола', $html);
        $this->assertStringContainsString('ЕМБС: 7760388', $html);
        $this->assertStringContainsString('ЕДБ: 4002024573440', $html);
        $this->assertStringContainsString('Жиро сметка: 210077603880171', $html);
        $this->assertStringContainsString('Тест Клиент', $html);
        $this->assertStringContainsString('12.000 ден.', $html);
    }

    public function test_document_is_written_to_private_storage(): void
    {
        Storage::fake('local');

        $invoice = FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-000002',
            'document_path' => null,
        ]);

        $stored = app(FinanceInvoiceDocumentService::class)->storeDocument($invoice);

        $this->assertSame('finance-invoices/'.$invoice->id.'-FIN-2026-000002.html', $stored->document_path);
        Storage::disk('local')->assertExists($stored->document_path);
    }

    public function test_download_response_serves_private_html_with_safe_headers(): void
    {
        Storage::fake('local');

        $invoice = FinanceInvoice::factory()->create([
            'number' => 'FIN-2026-000003',
            'document_path' => null,
        ]);

        $response = app(FinanceInvoiceDocumentService::class)->downloadResponse($invoice);

        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString($invoice->number, $response->getContent());
        Storage::disk('local')->assertExists($invoice->fresh()->document_path);
    }
}
