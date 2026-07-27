<?php

namespace Tests\Feature;

use App\Models\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RedirectResolutionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_resolution_returns_active_redirect_for_old_absolute_url(): void
    {
        Redirect::query()->create([
            'source_url' => 'https://old.example.test/stara-objava/',
            'target_url' => '/nova-objava',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $this->getJson('/api/redirects/resolve?url='.rawurlencode('https://old.example.test/stara-objava/'))
            ->assertOk()
            ->assertJsonPath('data.target_url', '/nova-objava')
            ->assertJsonPath('data.status_code', 301);
    }

    public function test_redirect_resolution_matches_path_when_request_only_has_path(): void
    {
        Redirect::query()->create([
            'source_url' => 'https://old.example.test/stara-objava/',
            'target_url' => '/nova-objava',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $this->getJson('/api/redirects/resolve?url='.rawurlencode('/stara-objava/'))
            ->assertOk()
            ->assertJsonPath('data.target_url', '/nova-objava');
    }

    public function test_redirect_resolution_ignores_inactive_or_missing_redirects(): void
    {
        Redirect::query()->create([
            'source_url' => 'https://old.example.test/inactive/',
            'target_url' => '/inactive-target',
            'status_code' => 301,
            'is_active' => false,
        ]);

        $this->getJson('/api/redirects/resolve?url='.rawurlencode('/inactive/'))->assertNotFound();
        $this->getJson('/api/redirects/resolve?url='.rawurlencode('/missing/'))->assertNotFound();
    }

    public function test_redirect_resolution_ignores_self_redirects_to_the_same_path(): void
    {
        Redirect::query()->create([
            'source_url' => 'https://old.example.test/same-path/',
            'target_url' => '/same-path',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $this->getJson('/api/redirects/resolve?url='.rawurlencode('/same-path'))->assertNotFound();
    }

    public function test_redirect_resolution_uses_indexed_path_hash_and_matches_encoded_cyrillic_paths(): void
    {
        $redirect = Redirect::query()->create([
            'source_url' => 'https://razbudise.mk/kategorija/%D0%BA%D1%83%D0%BB%D1%82%D1%83%D1%80%D0%B0/',
            'target_url' => '/category/kultura',
            'status_code' => 301,
            'is_active' => true,
        ]);
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'redirects')) {
                $queries[] = $query->sql;
            }
        });

        $this->getJson('/api/redirects/resolve?url='.rawurlencode('/kategorija/култура/'))
            ->assertOk()
            ->assertJsonPath('data.target_url', '/category/kultura');

        $lookup = collect($queries)->first(fn (string $sql): bool => str_starts_with(strtolower(trim($sql)), 'select'));

        $this->assertNotNull($redirect->source_path_hash);
        $this->assertNotNull($lookup);
        $this->assertStringContainsString('source_path_hash', $lookup);
        $this->assertStringContainsString('limit', strtolower($lookup));
    }

    public function test_redirect_source_path_hash_is_refreshed_when_source_url_changes(): void
    {
        $redirect = Redirect::query()->create([
            'source_url' => 'https://old.example.test/first-path/',
            'target_url' => '/target',
            'status_code' => 301,
            'is_active' => true,
        ]);
        $firstHash = $redirect->source_path_hash;

        $redirect->update(['source_url' => 'https://old.example.test/second-path/']);

        $this->assertNotNull($firstHash);
        $this->assertNotSame($firstHash, $redirect->fresh()->source_path_hash);
    }
}
