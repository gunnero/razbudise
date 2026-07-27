<?php

namespace Database\Factories;

use App\Models\FinanceInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceInvoice>
 */
class FinanceInvoiceFactory extends Factory
{
    protected $model = FinanceInvoice::class;

    public function definition(): array
    {
        return [
            'number' => 'FIN-'.now()->format('Y').'-'.fake()->unique()->numerify('######'),
            'type' => 'invoice',
            'status' => 'issued',
            'customer_name' => fake()->company(),
            'description' => fake()->sentence(),
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
            'subtotal_denars' => 10000,
            'discount_denars' => 0,
            'tax_rate' => 0,
            'tax_denars' => 0,
            'total_denars' => 10000,
        ];
    }
}
