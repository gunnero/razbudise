<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdCampaignEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_at_scope_returns_active_campaigns_inside_date_window(): void
    {
        $now = now();

        $eligible = AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => $now->copy()->subDay(),
            'end_at' => $now->copy()->addDay(),
        ]);

        AdCampaign::factory()->create([
            'status' => 'paused',
            'start_at' => $now->copy()->subDay(),
            'end_at' => $now->copy()->addDay(),
        ]);

        AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => $now->copy()->addHour(),
            'end_at' => $now->copy()->addDay(),
        ]);

        AdCampaign::factory()->create([
            'status' => 'active',
            'start_at' => $now->copy()->subDays(3),
            'end_at' => $now->copy()->subDay(),
        ]);

        $this->assertSame([$eligible->id], AdCampaign::eligibleAt($now)->pluck('id')->all());
    }
}
