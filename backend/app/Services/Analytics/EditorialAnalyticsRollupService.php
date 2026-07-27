<?php

namespace App\Services\Analytics;

use App\Models\ArticleView;
use App\Models\EditorialAnalyticsDaily;
use App\Models\EditorialAnalyticsEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EditorialAnalyticsRollupService
{
    /**
     * Rebuild complete calendar days. Rebuilding, rather than incrementing,
     * makes retries safe when late events arrive or a scheduled run overlaps a
     * deployment.
     *
     * @return array{days: int, rows: int}
     */
    public function rollup(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $days = 0;
        $rows = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $rows += $this->rollupDay($day);
            $days++;
        }

        return ['days' => $days, 'rows' => $rows];
    }

    public function pruneRawEvents(?int $retentionDays = null): int
    {
        $days = max(30, $retentionDays ?? (int) config('editorial_analytics.raw_event_retention_days', 395));
        $cutoff = now()->subDays($days);
        $events = EditorialAnalyticsEvent::query()
            ->where('occurred_at', '<', $cutoff)
            ->delete();
        $views = ArticleView::query()
            ->where('occurred_at', '<', $cutoff)
            ->delete();

        return $events + $views;
    }

    private function rollupDay(Carbon $day): int
    {
        $date = $day->toDateString();
        $rows = [];

        $eventRows = EditorialAnalyticsEvent::query()
            ->whereDate('event_date', $date)
            ->whereNotNull('article_id')
            ->select(['article_id', 'author_id', 'category_id', 'source_channel', 'device'])
            ->selectRaw("SUM(CASE WHEN event_type = 'article_open' THEN 1 ELSE 0 END) AS page_views")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event_type = 'article_open' THEN COALESCE(session_hash, visitor_hash) END) AS article_opens")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event_type = 'article_open' THEN visitor_hash END) AS unique_visitors")
            ->selectRaw("SUM(CASE WHEN event_type = 'engaged_session' THEN 1 ELSE 0 END) AS engaged_sessions")
            ->selectRaw("SUM(CASE WHEN event_type = 'engaged_session' THEN COALESCE(engaged_ms, 0) ELSE 0 END) AS engaged_time_ms")
            ->selectRaw("SUM(CASE WHEN event_type IN ('outbound_click', 'external_link_click', 'source_link_click') THEN 1 ELSE 0 END) AS outbound_clicks")
            ->selectRaw("SUM(CASE WHEN event_type = 'social_share' THEN 1 ELSE 0 END) AS social_shares")
            ->selectRaw("SUM(CASE WHEN event_type = 'completion' THEN 1 ELSE 0 END) AS completions")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event_type = 'article_open' AND is_returning = 1 THEN visitor_hash END) AS return_visitors")
            ->selectRaw("SUM(CASE WHEN event_type = 'homepage_article_click' THEN 1 ELSE 0 END) AS homepage_clicks")
            ->selectRaw("SUM(CASE WHEN event_type = 'category_article_click' THEN 1 ELSE 0 END) AS category_clicks")
            ->selectRaw("SUM(CASE WHEN event_type = 'author_article_click' THEN 1 ELSE 0 END) AS author_clicks")
            ->selectRaw("SUM(CASE WHEN event_type = 'search_article_click' THEN 1 ELSE 0 END) AS search_clicks")
            ->selectRaw("SUM(CASE WHEN event_type = 'related_article_click' THEN 1 ELSE 0 END) AS related_clicks")
            ->groupBy(['article_id', 'author_id', 'category_id', 'source_channel', 'device'])
            ->get();

        foreach ($eventRows as $eventRow) {
            $key = $this->dimensionKey(
                (int) $eventRow->article_id,
                (string) ($eventRow->source_channel ?: 'unknown'),
                (string) ($eventRow->device ?: 'unknown'),
            );
            $rows[$key] = $this->baseRow($date, $eventRow);
            foreach ([
                'page_views', 'article_opens', 'unique_visitors', 'engaged_sessions',
                'engaged_time_ms', 'outbound_clicks', 'social_shares', 'completions',
                'return_visitors', 'homepage_clicks', 'category_clicks', 'author_clicks',
                'search_clicks', 'related_clicks',
            ] as $metric) {
                $rows[$key][$metric] = (int) $eventRow->{$metric};
            }
        }

        // Article views predate v2 events. They remain the authoritative
        // period-level unique source, and provide a conservative historical
        // fallback when no raw article_open event exists for a dimension.
        $viewRows = ArticleView::query()
            ->join('articles', 'articles.id', '=', 'article_views.article_id')
            ->whereDate('article_views.viewed_on', $date)
            ->select([
                'article_views.article_id',
                'articles.author_id',
                'articles.category_id',
                'article_views.source_channel',
                'article_views.device',
            ])
            ->selectRaw('COUNT(DISTINCT article_views.visitor_hash) AS unique_visitors')
            ->selectRaw('COUNT(DISTINCT CASE WHEN article_views.is_returning = 1 THEN article_views.visitor_hash END) AS return_visitors')
            ->groupBy([
                'article_views.article_id', 'articles.author_id', 'articles.category_id',
                'article_views.source_channel', 'article_views.device',
            ])
            ->get();

        foreach ($viewRows as $viewRow) {
            $source = (string) ($viewRow->source_channel ?: 'unknown');
            $device = (string) ($viewRow->device ?: 'unknown');
            $key = $this->dimensionKey((int) $viewRow->article_id, $source, $device);
            $rows[$key] ??= $this->baseRow($date, $viewRow);
            $uniqueVisitors = (int) $viewRow->unique_visitors;
            $rows[$key]['unique_visitors'] = max($rows[$key]['unique_visitors'], $uniqueVisitors);
            $rows[$key]['return_visitors'] = max($rows[$key]['return_visitors'], (int) $viewRow->return_visitors);
            $rows[$key]['page_views'] = max($rows[$key]['page_views'], $uniqueVisitors);
            $rows[$key]['article_opens'] = max($rows[$key]['article_opens'], $uniqueVisitors);
        }

        $sessionScrolls = EditorialAnalyticsEvent::query()
            ->whereDate('event_date', $date)
            ->where('event_type', 'scroll_depth')
            ->whereNotNull('article_id')
            ->whereNotNull('scroll_depth')
            ->select(['article_id', 'author_id', 'category_id', 'source_channel', 'device'])
            ->selectRaw('COALESCE(session_hash, visitor_hash) AS reader_session')
            ->selectRaw('MAX(scroll_depth) AS max_scroll_depth')
            ->groupBy(['article_id', 'author_id', 'category_id', 'source_channel', 'device'])
            ->groupByRaw('COALESCE(session_hash, visitor_hash)');

        $scrollRows = DB::query()
            ->fromSub($sessionScrolls, 'session_scrolls')
            ->select(['article_id', 'author_id', 'category_id', 'source_channel', 'device'])
            ->selectRaw('SUM(max_scroll_depth) AS scroll_depth_sum')
            ->selectRaw('COUNT(*) AS scroll_depth_samples')
            ->groupBy(['article_id', 'author_id', 'category_id', 'source_channel', 'device'])
            ->get();

        foreach ($scrollRows as $scrollRow) {
            $source = (string) ($scrollRow->source_channel ?: 'unknown');
            $device = (string) ($scrollRow->device ?: 'unknown');
            $key = $this->dimensionKey((int) $scrollRow->article_id, $source, $device);
            $rows[$key] ??= $this->baseRow($date, $scrollRow);
            $rows[$key]['scroll_depth_sum'] = (int) $scrollRow->scroll_depth_sum;
            $rows[$key]['scroll_depth_samples'] = (int) $scrollRow->scroll_depth_samples;
        }

        // Engagement checkpoints are cumulative active time. Keep the largest
        // checkpoint per reader session so background/foreground cycles do not
        // inflate either session count or engaged time.
        $sessionEngagement = EditorialAnalyticsEvent::query()
            ->whereDate('event_date', $date)
            ->where('event_type', 'engaged_session')
            ->whereNotNull('article_id')
            ->whereNotNull('engaged_ms')
            ->select(['article_id', 'author_id', 'category_id', 'source_channel', 'device'])
            ->selectRaw('COALESCE(session_hash, visitor_hash) AS reader_session')
            ->selectRaw('MAX(engaged_ms) AS max_engaged_ms')
            ->groupBy(['article_id', 'author_id', 'category_id', 'source_channel', 'device'])
            ->groupByRaw('COALESCE(session_hash, visitor_hash)');

        $engagementRows = DB::query()
            ->fromSub($sessionEngagement, 'session_engagement')
            ->select(['article_id', 'author_id', 'category_id', 'source_channel', 'device'])
            ->selectRaw('SUM(max_engaged_ms) AS engaged_time_ms')
            ->selectRaw('COUNT(*) AS engaged_sessions')
            ->groupBy(['article_id', 'author_id', 'category_id', 'source_channel', 'device'])
            ->get();

        foreach ($engagementRows as $engagementRow) {
            $source = (string) ($engagementRow->source_channel ?: 'unknown');
            $device = (string) ($engagementRow->device ?: 'unknown');
            $key = $this->dimensionKey((int) $engagementRow->article_id, $source, $device);
            $rows[$key] ??= $this->baseRow($date, $engagementRow);
            $rows[$key]['engaged_time_ms'] = (int) $engagementRow->engaged_time_ms;
            $rows[$key]['engaged_sessions'] = (int) $engagementRow->engaged_sessions;
        }

        $timestamp = now();
        $payload = collect($rows)->map(function (array $row) use ($timestamp): array {
            $row['created_at'] = $timestamp;
            $row['updated_at'] = $timestamp;

            return $row;
        })->values()->all();

        DB::transaction(function () use ($date, $payload): void {
            EditorialAnalyticsDaily::query()->where('day', $date)->delete();
            foreach (array_chunk($payload, 500) as $chunk) {
                EditorialAnalyticsDaily::query()->insert($chunk);
            }
        });

        return count($payload);
    }

    private function dimensionKey(int $articleId, string $source, string $device): string
    {
        return $articleId.'|'.$source.'|'.$device;
    }

    /** @return array<string, mixed> */
    private function baseRow(string $date, object $source): array
    {
        return [
            'day' => $date,
            'scope_key' => 'article:'.(int) $source->article_id,
            'article_id' => (int) $source->article_id,
            'author_id' => $source->author_id ? (int) $source->author_id : null,
            'category_id' => $source->category_id ? (int) $source->category_id : null,
            'source_channel' => (string) ($source->source_channel ?: 'unknown'),
            'device' => (string) ($source->device ?: 'unknown'),
            'page_views' => 0,
            'article_opens' => 0,
            'unique_visitors' => 0,
            'engaged_sessions' => 0,
            'engaged_time_ms' => 0,
            'scroll_depth_sum' => 0,
            'scroll_depth_samples' => 0,
            'outbound_clicks' => 0,
            'social_shares' => 0,
            'completions' => 0,
            'return_visitors' => 0,
            'homepage_clicks' => 0,
            'category_clicks' => 0,
            'author_clicks' => 0,
            'search_clicks' => 0,
            'related_clicks' => 0,
        ];
    }
}
