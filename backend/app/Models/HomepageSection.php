<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageSection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            'manual' => 'Manual article picks',
            'latest' => 'Latest published articles',
            'category' => 'Category feed',
            'promo_cards' => 'Promoted image cards',
        ];
    }
}
