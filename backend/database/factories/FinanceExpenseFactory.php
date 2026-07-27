<?php

namespace Database\Factories;

use App\Models\FinanceExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceExpense>
 */
class FinanceExpenseFactory extends Factory
{
    protected $model = FinanceExpense::class;

    public function definition(): array
    {
        return [
            'vendor_name' => fake()->company(),
            'description' => fake()->sentence(),
            'expense_date' => now()->toDateString(),
            'status' => 'unpaid',
            'subtotal_denars' => 5000,
            'tax_rate' => 0,
            'tax_denars' => 0,
            'total_denars' => 5000,
        ];
    }
}
