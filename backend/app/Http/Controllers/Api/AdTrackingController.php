<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdClick;
use App\Models\AdCreative;
use App\Models\AdImpression;
use App\Models\AdZone;
use App\Services\Ads\AdDeliveryTokenService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdTrackingController extends Controller
{
    public function impression(Request $request, AdDeliveryTokenService $deliveryTokens): JsonResponse
    {
        $data = $request->validate([
            'delivery_token' => ['required', 'string', 'max:4096'],
            'creative_id' => ['required', 'integer', 'exists:ad_creatives,id'],
            'zone_key' => ['nullable', 'string', 'exists:ad_zones,key'],
            'article_id' => ['nullable', 'integer', 'exists:articles,id'],
            'category' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'device' => ['nullable', 'string', 'in:desktop,tablet,mobile'],
        ]);

        $creative = AdCreative::query()->with('campaign')->findOrFail($data['creative_id']);
        $delivery = $deliveryTokens->validate(
            $data['delivery_token'],
            $creative,
            $this->expectedDeliveryContext($request, $data),
        );
        $zone = $this->deliveryZone($delivery);
        $fingerprint = $this->eventFingerprint('impression', $request, $delivery);
        $existing = AdImpression::query()
            ->where('delivery_id', $delivery['delivery_id'])
            ->orWhere('event_fingerprint', $fingerprint)
            ->first();

        if ($existing) {
            return $this->trackingResponse(false);
        }

        $this->abortUnlessTrackable($creative);

        try {
            AdImpression::query()->create([
                'delivery_id' => $delivery['delivery_id'],
                'event_fingerprint' => $fingerprint,
                'campaign_id' => $creative->campaign_id,
                'creative_id' => $creative->id,
                'zone_id' => $zone->id,
                'article_id' => $delivery['context']['article_id'],
                'device' => $delivery['context']['device'],
                'ip_hash' => $this->hashIp($request),
                'user_agent' => $request->userAgent(),
                'occurred_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = AdImpression::query()
                ->where('delivery_id', $delivery['delivery_id'])
                ->orWhere('event_fingerprint', $fingerprint)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            return $this->trackingResponse(false);
        }

        return $this->trackingResponse(true);
    }

    public function click(
        Request $request,
        AdCreative $creative,
        AdDeliveryTokenService $deliveryTokens,
    ): JsonResponse {
        [$delivery, $zone] = $this->validatedClickDelivery($request, $creative, $deliveryTokens);
        [$click, $recorded] = $this->recordClick($request, $creative, $delivery, $zone);

        return response()->json([
            'data' => [
                'recorded' => $recorded,
                'duplicate' => ! $recorded,
                'target_url' => $click->target_url,
            ],
        ], $recorded ? 201 : 200);
    }

    public function redirect(
        Request $request,
        AdCreative $creative,
        AdDeliveryTokenService $deliveryTokens,
    ): RedirectResponse|JsonResponse {
        [$delivery, $zone] = $this->validatedClickDelivery(
            $request,
            $creative,
            $deliveryTokens,
            allowExpired: true,
        );

        if ($deliveryTokens->isExpired($delivery)) {
            return $this->redirectToAdvertiser($creative->click_url);
        }

        [$click] = $this->recordClick($request, $creative, $delivery, $zone);

        return $this->redirectToAdvertiser($click->target_url);
    }

    /**
     * @param  array{delivery_id: string, creative_id: int, zone_id: int, context: array{article_id: int|null, device: string|null}, issued_at: int}  $delivery
     * @return array{0: AdClick, 1: bool}
     */
    private function recordClick(
        Request $request,
        AdCreative $creative,
        array $delivery,
        AdZone $zone,
    ): array {
        $fingerprint = $this->eventFingerprint('click', $request, $delivery);
        $existing = AdClick::query()
            ->where('delivery_id', $delivery['delivery_id'])
            ->orWhere('event_fingerprint', $fingerprint)
            ->first();

        if ($existing) {
            return [$existing, false];
        }

        $this->abortUnlessTrackable($creative);

        try {
            $click = AdClick::query()->create([
                'delivery_id' => $delivery['delivery_id'],
                'event_fingerprint' => $fingerprint,
                'campaign_id' => $creative->campaign_id,
                'creative_id' => $creative->id,
                'zone_id' => $zone->id,
                'article_id' => $delivery['context']['article_id'],
                'device' => $delivery['context']['device'],
                'ip_hash' => $this->hashIp($request),
                'user_agent' => $request->userAgent(),
                'target_url' => $creative->click_url,
                'occurred_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = AdClick::query()
                ->where('delivery_id', $delivery['delivery_id'])
                ->orWhere('event_fingerprint', $fingerprint)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            return [$existing, false];
        }

        return [$click, true];
    }

    /**
     * @return array{0: array<string, mixed>, 1: AdZone}
     */
    private function validatedClickDelivery(
        Request $request,
        AdCreative $creative,
        AdDeliveryTokenService $deliveryTokens,
        bool $allowExpired = false,
    ): array {
        $data = $request->validate([
            'delivery_token' => ['required', 'string', 'max:4096'],
            'zone_key' => ['nullable', 'string', 'exists:ad_zones,key'],
            'article_id' => ['nullable', 'integer', 'exists:articles,id'],
            'category' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'device' => ['nullable', 'string', 'in:desktop,tablet,mobile'],
        ]);

        $creative->loadMissing('campaign');
        $expected = $this->expectedDeliveryContext($request, $data);
        $delivery = $allowExpired
            ? $deliveryTokens->validateForRedirect($data['delivery_token'], $creative, $expected)
            : $deliveryTokens->validate($data['delivery_token'], $creative, $expected);

        return [$delivery, $this->deliveryZone($delivery)];
    }

    private function abortUnlessTrackable(AdCreative $creative): void
    {
        if (! $creative->is_active || ! $creative->campaign) {
            abort(404);
        }

        if ($creative->is_fallback) {
            return;
        }

        $campaignIsEligible = AdCreative::query()
            ->whereKey($creative->id)
            ->whereHas('campaign', fn ($query) => $query->eligibleForDeliveryAt(now()))
            ->exists();

        if (! $campaignIsEligible) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function expectedDeliveryContext(Request $request, array $data): array
    {
        $expected = [];

        foreach (['zone_key', 'device', 'category', 'category_id', 'article_id'] as $key) {
            if ($request->has($key)) {
                $expected[$key] = $data[$key] ?? null;
            }
        }

        return $expected;
    }

    /**
     * @param  array{zone_id: int, zone_key: string}  $delivery
     */
    private function deliveryZone(array $delivery): AdZone
    {
        $zone = AdZone::query()->find($delivery['zone_id']);

        if (! $zone || ! hash_equals($delivery['zone_key'], $zone->key)) {
            throw new AuthorizationException('Invalid or expired ad delivery token.');
        }

        return $zone;
    }

    /**
     * @param  array{creative_id: int, zone_id: int, context: array{article_id: int|null, device: string|null}, issued_at: int}  $delivery
     */
    private function eventFingerprint(string $event, Request $request, array $delivery): string
    {
        $window = max(1, (int) config('ads.tracking_dedupe_window_seconds', 30));
        $bucket = intdiv($delivery['issued_at'], $window);

        return hash('sha256', json_encode([
            'event' => $event,
            'creative_id' => $delivery['creative_id'],
            'zone_id' => $delivery['zone_id'],
            'article_id' => $delivery['context']['article_id'],
            'device' => $delivery['context']['device'],
            'ip_hash' => $this->hashIp($request),
            'user_agent' => $request->userAgent(),
            'bucket' => $bucket,
        ]));
    }

    private function trackingResponse(bool $recorded): JsonResponse
    {
        return response()->json([
            'data' => [
                'recorded' => $recorded,
                'duplicate' => ! $recorded,
            ],
        ], $recorded ? 201 : 200);
    }

    private function redirectToAdvertiser(?string $targetUrl): RedirectResponse|JsonResponse
    {
        if (! $targetUrl) {
            return response()->json([
                'message' => 'Ad creative does not have a click URL.',
            ], 404);
        }

        return redirect()
            ->away($targetUrl)
            ->withHeaders(['Referrer-Policy' => 'no-referrer']);
    }

    private function hashIp(Request $request): ?string
    {
        $ip = $request->ip();

        return $ip ? hash('sha256', $ip) : null;
    }
}
