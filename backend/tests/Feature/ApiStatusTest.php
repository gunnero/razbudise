<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiStatusTest extends TestCase
{
    public function test_status_endpoint_returns_api_metadata(): void
    {
        $response = $this->getJson('/api/v1/status');

        $response->assertOk()
            ->assertJsonPath('data.name', 'RazbudiSe API')
            ->assertJsonPath('data.version', 'v1')
            ->assertJsonPath('data.mobile_ready', true)
            ->assertJsonPath('data.ai_author_generation_enabled', false);
    }
}
