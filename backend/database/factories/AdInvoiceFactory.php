<?php

namespace Database\Factories;

use App\Models\AdBookingRequest;
use App\Models\AdInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdInvoice>
 */
class AdInvoiceFactory extends Factory
{
    protected $model = AdInvoice::class;

    public function definition(): array
    {
        return [
            'ad_booking_request_id' => AdBookingRequest::factory(),
            'number' => 'PRO-'.now()->year.'-'.fake()->unique()->numberBetween(1000, 9999),
            'type' => 'proforma',
            'status' => 'issued',
            'document_path' => null,
            'issued_at' => now(),
            'due_at' => now()->addWeek(),
            'paid_at' => null,
            'subtotal_denars' => 15000,
            'discount_denars' => 0,
            'tax_rate' => 0,
            'tax_denars' => 0,
            'total_denars' => 15000,
        ];
    }
}
