<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AdCampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class AdCampaign extends Model
{
    /** @use HasFactory<AdCampaignFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'device_targeting' => 'array',
            'category_targeting' => 'array',
            'article_targeting' => 'array',
        ];
    }

    public function scopeEligibleAt(Builder $query, CarbonInterface|string|null $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('status', 'active')
            ->where('start_at', '<=', $at)
            ->where('end_at', '>=', $at);
    }

    public function scopeEligibleForDeliveryAt(Builder $query, CarbonInterface|string|null $at = null): Builder
    {
        $at = $at instanceof CarbonInterface ? $at : Carbon::parse($at ?? now());
        $dayStart = $at->copy()->startOfDay();

        return $query
            ->eligibleAt($at)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('max_impressions')
                    ->orWhere('max_impressions', '>', function ($query): void {
                        $query
                            ->selectRaw('count(*)')
                            ->from('ad_impressions')
                            ->whereColumn('ad_impressions.campaign_id', 'ad_campaigns.id');
                    });
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('max_clicks')
                    ->orWhere('max_clicks', '>', function ($query): void {
                        $query
                            ->selectRaw('count(*)')
                            ->from('ad_clicks')
                            ->whereColumn('ad_clicks.campaign_id', 'ad_campaigns.id');
                    });
            })
            ->where(function (Builder $query) use ($dayStart, $at): void {
                $query
                    ->whereNull('daily_impression_cap')
                    ->orWhere('daily_impression_cap', '>', function ($query) use ($dayStart, $at): void {
                        $query
                            ->selectRaw('count(*)')
                            ->from('ad_impressions')
                            ->whereColumn('ad_impressions.campaign_id', 'ad_campaigns.id')
                            ->whereBetween('occurred_at', [$dayStart, $at]);
                    });
            });
    }

    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(Advertiser::class);
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(AdCreative::class, 'campaign_id');
    }

    public function bookingLineItems(): HasMany
    {
        return $this->hasMany(AdBookingLineItem::class, 'ad_campaign_id');
    }

    public function financeExpenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'related_ad_campaign_id');
    }

    public function impressions(): HasMany
    {
        return $this->hasMany(AdImpression::class, 'campaign_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AdClick::class, 'campaign_id');
    }

    public function totalImpressions(): int
    {
        return $this->impressions()->count();
    }

    public function totalClicks(): int
    {
        return $this->clicks()->count();
    }

    public function ctrPercent(): float
    {
        $impressions = $this->totalImpressions();

        if ($impressions === 0) {
            return 0.0;
        }

        return round(($this->totalClicks() / $impressions) * 100, 2);
    }

    public function impressionCapProgress(): string
    {
        $count = $this->totalImpressions();

        return $this->max_impressions ? "{$count} / {$this->max_impressions}" : (string) $count;
    }

    public function clickCapProgress(): string
    {
        $count = $this->totalClicks();

        return $this->max_clicks ? "{$count} / {$this->max_clicks}" : (string) $count;
    }
}
