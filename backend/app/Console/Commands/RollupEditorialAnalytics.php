<?php

namespace App\Console\Commands;

use App\Services\Analytics\EditorialAnalyticsRollupService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class RollupEditorialAnalytics extends Command
{
    protected $signature = 'editorial-analytics:rollup
        {--from= : First calendar day (Y-m-d)}
        {--to= : Last calendar day (Y-m-d)}
        {--days= : Inclusive lookback when --from is omitted}
        {--prune : Prune expired pseudonymous raw events after rollup}';

    protected $description = 'Safely rebuild daily editorial analytics aggregates';

    public function handle(EditorialAnalyticsRollupService $rollups): int
    {
        try {
            $to = filled($this->option('to')) ? Carbon::parse((string) $this->option('to')) : today();
            $lookback = max(1, (int) ($this->option('days') ?: config('editorial_analytics.rollup_lookback_days', 3)));
            $from = filled($this->option('from'))
                ? Carbon::parse((string) $this->option('from'))
                : $to->copy()->subDays($lookback - 1);
        } catch (Throwable) {
            $this->error('Use valid Y-m-d values for --from and --to.');

            return self::INVALID;
        }

        $result = $rollups->rollup($from, $to);
        $this->info("Rebuilt {$result['rows']} aggregate rows across {$result['days']} day(s).");

        if ($this->option('prune')) {
            $pruned = $rollups->pruneRawEvents();
            $this->info("Pruned {$pruned} expired raw event(s).");
        }

        return self::SUCCESS;
    }
}
