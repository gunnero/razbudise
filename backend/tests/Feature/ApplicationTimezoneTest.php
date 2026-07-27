<?php

namespace Tests\Feature;

use Filament\Support\Facades\FilamentTimezone;
use Tests\TestCase;

class ApplicationTimezoneTest extends TestCase
{
    public function test_storage_stays_utc_while_filament_uses_the_editorial_timezone(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame('Europe/Skopje', FilamentTimezone::get());
    }
}
