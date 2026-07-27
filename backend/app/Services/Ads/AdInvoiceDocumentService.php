<?php

namespace App\Services\Ads;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdInvoice;
use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdInvoiceDocumentService
{
    private const ISSUE_ATTEMPTS = 3;

    public function __construct(
        private readonly AdBillingProfileService $billingProfile,
    ) {}

    public function issueForBookingRequest(AdBookingRequest $bookingRequest): AdInvoice
    {
        for ($attempt = 1; $attempt <= self::ISSUE_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($bookingRequest): AdInvoice {
                    $booking = AdBookingRequest::query()
                        ->whereKey($bookingRequest->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $booking->load(['customer', 'lineItems.zone']);

                    $invoice = AdInvoice::query()
                        ->where('ad_booking_request_id', $booking->id)
                        ->lockForUpdate()
                        ->first() ?: new AdInvoice([
                            'ad_booking_request_id' => $booking->id,
                            'number' => $this->nextInvoiceNumber(),
                        ]);

                    $invoice->forceFill([
                        'type' => 'proforma',
                        'status' => $invoice->status ?: 'issued',
                        'issued_at' => $invoice->issued_at ?: now(),
                        'due_at' => $invoice->due_at ?: now()->addDays(7),
                        'subtotal_denars' => (int) $booking->subtotal_denars,
                        'discount_denars' => (int) $booking->discount_denars,
                        'tax_rate' => $booking->tax_rate,
                        'tax_denars' => (int) $booking->tax_denars,
                        'total_denars' => (int) $booking->total_denars,
                    ])->save();

                    $path = $this->documentPath($invoice);
                    if ($invoice->document_path !== $path) {
                        $invoice->forceFill(['document_path' => $path])->save();
                    }

                    Storage::disk('local')->put($invoice->document_path, $this->renderHtml($invoice->fresh([
                        'bookingRequest.customer',
                        'bookingRequest.lineItems.zone',
                    ])));

                    return $invoice->fresh(['bookingRequest.customer', 'bookingRequest.lineItems.zone']);
                });
            } catch (QueryException $exception) {
                if ($attempt === self::ISSUE_ATTEMPTS || ! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unable to issue ad invoice document.');
    }

    public function renderHtml(AdInvoice $invoice): string
    {
        $invoice->loadMissing(['bookingRequest.customer', 'bookingRequest.lineItems.zone']);
        $booking = $invoice->bookingRequest;
        $customer = $booking->customer;
        $issuer = $this->billingProfile->profile();
        $lineItems = $booking->lineItems
            ->map(fn (AdBookingLineItem $lineItem): string => sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s - %s</td><td class="amount">%s</td></tr>',
                e($lineItem->zone?->key ?? 'ad_zone_'.$lineItem->ad_zone_id),
                e($lineItem->creative_type),
                e($lineItem->start_at?->toDateString() ?? ''),
                e($lineItem->end_at?->toDateString() ?? ''),
                e($this->formatDenars((int) $lineItem->price_denars)),
            ))
            ->implode("\n");

        return '<!doctype html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <title>'.e($invoice->number).' - Профактура</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111827; line-height: 1.5; }
        .wrap { max-width: 760px; margin: 32px auto; }
        h1 { font-size: 26px; margin-bottom: 8px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; }
        h2 { font-size: 18px; margin: 24px 0 8px; }
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
        <h1>Профактура / Барање за плаќање</h1>
        <p><strong>Број:</strong> '.e($invoice->number).'</p>
        <p><strong>Референца за резервација:</strong> '.e($booking->reference).'</p>
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

        <h2>Податоци за компанија</h2>
        <p>
            '.e($booking->billing_company_name ?: $customer?->company_name).'<br>
            Даночен број: '.e($booking->billing_tax_number ?: $customer?->tax_number).'<br>
            Регистарски број: '.e($booking->billing_registration_number ?: $customer?->registration_number ?: '-').'<br>
            '.e($booking->billing_address ?: $customer?->billing_address).'<br>
            '.e($booking->billing_city ?: $customer?->billing_city).', '.e($booking->billing_country ?: $customer?->billing_country).'<br>
            Контакт: '.e($booking->contact_name).' &lt;'.e($booking->contact_email).'&gt;
        </p>

        <table>
            <thead>
                <tr>
                    <th>Зона</th>
                    <th>Креатив</th>
                    <th>Период</th>
                    <th>Износ</th>
                </tr>
            </thead>
            <tbody>
                '.$lineItems.'
            </tbody>
        </table>

        <section class="totals">
            <div><span>Меѓузбир:</span><span>'.e($this->formatDenars((int) $invoice->subtotal_denars)).'</span></div>
            <div><span>Попуст:</span><span>'.e($this->formatDenars((int) $invoice->discount_denars)).'</span></div>
            <div><span>ДДВ/данок: '.e($this->formatTaxRate((float) $invoice->tax_rate)).'</span><span>'.e($this->formatDenars((int) $invoice->tax_denars)).'</span></div>
            <div class="total"><span>Вкупно: '.e($this->formatDenars((int) $invoice->total_denars)).'</span></div>
        </section>
    </main>
</body>
</html>';
    }

    public function downloadResponse(AdInvoice $invoice): Response
    {
        $invoice->loadMissing(['bookingRequest.customer', 'bookingRequest.lineItems.zone']);
        $path = $this->documentPath($invoice);

        if ($invoice->document_path !== $path) {
            $invoice->forceFill(['document_path' => $path])->save();
        }

        if (! Storage::disk('local')->exists($invoice->document_path)) {
            Storage::disk('local')->put($invoice->document_path, $this->renderHtml($invoice));
        }

        return response(Storage::disk('local')->get($invoice->document_path), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.html"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function nextInvoiceNumber(): string
    {
        $year = now()->format('Y');
        $latest = AdInvoice::query()
            ->where('number', 'like', "PROF-{$year}-%")
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');
        $next = $this->nextSequenceFromInvoiceNumber($latest, $year);

        do {
            $number = sprintf('PROF-%s-%06d', $year, $next++);
        } while (AdInvoice::query()->where('number', $number)->exists());

        return $number;
    }

    private function documentPath(AdInvoice $invoice): string
    {
        return 'ad-invoices/'.$invoice->id.'-'.$this->safeDocumentNumber($invoice->number).'.html';
    }

    private function safeDocumentNumber(string $number): string
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $number), '-');

        return $safe !== '' ? $safe : 'invoice';
    }

    private function nextSequenceFromInvoiceNumber(?string $number, string $year): int
    {
        if ($number && preg_match('/^PROF-'.preg_quote($year, '/').'-(\d+)$/', $number, $matches)) {
            return ((int) $matches[1]) + 1;
        }

        return 1;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = $exception->getMessage();

        return in_array($sqlState, ['23000', '23505'], true)
            || $driverCode === '1062'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
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
