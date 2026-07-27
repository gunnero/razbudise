<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSiteSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_settings_endpoint_returns_public_settings_without_private_values(): void
    {
        SiteSetting::query()->create([
            'key' => 'site_name',
            'group' => 'general',
            'value' => ['Разбуди се'],
            'is_public' => true,
        ]);

        SiteSetting::query()->create([
            'key' => 'default_meta_description',
            'group' => 'seo',
            'value' => ['Јавен опис'],
            'is_public' => true,
        ]);

        SiteSetting::query()->create([
            'key' => 'internal_tracking_token',
            'group' => 'internal',
            'value' => ['do-not-leak'],
            'is_public' => false,
        ]);

        $this->getJson('/api/site-settings/public')
            ->assertOk()
            ->assertJsonPath('data.site_name', ['Разбуди се'])
            ->assertJsonPath('data.default_meta_description', ['Јавен опис'])
            ->assertJsonMissingPath('data.internal_tracking_token');
    }
}
