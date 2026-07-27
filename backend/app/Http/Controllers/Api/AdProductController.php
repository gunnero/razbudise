<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Ads\AdProductResource;
use App\Models\AdZone;
use App\Services\Ads\AdAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $zones = AdZone::query()
            ->with(['prices' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('duration_weeks')
                ->orderBy('creative_type')])
            ->where('is_active', true)
            ->whereHas('prices', fn ($query) => $query->where('is_active', true))
            ->orderBy('key')
            ->get();

        return AdProductResource::collection($zones);
    }

    public function availability(Request $request, AdZone $zone, AdAvailabilityService $availability): JsonResponse
    {
        abort_unless($zone->is_active, 404);

        $data = $request->validate([
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'duration_weeks' => ['required', 'integer', 'in:1,2,4'],
        ]);

        $start = CarbonImmutable::parse($data['start_date'])->startOfDay();
        $end = $start->addWeeks((int) $data['duration_weeks']);
        $available = $availability->isAvailable($zone, $start, $end);
        $nextAvailableStart = $available
            ? null
            : $availability->nextAvailableStart($zone, $start, (int) $data['duration_weeks'])->toDateString();

        return response()->json([
            'data' => [
                'zone_key' => $zone->key,
                'available' => $available,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'duration_weeks' => (int) $data['duration_weeks'],
                'next_available_start' => $nextAvailableStart,
            ],
        ]);
    }
}
