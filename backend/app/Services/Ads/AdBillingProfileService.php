<?php

namespace App\Services\Ads;

use App\Models\SiteSetting;

class AdBillingProfileService
{
    public const SETTING_KEY = 'organization_billing_profile';

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $value = SiteSetting::query()
            ->where('key', self::SETTING_KEY)
            ->value('value');

        $configured = is_array($value)
            ? collect($value)->filter(fn (mixed $item): bool => $item !== null && $item !== '')->all()
            : [];

        return [
            ...$this->defaults(),
            ...$configured,
        ];
    }

    public function taxRate(): float
    {
        return (float) ($this->profile()['tax_rate'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'legal_name' => 'Здружение за културно информирање РАЗБУДИ СЕ ДЕНЕС Битола',
            'short_name' => 'Здружение РАЗБУДИ СЕ ДЕНЕС Битола',
            'registration_number' => '7760388',
            'tax_number' => '4002024573440',
            'address' => 'БОШКО НАЈДОВСКИ бр.1Б, БИТОЛА, БИТОЛА',
            'bank_name' => 'НЛБ Банка АД Скопје',
            'bank_account' => '210077603880171',
            'activity_code' => '94.99',
            'tax_rate' => 0,
            'payment_note' => 'Плаќањето се врши со наведување на бројот на профактурата како повик/референца.',
        ];
    }
}
