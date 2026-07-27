<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdZone;
use App\Models\MediaAsset;
use App\Services\Ads\AdCreativeValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdCreativeValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_creative_must_match_exact_zone_dimensions(): void
    {
        $zone = AdZone::factory()->create([
            'allowed_sizes' => [[728, 90]],
        ]);
        $image = MediaAsset::factory()->create([
            'width' => 300,
            'height' => 250,
        ]);

        try {
            app(AdCreativeValidationService::class)->validateImageDimensions($zone, $image);
            $this->fail('Expected validation exception for incorrect image dimensions.');
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first('image_id');

            $this->assertStringContainsString('728x90', $message);
            $this->assertStringContainsString('300x250', $message);
        }
    }

    public function test_image_creative_accepts_allowed_zone_size(): void
    {
        $zone = AdZone::factory()->create([
            'allowed_sizes' => [[728, 90], [300, 250]],
        ]);
        $image = MediaAsset::factory()->create([
            'width' => 300,
            'height' => 250,
        ]);

        app(AdCreativeValidationService::class)->validateImageDimensions($zone, $image);

        $this->assertTrue(true);
    }

    public function test_malformed_allowed_sizes_are_ignored_without_php_notice_errors(): void
    {
        $zone = AdZone::factory()->create([
            'allowed_sizes' => [
                ['width' => 300],
                ['height' => 250],
                ['bad'],
                [728, 90],
            ],
        ]);
        $image = MediaAsset::factory()->create([
            'width' => 728,
            'height' => 90,
        ]);

        app(AdCreativeValidationService::class)->validateImageDimensions($zone, $image);

        $this->assertSame([[728, 90]], app(AdCreativeValidationService::class)->allowedSizes($zone));
    }

    public function test_html_creative_requires_technical_review(): void
    {
        $this->assertTrue(app(AdCreativeValidationService::class)->requiresTechnicalReview('html'));
    }

    public function test_script_creative_requires_technical_review(): void
    {
        $this->assertTrue(app(AdCreativeValidationService::class)->requiresTechnicalReview('script'));
    }
}
