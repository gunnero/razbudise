<?php

namespace Tests\Feature;

use Tests\TestCase;

class FilamentAdminBrandingTest extends TestCase
{
    public function test_admin_panel_uses_razbudise_branding_and_theme_assets(): void
    {
        $provider = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

        $this->assertStringContainsString("->brandName('RazbudiSe')", $provider);
        $this->assertStringContainsString("->brandLogo(asset('brand/razbudise-logo.png'))", $provider);
        $this->assertStringContainsString("->darkModeBrandLogo(asset('brand/razbudise-logo.png'))", $provider);
        $this->assertStringContainsString("->favicon(asset('favicon.ico'))", $provider);
        $this->assertStringContainsString("->viteTheme('resources/css/filament/admin/theme.css')", $provider);
        $this->assertStringContainsString("Color::hex('#f05a3a')", $provider);
        $this->assertFileExists(public_path('brand/razbudise-logo.png'));

        $this->assertStringContainsString("@import '../../../../vendor/filament/filament/resources/css/theme.css';", $theme);
        $this->assertStringContainsString('--razbudise-paper: #fffdf8;', $theme);
        $this->assertStringContainsString('--razbudise-accent: #f05a3a;', $theme);
        $this->assertStringContainsString('.fi-sidebar', $theme);
        $this->assertStringContainsString('.fi-logo img', $theme);
        $this->assertStringContainsString('.dark .fi-body', $theme);
    }
}
