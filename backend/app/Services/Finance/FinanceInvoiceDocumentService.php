<?php

namespace App\Services\Finance;

use App\Models\FinanceInvoice;
use App\Services\Ads\AdBillingProfileService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class FinanceInvoiceDocumentService
{
    public function __construct(
        private readonly AdBillingProfileService $billingProfile,
    ) {}

    public function renderHtml(FinanceInvoice $invoice): string
    {
        $issuer = $this->billingProfile->profile();

        return '<!doctype html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <title>'.e($invoice->number).' - Финансиска фактура</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111827; line-height: 1.5; }
        .wrap { max-width: 760px; margin: 32px auto; }
        h1 { font-size: 26px; margin-bottom: 8px; }
        h2 { font-size: 18px; margin: 24px 0 8px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; }
        .parties { display: grid; gap: 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 20px; }
        .party { border: 1px solid #d1d5db; padding: 14px; }
        .party h2 { margin-top: 0; }
        .amount { text-align: right; white-space: nowrap; }
        .totals { margin-top: 20px; margin-left: auto; max-width: 340px; }
        .totals div { display: flex; justify-content: space-between; padding: 4px 0; }
        .total { font-weight: 700; font-size: 18px; }
    </style>
</head>
<body>
    <main class="wrap">
        <h1>'.e($this->documentTitle($invoice)).'</h1>
        <p><strong>Број:</strong> '.e($invoice->number).'</p>
        <p><strong>Статус:</strong> '.e($invoice->status).'</p>
        <p><strong>Издадено:</strong> '.e($invoice->issued_at?->toDateString() ?? '').'</p>
        <p><strong>Рок за плаќање:</strong> '.e($invoice->due_at?->toDateString() ?? '').'</p>

        <section class="parties">
            <article class="party">
                <h2>Издавач</h2>
                <p>
                    '.e($issuer['legal_name']).'<br>
                    Кратко име: '.e($issuer['short_name']).'<br>
                    ЕМБС: '.e($issuer['registration_number']).'<br>
                    ЕДБ: '.e($issuer['tax_number']).'<br>
                    '.e($issuer['address']).'<br>
                    Дејност: '.e($issuer['activity_code']).'
                </p>
            </article>
            <article class="party">
                <h2>Инструкции за плаќање</h2>
                <p>
                    Банка: '.e($issuer['bank_name']).'<br>
                    Жиро сметка: '.e($issuer['bank_account']).'<br>
                    '.e($issuer['payment_note']).'
                </p>
            </article>
        </section>

        <h2>Податоци за клиент</h2>
        <p>
            '.e($invoice->customer_name).'<br>
            Даночен број: '.e($invoice->customer_tax_number ?: '-').'<br>
            Регистарски број: '.e($invoice->customer_registration_number ?: '-').'<br>
            '.e($invoice->customer_address ?: '-').'<br>
            Е-пошта: '.e($invoice->customer_email ?: '-').'
        </p>

        <table>
            <thead>
                <tr>
                    <th>Опис</th>
                    <th>Износ</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>'.e($invoice->description).'</td>
                    <td class="amount">'.e($this->formatDenars((int) $invoice->subtotal_denars)).'</td>
                </tr>
            </tbody>
        </table>

        <section class="totals">
            <div><span>Меѓузбир:</span><span>'.e($this->formatDenars((int) $invoice->subtotal_denars)).'</span></div>
            <div><span>Попуст:</span><span>'.e($this->formatDenars((int) $invoice->discount_denars)).'</span></div>
            <div><span>ДДВ/данок: '.e($this->formatTaxRate((float) $invoice->tax_rate)).'</span><span>'.e($this->formatDenars((int) $invoice->tax_denars)).'</span></div>
            <div class="total"><span>Вкупно:</span><span>'.e($this->formatDenars((int) $invoice->total_denars)).'</span></div>
        </section>
    </main>
</body>
</html>';
    }

    public function storeDocument(FinanceInvoice $invoice): FinanceInvoice
    {
        $path = $this->documentPath($invoice);

        if ($invoice->document_path !== $path) {
            $invoice->forceFill(['document_path' => $path])->save();
        }

        Storage::disk('local')->put($invoice->document_path, $this->renderHtml($invoice));

        return $invoice->fresh();
    }

    public function downloadResponse(FinanceInvoice $invoice): Response
    {
        $path = $this->documentPath($invoice);

        if ($invoice->document_path !== $path) {
            $invoice->forceFill(['document_path' => $path])->save();
        }

        if (! Storage::disk('local')->exists($invoice->document_path)) {
            Storage::disk('local')->put($invoice->document_path, $this->renderHtml($invoice));
        }

        return response(Storage::disk('local')->get($invoice->document_path), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->safeDocumentNumber($invoice->number).'.html"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function documentPath(FinanceInvoice $invoice): string
    {
        return 'finance-invoices/'.$invoice->id.'-'.$this->safeDocumentNumber($invoice->number).'.html';
    }

    private function safeDocumentNumber(string $number): string
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $number), '-');

        return $safe !== '' ? $safe : 'invoice';
    }

    private function documentTitle(FinanceInvoice $invoice): string
    {
        return match ($invoice->type) {
            'credit_note' => 'Книжно одобрение',
            'proforma' => 'Профактура',
            default => 'Фактура',
        };
    }

    private function formatDenars(int $denars): string
    {
        return number_format($denars, 0, ',', '.').' ден.';
    }

    private function formatTaxRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 2, ',', '.'), '0'), ',').'%';
    }
}
