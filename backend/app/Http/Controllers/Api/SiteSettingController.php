<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;

class SiteSettingController extends Controller
{
    public function public(): JsonResponse
    {
        $settings = SiteSetting::query()
            ->where('is_public', true)
            ->orderBy('key')
            ->get(['key', 'value'])
            ->mapWithKeys(fn (SiteSetting $setting): array => [
                $setting->key => $setting->value,
            ]);

        return response()->json([
            'data' => $settings,
        ]);
    }
}
