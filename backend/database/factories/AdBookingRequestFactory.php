<?php

namespace Database\Factories;

use App\Models\AdBookingRequest;
use App\Models\AdCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdBookingRequest>
 */
class AdBookingRequestFactory extends Factory
{
    protected $model = AdBookingRequest::class;

    public function definition(): array
    {
        return [
            'ad_customer_id' => AdCustomer::factory(),
            'discount_code_id' => null,
            'reference' => 'RAZ-'.now()->year.'-'.fake()->unique()->numberBetween(1000, 9999),
            'billing_company_name' => 'Битолска Маркетинг Компанија ДООЕЛ',
            'billing_tax_number' => 'MK4030'.fake()->unique()->numerify('#######'),
            'billing_registration_number' => fake()->numerify('#######'),
            'billing_address' => 'Широк Сокак 1',
            'billing_city' => 'Битола',
            'billing_country' => 'MK',
            'contact_name' => 'Александар',
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '+38970111222',
            'status' => 'pending_review',
            'subtotal_denars' => 15000,
            'discount_denars' => 0,
            'tax_rate' => 0,
            'tax_denars' => 0,
            'total_denars' => 15000,
            'payment_status' => 'unpaid',
            'payment_method' => null,
            'payment_reference' => null,
            'paid_at' => null,
            'external_payment_provider' => null,
            'external_payment_id' => null,
            'payment_metadata' => null,
            'hold_expires_at' => now()->addDays(2),
            'customer_notes' => null,
            'admin_notes' => null,
            'rejection_reason' => null,
        ];
    }
}
