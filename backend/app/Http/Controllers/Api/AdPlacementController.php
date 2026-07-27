<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdPlacementResource;
use App\Models\AdCreative;
use App\Models\AdZone;
use App\Models\Category;
use App\Services\Ads\AdDeliveryTokenService;
use App\Services\Ads\AdPlacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdPlacementController extends Controller
{
    public function show(
        Request $request,
        AdPlacementService $placements,
        AdDeliveryTokenService $deliveryTokens,
        string $zoneKey,
    ): AdPlacementResource|JsonResponse {
        $zone = AdZone::query()
            ->where('key', $zoneKey)
            ->where('is_active', true)
            ->first();

        if (! $zone) {
            return response()->json(['data' => null]);
        }

        $data = $request->validate([
            'device' => ['nullable', 'string', 'in:desktop,tablet,mobile'],
            'category' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'article_id' => ['nullable', 'integer', 'exists:articles,id'],
        ]);

        $category = $data['category'] ?? null;
        $categoryId = $data['category_id'] ?? null;

        if (! $categoryId && is_string($category) && ctype_digit($category)) {
            $categoryId = (int) $category;
        }

        if (! $categoryId && is_string($category) && $category !== '') {
            $categoryId = Category::query()->where('slug', $category)->value('id');
        }

        $creative = $placements->bestCreativeForZone($zone, [
            'device' => $data['device'] ?? null,
            'category' => $category,
            'category_id' => $categoryId,
            'article_id' => $data['article_id'] ?? null,
        ]);

        if (! $creative) {
            $creative = AdCreative::query()
                ->with(['image', 'zone'])
                ->where('is_active', true)
                ->where('is_fallback', true)
                ->where(function ($query) use ($zone): void {
                    $query
                        ->where('zone_id', $zone->id)
                        ->orWhereNull('zone_id');
                })
                ->orderByRaw('case when zone_id = ? then 0 else 1 end', [$zone->id])
                ->oldest('id')
                ->first();

            if ($creative && ! $creative->zone) {
                $creative->setRelation('zone', $zone);
            }
        }

        if (! $creative) {
            return response()->json(['data' => null]);
        }

        $context = [
            'device' => $data['device'] ?? null,
            'category' => $category,
            'category_id' => $categoryId,
            'article_id' => $data['article_id'] ?? null,
        ];

        return AdPlacementResource::make($creative)
            ->withDelivery($deliveryTokens->issue($creative, $zone, $context));
    }
}
