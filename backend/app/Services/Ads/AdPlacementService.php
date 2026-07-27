<?php

namespace App\Services\Ads;

use App\Models\AdCreative;
use App\Models\AdZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class AdPlacementService
{
    /**
     * @param  array{device?: string|null, category?: string|null, article_id?: int|null}  $context
     */
    public function bestCreativeForZone(AdZone $zone, array $context = []): ?AdCreative
    {
        $creatives = AdCreative::query()
            ->with(['campaign', 'image', 'zone'])
            ->where('is_active', true)
            ->where('is_fallback', false)
            ->where('zone_id', $zone->id)
            ->whereHas('campaign', fn (Builder $query) => $query->eligibleForDeliveryAt(now()))
            ->get()
            ->filter(fn (AdCreative $creative): bool => $this->campaignMatchesContext($creative, $context));

        if ($creatives->isEmpty()) {
            return null;
        }

        return $this->rankedCreatives($creatives)->first();
    }

    /**
     * @param  Collection<int, AdCreative>  $creatives
     * @return Collection<int, AdCreative>
     */
    private function rankedCreatives(Collection $creatives): Collection
    {
        return $creatives
            ->sort(function (AdCreative $first, AdCreative $second): int {
                $priority = (int) $second->campaign?->priority <=> (int) $first->campaign?->priority;

                if ($priority !== 0) {
                    return $priority;
                }

                $impressions = $first->impressions()->count() <=> $second->impressions()->count();

                if ($impressions !== 0) {
                    return $impressions;
                }

                $clicks = $first->clicks()->count() <=> $second->clicks()->count();

                if ($clicks !== 0) {
                    return $clicks;
                }

                return $first->id <=> $second->id;
            })
            ->values();
    }

    /**
     * @param  array{device?: string|null, category?: string|int|null, category_id?: int|null, article_id?: int|null}  $context
     */
    private function campaignMatchesContext(AdCreative $creative, array $context): bool
    {
        $campaign = $creative->campaign;

        if (! $campaign) {
            return false;
        }

        return $this->targetMatches($campaign->device_targeting ?? [], $context['device'] ?? null)
            && $this->targetMatches($campaign->category_targeting ?? [], [
                $context['category'] ?? null,
                $context['category_id'] ?? null,
            ])
            && $this->targetMatches($campaign->article_targeting ?? [], $context['article_id'] ?? null);
    }

    /**
     * @param  array<int, mixed>  $targets
     */
    private function targetMatches(array $targets, string|int|array|null $value): bool
    {
        $targets = $this->normalizedTargetValues($targets);
        $values = $this->normalizedTargetValues(Arr::wrap($value));

        if ($targets === []) {
            return true;
        }

        if ($values === []) {
            return false;
        }

        return collect($values)->intersect($targets)->isNotEmpty();
    }

    /**
     * @param  array<int, mixed>  $targets
     * @return array<int, string>
     */
    private function normalizedTargetValues(array $targets): array
    {
        return collect($targets)
            ->flatMap(fn ($target): array => $this->targetValues($target))
            ->filter(fn ($target): bool => $target !== '')
            ->map(fn ($target): string => (string) $target)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string|int>
     */
    private function targetValues(mixed $target): array
    {
        if ($target === null || $target === '') {
            return [];
        }

        if (! is_array($target)) {
            return [$target];
        }

        $values = [];

        foreach (['device', 'category', 'category_id', 'article', 'article_id', 'id', 'slug', 'value'] as $key) {
            if (isset($target[$key]) && $target[$key] !== '') {
                $values[] = $target[$key];
            }
        }

        if ($values === [] && array_is_list($target)) {
            foreach ($target as $nestedTarget) {
                $values = array_merge($values, $this->targetValues($nestedTarget));
            }
        }

        return $values;
    }
}
