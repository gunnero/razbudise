<?php

namespace Database\Factories;

use App\Models\AdCampaign;
use App\Models\Advertiser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdCampaign>
 */
class AdCampaignFactory extends Factory
{
    protected $model = AdCampaign::class;

    public function definition(): array
    {
        return [
            'advertiser_id' => Advertiser::factory(),
            'name' => fake()->company().' Campaign',
            'status' => 'draft',
            'start_at' => now()->addDay(),
            'end_at' => now()->addMonth(),
            'priority' => 0,
            'device_targeting' => [],
            'category_targeting' => [],
            'article_targeting' => [],
        ];
    }
}
