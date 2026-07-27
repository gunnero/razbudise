<?php

namespace Database\Factories;

use App\Models\AdCustomer;
use App\Models\Advertiser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdCustomer>
 */
class AdCustomerFactory extends Factory
{
    protected $model = AdCustomer::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'advertiser_id' => Advertiser::factory(),
            'company_name' => fake()->randomElement([
                'Битолска Маркетинг Компанија ДООЕЛ',
                'Пелагонија Медиа ДОО',
                'Градски Бренд ДООЕЛ',
            ]),
            'tax_number' => 'MK'.fake()->unique()->numerify('##########'),
            'registration_number' => fake()->numerify('#######'),
            'billing_address' => fake()->randomElement(['Широк Сокак 1', 'Партизанска 12', 'Илинденска 24']),
            'billing_city' => fake()->randomElement(['Битола', 'Скопје', 'Прилеп']),
            'billing_country' => 'MK',
            'contact_name' => fake()->randomElement(['Александар', 'Мартина', 'Елена', 'Никола']),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '+389'.fake()->numerify('7#######'),
            'status' => 'pending',
            'last_active_at' => null,
            'dashboard_deactivated_at' => null,
            'former_customer_at' => null,
            'admin_notes' => null,
        ];
    }
}
