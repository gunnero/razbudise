<?php

namespace App\Services\Ads;

use App\Models\AdCampaign;
use App\Models\AdClick;
use App\Models\AdCreative;
use App\Models\AdImpression;
use App\Models\AdZone;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class AdReportService
{
    /**
     * @return array{
     *     range: array{from: string, to: string},
     *     totals: array{impressions: int, clicks: int, ctr_percent: float},
     *     campaigns: array<int, array<string, mixed>>,
     *     zones: array<int, array<string, mixed>>
     * }
     */
    public function summary(CarbonInterface|string|null $from = null, CarbonInterface|string|null $to = null): array
    {
        [$fromAt, $toAt] = $this->dateRange($from, $to);

        $impressions = AdImpression::query()
            ->whereBetween('occurred_at', [$fromAt, $toAt])
            ->count();

        $clicks = AdClick::query()
            ->whereBetween('occurred_at', [$fromAt, $toAt])
            ->count();

        return [
            'range' => [
                'from' => $fromAt->toDateTimeString(),
                'to' => $toAt->toDateTimeString(),
            ],
            'totals' => [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr_percent' => $this->ctr($impressions, $clicks),
            ],
            'campaigns' => $this->campaignRows($fromAt, $toAt),
            'zones' => $this->zoneRows($fromAt, $toAt),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function campaignExportRows(CarbonInterface|string|null $from = null, CarbonInterface|string|null $to = null): array
    {
        return collect($this->summary($from, $to)['campaigns'])
            ->map(fn (array $campaign): array => [
                'campaign_id' => $campaign['id'],
                'campaign' => $campaign['name'],
                'advertiser' => $campaign['advertiser'],
                'status' => $campaign['status'],
                'impressions' => $campaign['impressions'],
                'clicks' => $campaign['clicks'],
                'ctr_percent' => $campaign['ctr_percent'],
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function zoneInventoryRows(): array
    {
        $globalFallbackCreatives = AdCreative::query()
            ->where('is_active', true)
            ->where('is_fallback', true)
            ->whereNull('zone_id')
            ->count();

        return AdZone::query()
            ->withCount([
                'creatives as active_creatives' => fn ($query) => $query->where('is_active', true),
                'creatives as fallback_creatives' => fn ($query) => $query->where('is_active', true)->where('is_fallback', true),
            ])
            ->orderBy('page_context')
            ->orderBy('key')
            ->get()
            ->map(function (AdZone $zone) use ($globalFallbackCreatives): array {
                $eligibleCreatives = $zone->creatives()
                    ->where('is_active', true)
                    ->where('is_fallback', false)
                    ->whereHas('campaign', fn ($query) => $query->eligibleForDeliveryAt(now()))
                    ->count();
                $zoneFallbackCreatives = (int) $zone->fallback_creatives;
                $deliveryStatus = $this->deliveryStatus($zone, $eligibleCreatives, $zoneFallbackCreatives, $globalFallbackCreatives);

                return [
                    'zone_key' => $zone->key,
                    'zone_name' => $zone->name,
                    'page_context' => $zone->page_context,
                    'is_active' => $zone->is_active ? 'yes' : 'no',
                    'allowed_sizes' => collect($zone->allowed_sizes ?? [])
                        ->map(fn ($size): string => is_array($size) ? implode('x', $size) : (string) $size)
                        ->implode(', '),
                    'active_creatives' => (int) $zone->active_creatives,
                    'eligible_creatives' => $eligibleCreatives,
                    'fallback_creatives' => $zoneFallbackCreatives,
                    'global_fallback_creatives' => $globalFallbackCreatives,
                    'has_launch_delivery' => in_array($deliveryStatus, ['campaign', 'zone_fallback', 'global_fallback'], true) ? 'yes' : 'no',
                    'delivery_status' => $deliveryStatus,
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return array{
     *     required: int,
     *     missing_zone_keys: array<int, string>,
     *     undeliverable_zones: array<int, array<string, string>>,
     *     blocking_count: int
     * }
     */
    public function requiredZoneCoverage(?array $rows = null): array
    {
        $requiredKeys = collect(config('ads.required_zone_keys', []))
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->map(fn (string $key): string => trim($key))
            ->unique()
            ->values();
        $rowsByKey = collect($rows ?? $this->zoneInventoryRows())->keyBy('zone_key');
        $missingZoneKeys = $requiredKeys->diff($rowsByKey->keys())->values()->all();
        $undeliverableZones = $requiredKeys
            ->intersect($rowsByKey->keys())
            ->map(function (string $key) use ($rowsByKey): ?array {
                $row = $rowsByKey->get($key);

                if (($row['has_launch_delivery'] ?? 'no') === 'yes') {
                    return null;
                }

                return [
                    'zone_key' => $key,
                    'delivery_status' => (string) ($row['delivery_status'] ?? 'unknown'),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'required' => $requiredKeys->count(),
            'missing_zone_keys' => $missingZoneKeys,
            'undeliverable_zones' => $undeliverableZones,
            'blocking_count' => count($missingZoneKeys) + count($undeliverableZones),
        ];
    }

    /**
     * @return array{CarbonInterface, CarbonInterface}
     */
    private function dateRange(CarbonInterface|string|null $from, CarbonInterface|string|null $to): array
    {
        $fromAt = $from instanceof CarbonInterface
            ? Carbon::parse($from->toDateTimeString())
            : Carbon::parse($from ?? now()->subDays(30));

        $toAt = $to instanceof CarbonInterface
            ? Carbon::parse($to->toDateTimeString())
            : Carbon::parse($to ?? now());

        return [
            $fromAt->copy()->startOfDay(),
            $toAt->copy()->endOfDay(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function campaignRows(CarbonInterface $fromAt, CarbonInterface $toAt): array
    {
        return AdCampaign::query()
            ->with('advertiser')
            ->withCount([
                'impressions as report_impressions' => fn ($query) => $query->whereBetween('occurred_at', [$fromAt, $toAt]),
                'clicks as report_clicks' => fn ($query) => $query->whereBetween('occurred_at', [$fromAt, $toAt]),
            ])
            ->where(function ($query) use ($fromAt, $toAt): void {
                $query
                    ->whereHas('impressions', fn ($query) => $query->whereBetween('occurred_at', [$fromAt, $toAt]))
                    ->orWhereHas('clicks', fn ($query) => $query->whereBetween('occurred_at', [$fromAt, $toAt]));
            })
            ->get()
            ->sortByDesc('report_impressions')
            ->values()
            ->map(fn (AdCampaign $campaign): array => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'advertiser' => $campaign->advertiser?->name,
                'status' => $campaign->status,
                'impressions' => (int) $campaign->report_impressions,
                'clicks' => (int) $campaign->report_clicks,
                'ctr_percent' => $this->ctr((int) $campaign->report_impressions, (int) $campaign->report_clicks),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function zoneRows(CarbonInterface $fromAt, CarbonInterface $toAt): array
    {
        return AdZone::query()
            ->withCount([
                'impressions as report_impressions' => fn ($query) => $query->whereBetween('occurred_at', [$fromAt, $toAt]),
                'clicks as report_clicks' => fn ($query) => $query->whereBetween('occurred_at', [$fromAt, $toAt]),
            ])
            ->where(function ($query) use ($fromAt, $toAt): void {
                $query
                    ->whereHas('impressions', fn ($query) => $query->whereBetween('occurred_at', [$fromAt, $toAt]))
                    ->orWhereHas('clicks', fn ($query) => $query->whereBetween('occurred_at', [$fromAt, $toAt]));
            })
            ->get()
            ->sortByDesc('report_impressions')
            ->values()
            ->map(fn (AdZone $zone): array => [
                'id' => $zone->id,
                'key' => $zone->key,
                'name' => $zone->name,
                'impressions' => (int) $zone->report_impressions,
                'clicks' => (int) $zone->report_clicks,
                'ctr_percent' => $this->ctr((int) $zone->report_impressions, (int) $zone->report_clicks),
            ])
            ->all();
    }

    private function ctr(int $impressions, int $clicks): float
    {
        if ($impressions === 0) {
            return 0.0;
        }

        return round(($clicks / $impressions) * 100, 2);
    }

    private function deliveryStatus(AdZone $zone, int $eligibleCreatives, int $zoneFallbackCreatives, int $globalFallbackCreatives): string
    {
        if (! $zone->is_active) {
            return 'inactive';
        }

        if ($eligibleCreatives > 0) {
            return 'campaign';
        }

        if ($zoneFallbackCreatives > 0) {
            return 'zone_fallback';
        }

        if ($globalFallbackCreatives > 0) {
            return 'global_fallback';
        }

        return 'empty';
    }
}
