<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_backoffice_redirects_keep_https_when_request_is_forwarded_by_proxy(): void
    {
        $response = $this
            ->withServerVariables([
                'HTTP_HOST' => 'preview.example.test',
                'HTTP_X_FORWARDED_HOST' => 'preview.example.test',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_PORT' => '443',
                'REMOTE_ADDR' => '127.0.0.1',
            ])
            ->get('/backoffice');

        $response->assertRedirect('https://preview.example.test/backoffice/login');
    }

    public function test_old_admin_path_is_not_registered_as_the_cms_entry_point(): void
    {
        $this->get('/admin')->assertNotFound();
    }
}
