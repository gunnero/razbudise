<?php

namespace App\Services\Analytics;

use App\Models\Article;
use App\Models\ArticleView;
use App\Models\Author;
use App\Models\Category;
use App\Models\EditorialAnalyticsDaily;
use App\Models\EditorialAnalyticsEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EditorialAnalyticsService
{
    public const DATE_RANGES = [
        'today' => 'Денес',
        'yesterday' => 'Вчера',
        'last_7_days' => 'Последни 7 дена',
        'last_30_days' => 'Последни 30 дена',
        'current_month' => 'Тековен месец',
        'previous_month' => 'Претходен месец',
        'custom' => 'Сопствен период',
    ];

    public const SOURCE_LABELS = [
        'homepage' => 'Почетна',
        'category_page' => 'Рубрика',
        'author_page' => 'Авторски профил',
        'internal_search' => 'Внатрешно пребарување',
        'related_article' => 'Поврзана објава',
        'direct' => 'Директно',
        'organic_search' => 'Органско пребарување',
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'x' => 'X',
        'linkedin' => 'LinkedIn',
        'newsletter' => 'Билтен',
        'external_referral' => 'Надворешна препорака',
        'paid_campaign' => 'Платена кампања',
        'unknown' => 'Непознато',
    ];

    public const DEVICE_LABELS = [
        'desktop' => 'Компјутер',
        'mobile' => 'Мобилен',
        'tablet' => 'Таблет',
        'unknown' => 'Непознато',
    ];

    public const SERIES_METRICS = [
        'page_views' => 'Прегледи на страница',
        'unique_visitors' => 'Уникатни читатели',
        'engaged_sessions' => 'Ангажирани сесии',
        'outbound_clicks' => 'Надворешни кликови',
        'social_shares' => 'Споделувања',
        'completions' => 'Завршени читања',
    ];

    /**
     * Complete role-scoped dashboard report. Raw identifiers are never
     * returned; period-level uniqueness is calculated from hashed view rows.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(User $user, array $filters = []): array
    {
        $normalized = $this->normalizeFilters($filters);
        $cacheKey = 'editorial-analytics:v2:'.hash('sha256', json_encode([
            $user->id,
            $user->roles()->pluck('name')->sort()->values()->all(),
            $this->scopeFingerprint($user),
            $normalized,
            EditorialAnalyticsDaily::query()->max('updated_at'),
        ], JSON_THROW_ON_ERROR));

        $build = fn (): array => $this->buildReport($user, $normalized);

        return app()->environment('testing')
            ? $build()
            : Cache::remember($cacheKey, (int) config('editorial_analytics.report_cache_seconds', 300), $build);
    }

    /**
     * Backwards-compatible compact summary used by existing cards and tests.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $articles = $this->articlesFor($user);
        $articleIds = (clone $articles)->select('articles.id');
        $now = now();
        $topArticles = (clone $articles)
            ->withCount([
                'views as views_7d_count' => fn (Builder $query) => $query->where('occurred_at', '>=', $now->copy()->subDays(7)),
                'views as views_30d_count' => fn (Builder $query) => $query->where('occurred_at', '>=', $now->copy()->subDays(30)),
                'views as views_total_count',
            ])
            ->orderByDesc('views_30d_count')
            ->limit(20)
            ->get(['id', 'title', 'slug', 'published_at']);

        return [
            'scope' => $this->scope($user),
            'published_articles' => (clone $articles)->count(),
            'views_7d' => ArticleView::query()->whereIn('article_id', clone $articleIds)->where('occurred_at', '>=', $now->copy()->subDays(7))->count(),
            'views_30d' => ArticleView::query()->whereIn('article_id', clone $articleIds)->where('occurred_at', '>=', $now->copy()->subDays(30))->count(),
            'views_total' => ArticleView::query()->whereIn('article_id', clone $articleIds)->count(),
            'top_articles' => $topArticles,
            'has_author_link' => $user->isAdmin() || $user->hasRole('editor_in_chief') || $user->hasRole('editor') || (bool) $user->teamMember?->author_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, int|string|null|float>>
     */
    public function exportRows(User $user, array $filters): array
    {
        $normalized = $this->normalizeFilters($filters);
        [$start, $end] = $this->dateBounds($normalized);
        $articles = $this->filteredArticles($user, $normalized);
        $articleIds = $articles->pluck('articles.id');
        $aggregates = $this->articleAggregates($articleIds, $start, $end, $normalized, 10000)->keyBy('article_id');

        return $articles
            ->with(['author:id,name', 'category:id,name', 'supervisingEditor:id,name'])
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'author_id', 'category_id', 'supervising_editor_id', 'type', 'published_at'])
            ->map(function (Article $article) use ($aggregates): array {
                $stats = $aggregates->get($article->id, []);

                return [
                    'article_id' => $article->id,
                    'title' => $article->title,
                    'slug' => $article->slug,
                    'author' => $article->author?->name,
                    'editor' => $article->supervisingEditor?->name,
                    'category' => $article->category?->name,
                    'type' => $article->type,
                    'published_at' => $article->published_at?->toIso8601String(),
                    'page_views' => (int) ($stats['page_views'] ?? 0),
                    'unique_visitors' => $stats['unique_visitors'] ?? null,
                    'engaged_sessions' => (int) ($stats['engaged_sessions'] ?? 0),
                    'engaged_time_seconds' => (float) ($stats['engaged_time_seconds'] ?? 0),
                    'average_engaged_seconds' => $this->divide($stats['engaged_time_ms'] ?? 0, ($stats['engaged_sessions'] ?? 0) * 1000, 1),
                    'average_scroll_depth' => $this->divide($stats['scroll_depth_sum'] ?? 0, $stats['scroll_depth_samples'] ?? 0, 1),
                    'completion_rate' => $this->percentage($stats['completions'] ?? 0, $stats['article_opens'] ?? 0),
                    'outbound_clicks' => (int) ($stats['outbound_clicks'] ?? 0),
                    'social_shares' => (int) ($stats['social_shares'] ?? 0),
                    'return_visitors' => $stats['return_visitors'] ?? null,
                    'homepage_clicks' => (int) ($stats['homepage_clicks'] ?? 0),
                    'category_clicks' => (int) ($stats['category_clicks'] ?? 0),
                    'author_clicks' => (int) ($stats['author_clicks'] ?? 0),
                    'search_clicks' => (int) ($stats['search_clicks'] ?? 0),
                    'related_clicks' => (int) ($stats['related_clicks'] ?? 0),
                ];
            })
            ->all();
    }

    /** @return array<int, string> */
    public function exportHeaders(): array
    {
        return [
            'article_id', 'title', 'slug', 'author', 'editor', 'category', 'type',
            'published_at', 'page_views', 'unique_visitors', 'engaged_sessions', 'engaged_time_seconds',
            'average_engaged_seconds', 'average_scroll_depth', 'completion_rate',
            'outbound_clicks', 'social_shares', 'return_visitors', 'homepage_clicks',
            'category_clicks', 'author_clicks', 'search_clicks', 'related_clicks',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function buildReport(User $user, array $filters): array
    {
        [$start, $end] = $this->dateBounds($filters);
        [$previousStart, $previousEnd] = $this->previousDateBounds($start, $end);
        $articles = $this->filteredArticles($user, $filters);
        $articleIds = $articles->pluck('articles.id');
        $current = $this->metrics($articleIds, $start, $end, $filters);
        $previous = $this->metrics($articleIds, $previousStart, $previousEnd, $filters);
        $metricCards = $this->metricCards($current, $previous);
        $topArticles = $this->articleAggregates($articleIds, $start, $end, $filters, 20);
        $authorAnalytics = $this->authorAggregates($articleIds, $start, $end, $filters);
        $series = $this->series($articleIds, $start, $end, $filters);
        $filtersData = $this->filterOptions($user);

        return [
            'scope' => $this->scope($user),
            'scope_label' => $this->scopeLabel($user),
            'has_author_link' => $user->isAdmin() || $user->hasRole('editor_in_chief') || $user->hasRole('editor') || (bool) $user->teamMember?->author_id,
            'date' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'previous_start' => $previousStart->toDateString(),
                'previous_end' => $previousEnd->toDateString(),
                'label' => $start->equalTo($end)
                    ? $start->locale('mk')->translatedFormat('j F Y')
                    : $start->locale('mk')->translatedFormat('j M').' до '.$end->locale('mk')->translatedFormat('j M Y'),
            ],
            'metrics' => $current,
            'metric_cards' => $metricCards,
            'series' => $series,
            'top_articles' => $topArticles,
            'authors' => $authorAnalytics,
            'sources' => $this->dimensionBreakdown($articleIds, $start, $end, $filters, 'source_channel', self::SOURCE_LABELS),
            'devices' => $this->dimensionBreakdown($articleIds, $start, $end, $filters, 'device', self::DEVICE_LABELS),
            'categories' => $this->categoryBreakdown($articleIds, $start, $end, $filters),
            'article_detail' => $this->articleDetail($articles, $filters, $start, $end),
            'author_detail' => $this->authorDetail($authorAnalytics, $filters),
            'site_interactions' => $this->siteInteractions($user, $start, $end, $filters),
            'realtime' => $this->nearRealtime($articleIds, $filters),
            'data_quality' => $this->dataQuality($user, $articleIds, $start, $end),
            'filter_options' => $filtersData,
            'selected' => $filters,
            'rollup_updated_at' => EditorialAnalyticsDaily::query()->max('updated_at'),
            'near_realtime_notice' => 'Податоците во прегледот се обновуваат приближно на секои 5 минути.',
        ];
    }

    /**
     * @param  Collection<int, int>  $articleIds
     * @param  array<string, mixed>  $filters
     * @return array<string, int|float|null>
     */
    private function metrics(Collection $articleIds, Carbon $start, Carbon $end, array $filters): array
    {
        $daily = $this->dailyQuery($articleIds, $start, $end, $filters);
        $stats = (clone $daily)
            ->selectRaw('COALESCE(SUM(page_views), 0) AS page_views')
            ->selectRaw('COALESCE(SUM(article_opens), 0) AS article_opens')
            ->selectRaw('COALESCE(SUM(engaged_sessions), 0) AS engaged_sessions')
            ->selectRaw('COALESCE(SUM(engaged_time_ms), 0) AS engaged_time_ms')
            ->selectRaw('COALESCE(SUM(scroll_depth_sum), 0) AS scroll_depth_sum')
            ->selectRaw('COALESCE(SUM(scroll_depth_samples), 0) AS scroll_depth_samples')
            ->selectRaw('COALESCE(SUM(outbound_clicks), 0) AS outbound_clicks')
            ->selectRaw('COALESCE(SUM(social_shares), 0) AS social_shares')
            ->selectRaw('COALESCE(SUM(completions), 0) AS completions')
            ->selectRaw('COALESCE(SUM(return_visitors), 0) AS daily_return_visitors')
            ->first();

        $views = ArticleView::query()
            ->whereIn('article_id', $articleIds)
            ->whereDate('viewed_on', '>=', $start->toDateString())
            ->whereDate('viewed_on', '<=', $end->toDateString());
        $this->applyViewDimensions($views, $filters);
        $uniqueVisitors = (clone $views)->distinct()->count('visitor_hash');
        $returnVisitors = (clone $views)->where('is_returning', true)->distinct()->count('visitor_hash');
        $rawCoverageAvailable = $this->rawCoverageAvailable($start);
        $legacyViews = (clone $views)->count();
        $pageViews = max((int) ($stats?->page_views ?? 0), $legacyViews);
        $articleOpens = max((int) ($stats?->article_opens ?? 0), $legacyViews);
        $publishedArticles = Article::query()
            ->whereIn('id', $articleIds)
            ->whereBetween('published_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->count();
        $aggregatedViewedArticles = (clone $daily)
            ->where('page_views', '>', 0)
            ->distinct()
            ->count('article_id');
        $viewedArticles = max(1, (clone $views)->distinct()->count('article_id'), $aggregatedViewedArticles);
        $engagedSessions = (int) ($stats?->engaged_sessions ?? 0);
        $scrollSamples = (int) ($stats?->scroll_depth_samples ?? 0);

        return [
            'page_views' => $pageViews,
            'unique_visitors' => $rawCoverageAvailable ? $uniqueVisitors : null,
            'article_opens' => $articleOpens,
            'engaged_sessions' => $engagedSessions,
            'average_engaged_seconds' => $this->divide((int) ($stats?->engaged_time_ms ?? 0), $engagedSessions * 1000, 1),
            'average_scroll_depth' => $this->divide((int) ($stats?->scroll_depth_sum ?? 0), $scrollSamples, 1),
            'outbound_clicks' => (int) ($stats?->outbound_clicks ?? 0),
            'social_shares' => (int) ($stats?->social_shares ?? 0),
            'completion_rate' => $this->percentage((int) ($stats?->completions ?? 0), $articleOpens),
            'return_visitors' => $rawCoverageAvailable ? $returnVisitors : null,
            'articles_published' => $publishedArticles,
            'average_views_per_article' => $this->divide($pageViews, $viewedArticles, 1),
        ];
    }

    /**
     * @param  array<string, int|float|null>  $current
     * @param  array<string, int|float|null>  $previous
     * @return array<int, array<string, mixed>>
     */
    private function metricCards(array $current, array $previous): array
    {
        $labels = [
            'page_views' => 'Прегледи на страница',
            'unique_visitors' => 'Уникатни читатели',
            'article_opens' => 'Отворања на објави',
            'engaged_sessions' => 'Ангажирани сесии',
            'average_engaged_seconds' => 'Просечно активно време',
            'average_scroll_depth' => 'Просечна длабочина',
            'outbound_clicks' => 'Надворешни кликови',
            'social_shares' => 'Споделувања',
            'completion_rate' => 'Стапка на дочитување',
            'return_visitors' => 'Повратни читатели',
            'articles_published' => 'Објавени текстови',
            'average_views_per_article' => 'Просек по објава',
        ];

        return collect($labels)->map(function (string $label, string $key) use ($current, $previous): array {
            $value = $current[$key] ?? null;
            $before = $previous[$key] ?? null;
            $available = $value !== null;
            $absolute = $available && $before !== null ? round((float) $value - (float) $before, 1) : null;
            $percent = $available && is_numeric($before) && (float) $before !== 0.0
                ? round((((float) $value - (float) $before) / abs((float) $before)) * 100, 1)
                : null;

            return compact('key', 'label', 'value', 'before', 'absolute', 'percent', 'available');
        })->where('available', true)->values()->all();
    }

    /**
     * @param  Collection<int, int>  $articleIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function articleAggregates(Collection $articleIds, Carbon $start, Carbon $end, array $filters, int $limit): Collection
    {
        $rows = $this->dailyQuery($articleIds, $start, $end, $filters)
            ->select('article_id')
            ->selectRaw('SUM(page_views) AS page_views')
            ->selectRaw('SUM(unique_visitors) AS unique_visitors')
            ->selectRaw('SUM(article_opens) AS article_opens')
            ->selectRaw('SUM(engaged_sessions) AS engaged_sessions')
            ->selectRaw('SUM(engaged_time_ms) AS engaged_time_ms')
            ->selectRaw('SUM(scroll_depth_sum) AS scroll_depth_sum')
            ->selectRaw('SUM(scroll_depth_samples) AS scroll_depth_samples')
            ->selectRaw('SUM(outbound_clicks) AS outbound_clicks')
            ->selectRaw('SUM(social_shares) AS social_shares')
            ->selectRaw('SUM(completions) AS completions')
            ->selectRaw('SUM(return_visitors) AS return_visitors')
            ->selectRaw('SUM(homepage_clicks) AS homepage_clicks')
            ->selectRaw('SUM(category_clicks) AS category_clicks')
            ->selectRaw('SUM(author_clicks) AS author_clicks')
            ->selectRaw('SUM(search_clicks) AS search_clicks')
            ->selectRaw('SUM(related_clicks) AS related_clicks')
            ->groupBy('article_id')
            ->orderByDesc('page_views')
            ->limit($limit)
            ->get()
            ->keyBy('article_id');

        $periodViews = ArticleView::query()
            ->whereIn('article_id', $articleIds)
            ->whereDate('viewed_on', '>=', $start->toDateString())
            ->whereDate('viewed_on', '<=', $end->toDateString());
        $this->applyViewDimensions($periodViews, $filters);
        $periodVisitors = $periodViews
            ->select('article_id')
            ->selectRaw('COUNT(DISTINCT visitor_hash) AS unique_visitors')
            ->selectRaw('COUNT(DISTINCT CASE WHEN is_returning = 1 THEN visitor_hash END) AS return_visitors')
            ->groupBy('article_id')
            ->get()
            ->keyBy('article_id');
        $rawCoverageAvailable = $this->rawCoverageAvailable($start);

        if ($rows->isEmpty() && $articleIds->isNotEmpty()) {
            $legacy = ArticleView::query()
                ->whereIn('article_id', $articleIds)
                ->whereDate('viewed_on', '>=', $start->toDateString())
                ->whereDate('viewed_on', '<=', $end->toDateString());
            $this->applyViewDimensions($legacy, $filters);
            $rows = $legacy
                ->select('article_id')
                ->selectRaw('COUNT(*) AS page_views')
                ->selectRaw('COUNT(DISTINCT visitor_hash) AS unique_visitors')
                ->groupBy('article_id')
                ->orderByDesc('page_views')
                ->limit($limit)
                ->get()
                ->keyBy('article_id');
        }

        $articles = Article::query()
            ->whereIn('id', $rows->keys())
            ->with(['author:id,name', 'category:id,name', 'supervisingEditor:id,name'])
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($articles, $periodVisitors, $rawCoverageAvailable): array {
            $article = $articles->get($row->article_id);
            $visitorStats = $periodVisitors->get($row->article_id);

            return [
                'article_id' => (int) $row->article_id,
                'title' => $article?->title ?? 'Избришана објава',
                'slug' => $article?->slug,
                'author' => $article?->author?->name,
                'editor' => $article?->supervisingEditor?->name,
                'category' => $article?->category?->name,
                'type' => $article?->type,
                'published_at' => $article?->published_at,
                'reading_time' => $article?->reading_time_minutes,
                'word_count' => $article ? $this->unicodeWordCount((string) $article->body_html) : null,
                'page_views' => (int) ($row->page_views ?? 0),
                'unique_visitors' => $rawCoverageAvailable ? (int) ($visitorStats?->unique_visitors ?? 0) : null,
                'engaged_sessions' => (int) ($row->engaged_sessions ?? 0),
                'engaged_time_seconds' => round(((int) ($row->engaged_time_ms ?? 0)) / 1000, 1),
                'average_engaged_seconds' => $this->divide($row->engaged_time_ms ?? 0, ($row->engaged_sessions ?? 0) * 1000, 1),
                'average_scroll_depth' => $this->divide($row->scroll_depth_sum ?? 0, $row->scroll_depth_samples ?? 0, 1),
                'completion_rate' => $this->percentage($row->completions ?? 0, $row->article_opens ?? 0),
                'outbound_clicks' => (int) ($row->outbound_clicks ?? 0),
                'social_shares' => (int) ($row->social_shares ?? 0),
                'return_visitors' => $rawCoverageAvailable ? (int) ($visitorStats?->return_visitors ?? 0) : null,
                'homepage_clicks' => (int) ($row->homepage_clicks ?? 0),
                'category_clicks' => (int) ($row->category_clicks ?? 0),
                'author_clicks' => (int) ($row->author_clicks ?? 0),
                'search_clicks' => (int) ($row->search_clicks ?? 0),
                'related_clicks' => (int) ($row->related_clicks ?? 0),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, int>  $articleIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function authorAggregates(Collection $articleIds, Carbon $start, Carbon $end, array $filters): Collection
    {
        $rows = $this->dailyQuery($articleIds, $start, $end, $filters)
            ->whereNotNull('author_id')
            ->select('author_id')
            ->selectRaw('SUM(page_views) AS page_views')
            ->selectRaw('SUM(unique_visitors) AS unique_visitors')
            ->selectRaw('SUM(article_opens) AS article_opens')
            ->selectRaw('SUM(engaged_sessions) AS engaged_sessions')
            ->selectRaw('SUM(engaged_time_ms) AS engaged_time_ms')
            ->selectRaw('SUM(scroll_depth_sum) AS scroll_depth_sum')
            ->selectRaw('SUM(scroll_depth_samples) AS scroll_depth_samples')
            ->selectRaw('SUM(completions) AS completions')
            ->selectRaw('SUM(outbound_clicks) AS outbound_clicks')
            ->selectRaw('SUM(social_shares) AS social_shares')
            ->selectRaw('SUM(return_visitors) AS return_visitors')
            ->selectRaw('COUNT(DISTINCT article_id) AS viewed_articles')
            ->selectRaw('SUM(homepage_clicks) AS homepage_clicks')
            ->selectRaw('SUM(category_clicks) AS category_clicks')
            ->selectRaw('SUM(author_clicks) AS author_clicks')
            ->selectRaw('SUM(search_clicks) AS search_clicks')
            ->selectRaw('SUM(related_clicks) AS related_clicks')
            ->groupBy('author_id')
            ->orderByDesc('page_views')
            ->get()
            ->keyBy('author_id');
        $publicationWindows = $this->authorPublicationWindowMetrics($articleIds, $start, $end, $filters);
        $authorIds = $rows->keys()
            ->merge($publicationWindows->keys())
            ->map(fn ($authorId): int => (int) $authorId)
            ->unique()
            ->values();
        $authors = Author::query()->whereIn('id', $authorIds)->get(['id', 'name', 'slug'])->keyBy('id');

        $periodViews = ArticleView::query()
            ->join('articles', 'articles.id', '=', 'article_views.article_id')
            ->whereIn('article_views.article_id', $articleIds)
            ->whereDate('article_views.viewed_on', '>=', $start->toDateString())
            ->whereDate('article_views.viewed_on', '<=', $end->toDateString());
        $this->applyViewDimensions($periodViews, $filters);
        $periodVisitors = $periodViews
            ->select('articles.author_id')
            ->selectRaw('COUNT(DISTINCT article_views.visitor_hash) AS unique_visitors')
            ->selectRaw('COUNT(DISTINCT CASE WHEN article_views.is_returning = 1 THEN article_views.visitor_hash END) AS return_visitors')
            ->groupBy('articles.author_id')
            ->get()
            ->keyBy('author_id');
        $rawCoverageAvailable = $this->rawCoverageAvailable($start);
        $periodDays = max(1, $start->diffInDays($end) + 1);

        return $authorIds->map(function (int $authorId) use ($rows, $authors, $publicationWindows, $periodVisitors, $rawCoverageAvailable, $periodDays): array {
            $row = $rows->get($authorId);
            $author = $authors->get($authorId);
            $visitorStats = $periodVisitors->get($authorId);
            $publicationWindow = $publicationWindows->get($authorId, []);
            $published = (int) ($publicationWindow['articles_published'] ?? 0);

            return [
                'author_id' => $authorId,
                'name' => $author?->name ?? 'Избришан автор',
                'slug' => $author?->slug,
                'articles_published' => $published,
                'page_views' => (int) ($row?->page_views ?? 0),
                'unique_visitors' => $rawCoverageAvailable ? (int) ($visitorStats?->unique_visitors ?? 0) : null,
                'average_views_per_article' => $this->divide((int) ($row?->page_views ?? 0), max(1, (int) ($row?->viewed_articles ?? 0)), 1),
                'publication_frequency' => round($published / $periodDays, 2),
                'first_24_hour_views' => $publicationWindow['first_24_hour_views'] ?? null,
                'first_7_day_views' => $publicationWindow['first_7_day_views'] ?? null,
                'engaged_time_ms' => (int) ($row?->engaged_time_ms ?? 0),
                'engaged_time_seconds' => round(((int) ($row?->engaged_time_ms ?? 0)) / 1000, 1),
                'average_engaged_seconds' => $this->divide($row?->engaged_time_ms ?? 0, ($row?->engaged_sessions ?? 0) * 1000, 1),
                'average_scroll_depth' => $this->divide($row?->scroll_depth_sum ?? 0, $row?->scroll_depth_samples ?? 0, 1),
                'completion_rate' => $this->percentage($row?->completions ?? 0, $row?->article_opens ?? 0),
                'outbound_clicks' => (int) ($row?->outbound_clicks ?? 0),
                'social_shares' => (int) ($row?->social_shares ?? 0),
                'return_visitors' => $rawCoverageAvailable ? (int) ($visitorStats?->return_visitors ?? 0) : null,
                'homepage_clicks' => (int) ($row?->homepage_clicks ?? 0),
                'category_clicks' => (int) ($row?->category_clicks ?? 0),
                'author_clicks' => (int) ($row?->author_clicks ?? 0),
                'search_clicks' => (int) ($row?->search_clicks ?? 0),
                'related_clicks' => (int) ($row?->related_clicks ?? 0),
            ];
        })->sortByDesc('page_views')->values();
    }

    /**
     * The selected period defines the publication cohort. Each count then
     * follows that article from its own publication timestamp for 24 hours or
     * seven days. This keeps launch performance meaningful without clipping a
     * window at the report boundary.
     *
     * Raw ArticleView rows are the only trustworthy source for timestamped
     * publication windows. If any article in an author's cohort predates raw
     * retention, the aggregate is unavailable rather than a misleading
     * partial total.
     *
     * @param  Collection<int, int>  $articleIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{articles_published: int, first_24_hour_views: int|null, first_7_day_views: int|null}>
     */
    private function authorPublicationWindowMetrics(Collection $articleIds, Carbon $start, Carbon $end, array $filters): Collection
    {
        $cohort = Article::query()
            ->whereIn('id', $articleIds)
            ->whereNotNull('author_id')
            ->whereBetween('published_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->get(['id', 'author_id', 'published_at']);

        if ($cohort->isEmpty()) {
            return collect();
        }

        $rawRetentionStart = now()->subDays(max(
            30,
            (int) config('editorial_analytics.raw_event_retention_days', 395),
        ));
        $eligible = $cohort->filter(
            fn (Article $article): bool => $article->published_at !== null && $article->published_at->gte($rawRetentionStart),
        );
        $eligibleIds = $eligible->pluck('id');
        $first24ByAuthor = collect();
        $first7ByAuthor = collect();

        if ($eligibleIds->isNotEmpty()) {
            $first24ByAuthor = $this->publicationWindowViewCounts($eligibleIds, $filters, 24);
            $first7ByAuthor = $this->publicationWindowViewCounts($eligibleIds, $filters, 24 * 7);
        }

        $eligibleCounts = $eligible->countBy('author_id');

        return $cohort
            ->groupBy('author_id')
            ->map(function (Collection $articles, int|string $authorId) use ($eligibleCounts, $first24ByAuthor, $first7ByAuthor): array {
                $articleCount = $articles->count();
                $hasCompleteRawCoverage = (int) $eligibleCounts->get($authorId, 0) === $articleCount;

                return [
                    'articles_published' => $articleCount,
                    'first_24_hour_views' => $hasCompleteRawCoverage ? (int) $first24ByAuthor->get($authorId, 0) : null,
                    'first_7_day_views' => $hasCompleteRawCoverage ? (int) $first7ByAuthor->get($authorId, 0) : null,
                ];
            });
    }

    /**
     * @param  Collection<int, int>  $articleIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, int>
     */
    private function publicationWindowViewCounts(Collection $articleIds, array $filters, int $hours): Collection
    {
        $query = ArticleView::query()
            ->join('articles', 'articles.id', '=', 'article_views.article_id')
            ->whereIn('article_views.article_id', $articleIds)
            ->whereColumn('article_views.occurred_at', '>=', 'articles.published_at')
            ->whereRaw('article_views.occurred_at <= '.$this->publicationWindowEndExpression($hours));
        $this->applyViewDimensions($query, $filters);

        return $query
            ->select('articles.author_id as author_id')
            ->selectRaw('COUNT(*) AS aggregate_views')
            ->groupBy('articles.author_id')
            ->get()
            ->pluck('aggregate_views', 'author_id')
            ->map(fn ($views): int => (int) $views);
    }

    private function publicationWindowEndExpression(int $hours): string
    {
        $driver = ArticleView::query()->getQuery()->getConnection()->getDriverName();

        return match ($driver) {
            'sqlite' => "datetime(articles.published_at, '+{$hours} hours')",
            'pgsql' => "articles.published_at + INTERVAL '{$hours} hours'",
            'sqlsrv' => "DATEADD(hour, {$hours}, articles.published_at)",
            default => "DATE_ADD(articles.published_at, INTERVAL {$hours} HOUR)",
        };
    }

    /**
     * @param  Collection<int, int>  $articleIds
     * @param  array<string, mixed>  $filters
     * @return array<int, array{day: string, value: int|float}>
     */
    private function series(Collection $articleIds, Carbon $start, Carbon $end, array $filters): array
    {
        $metric = array_key_exists((string) $filters['metric'], self::SERIES_METRICS)
            ? (string) $filters['metric']
            : 'page_views';
        if ($metric === 'unique_visitors') {
            if ($this->rawCoverageAvailable($start)) {
                $periodViews = ArticleView::query()
                    ->whereIn('article_id', $articleIds)
                    ->whereDate('viewed_on', '>=', $start->toDateString())
                    ->whereDate('viewed_on', '<=', $end->toDateString());
                $this->applyViewDimensions($periodViews, $filters);
                $rows = $periodViews
                    ->selectRaw('DATE(viewed_on) AS view_day')
                    ->selectRaw('COUNT(DISTINCT visitor_hash) AS metric_value')
                    ->groupByRaw('DATE(viewed_on)')
                    ->orderByRaw('DATE(viewed_on)')
                    ->pluck('metric_value', 'view_day');
            } else {
                // Daily rows are split by source and device. Summing their
                // distinct counts would overstate a reader who changes either
                // dimension, so hide this series after raw retention expires.
                $rows = collect();
            }
        } else {
            $rows = $this->dailyQuery($articleIds, $start, $end, $filters)
                ->select('day')
                ->selectRaw("SUM({$metric}) AS metric_value")
                ->groupBy('day')
                ->orderBy('day')
                ->pluck('metric_value', 'day');
        }

        if ($rows->isEmpty() && in_array($metric, ['page_views', 'unique_visitors', 'article_opens'], true)) {
            $legacy = ArticleView::query()
                ->whereIn('article_id', $articleIds)
                ->whereDate('viewed_on', '>=', $start->toDateString())
                ->whereDate('viewed_on', '<=', $end->toDateString());
            $this->applyViewDimensions($legacy, $filters);
            $rows = $legacy
                ->selectRaw('DATE(viewed_on) AS view_day')
                ->selectRaw($metric === 'unique_visitors' ? 'COUNT(DISTINCT visitor_hash) AS metric_value' : 'COUNT(*) AS metric_value')
                ->groupByRaw('DATE(viewed_on)')
                ->orderByRaw('DATE(viewed_on)')
                ->pluck('metric_value', 'view_day');
        }

        $series = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $series[] = ['day' => $day->toDateString(), 'value' => (int) ($rows[$day->toDateString()] ?? 0)];
        }

        return $series;
    }

    /**
     * @param  Collection<int, int>  $articleIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $labels
     * @return array<int, array{key: string, label: string, value: int}>
     */
    private function dimensionBreakdown(Collection $articleIds, Carbon $start, Carbon $end, array $filters, string $dimension, array $labels): array
    {
        $rows = $this->dailyQuery($articleIds, $start, $end, [
            ...$filters,
            $dimension === 'source_channel' ? 'source' : 'device' => 'all',
        ])->select($dimension)
            ->selectRaw('SUM(page_views) AS value')
            ->groupBy($dimension)
            ->orderByDesc('value')
            ->get();

        return $rows->map(fn ($row): array => [
            'key' => (string) ($row->{$dimension} ?: 'unknown'),
            'label' => $labels[(string) ($row->{$dimension} ?: 'unknown')] ?? Str::headline((string) $row->{$dimension}),
            'value' => (int) $row->value,
        ])->all();
    }

    /** @return array<int, array{category_id: int, name: string, value: int}> */
    private function categoryBreakdown(Collection $articleIds, Carbon $start, Carbon $end, array $filters): array
    {
        $rows = $this->dailyQuery($articleIds, $start, $end, $filters)
            ->whereNotNull('category_id')
            ->select('category_id')
            ->selectRaw('SUM(page_views) AS value')
            ->groupBy('category_id')
            ->orderByDesc('value')
            ->limit(10)
            ->get();
        $categories = Category::query()->whereIn('id', $rows->pluck('category_id'))->pluck('name', 'id');

        return $rows->map(fn ($row): array => [
            'category_id' => (int) $row->category_id,
            'name' => $categories[$row->category_id] ?? 'Избришана рубрика',
            'value' => (int) $row->value,
        ])->all();
    }

    /**
     * @param  Builder<Article>  $articles
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    private function articleDetail(Builder $articles, array $filters, Carbon $start, Carbon $end): ?array
    {
        if (($filters['article_id'] ?? 'all') === 'all') {
            return null;
        }

        $article = (clone $articles)
            ->with(['author:id,name', 'category:id,name', 'supervisingEditor:id,name'])
            ->find((int) $filters['article_id']);
        if (! $article) {
            return null;
        }

        $first24End = $article->published_at?->copy()->addHours(24);
        $first7End = $article->published_at?->copy()->addDays(7);
        $stats = $this->articleAggregates(collect([$article->id]), $start, $end, $filters, 1)->first() ?? [];
        $hasPublicationWindowData = ! $article->published_at
            || $article->published_at->gte(now()->subDays((int) config('editorial_analytics.raw_event_retention_days', 395)));
        $referrers = ArticleView::query()
            ->where('article_id', $article->id)
            ->whereDate('viewed_on', '>=', $start->toDateString())
            ->whereDate('viewed_on', '<=', $end->toDateString())
            ->whereNotNull('referrer_host');
        $this->applyViewDimensions($referrers, $filters);

        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'author' => $article->author?->name,
            'editor' => $article->supervisingEditor?->name,
            'category' => $article->category?->name,
            'type' => $article->type,
            'published_at' => $article->published_at,
            'word_count' => $this->unicodeWordCount((string) $article->body_html),
            'reading_time' => $article->reading_time_minutes,
            'first_24_hour_views' => $hasPublicationWindowData && $article->published_at && $first24End
                ? $article->views()->whereBetween('occurred_at', [$article->published_at, $first24End])->count()
                : null,
            'first_7_day_views' => $hasPublicationWindowData && $article->published_at && $first7End
                ? $article->views()->whereBetween('occurred_at', [$article->published_at, $first7End])->count()
                : null,
            'page_views' => (int) ($stats['page_views'] ?? 0),
            'unique_visitors' => $stats['unique_visitors'] ?? null,
            'average_engaged_seconds' => $stats['average_engaged_seconds'] ?? null,
            'engaged_time_seconds' => $stats['engaged_time_seconds'] ?? null,
            'average_scroll_depth' => $stats['average_scroll_depth'] ?? null,
            'completion_rate' => $stats['completion_rate'] ?? null,
            'outbound_clicks' => (int) ($stats['outbound_clicks'] ?? 0),
            'social_shares' => (int) ($stats['social_shares'] ?? 0),
            'homepage_clicks' => (int) ($stats['homepage_clicks'] ?? 0),
            'category_clicks' => (int) ($stats['category_clicks'] ?? 0),
            'author_clicks' => (int) ($stats['author_clicks'] ?? 0),
            'search_clicks' => (int) ($stats['search_clicks'] ?? 0),
            'related_clicks' => (int) ($stats['related_clicks'] ?? 0),
            'referrers' => $referrers
                ->select('referrer_host')
                ->selectRaw('COUNT(*) AS visits')
                ->groupBy('referrer_host')
                ->orderByDesc('visits')
                ->limit(10)
                ->get()
                ->map(fn ($row): array => ['host' => $row->referrer_host, 'visits' => (int) $row->visits])
                ->all(),
        ];
    }

    /** @return array<int, array{key: string, label: string, value: int}> */
    private function siteInteractions(User $user, Carbon $start, Carbon $end, array $filters): array
    {
        if ($this->scope($user) !== 'editorial' || ! $this->rawCoverageAvailable($start)) {
            return [];
        }

        $labels = [
            'author_profile_click' => 'Отворања на авторски профили',
            'internal_cta_click' => 'Внатрешни повици за акција',
            'newsletter_signup_click' => 'Обиди за пријава на билтен',
            'external_link_click' => 'Надворешни линкови',
            'source_link_click' => 'Линкови кон извори',
        ];
        $query = EditorialAnalyticsEvent::query()
            ->whereIn('event_type', array_keys($labels))
            ->whereDate('event_date', '>=', $start->toDateString())
            ->whereDate('event_date', '<=', $end->toDateString());
        $this->applyEventDimensions($query, $filters);

        return $query->select('event_type')
            ->selectRaw('COUNT(*) AS interactions')
            ->groupBy('event_type')
            ->orderByDesc('interactions')
            ->get()
            ->map(fn ($row): array => [
                'key' => $row->event_type,
                'label' => $labels[$row->event_type],
                'value' => (int) $row->interactions,
            ])->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $authors
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    private function authorDetail(Collection $authors, array $filters): ?array
    {
        if (($filters['author_id'] ?? 'all') === 'all') {
            return null;
        }

        return $authors->firstWhere('author_id', (int) $filters['author_id']);
    }

    /**
     * @param  Collection<int, int>  $articleIds
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function nearRealtime(Collection $articleIds, array $filters): array
    {
        $query = EditorialAnalyticsEvent::query()
            ->whereIn('article_id', $articleIds)
            ->where('event_type', 'article_open')
            ->where('occurred_at', '>=', now()->subMinutes(5));
        $this->applyEventDimensions($query, $filters);

        return [
            'active_readers' => (clone $query)->distinct()->count('session_hash'),
            'articles' => (clone $query)
                ->select('article_id')
                ->selectRaw('COUNT(DISTINCT COALESCE(session_hash, visitor_hash)) AS readers')
                ->groupBy('article_id')
                ->orderByDesc('readers')
                ->limit(5)
                ->get()
                ->map(function ($row): array {
                    $article = Article::query()->find($row->article_id, ['id', 'title', 'slug']);

                    return ['title' => $article?->title, 'slug' => $article?->slug, 'readers' => (int) $row->readers];
                })
                ->filter(fn (array $row): bool => filled($row['title']))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, int|null> */
    private function dataQuality(User $user, Collection $articleIds, Carbon $start, Carbon $end): array
    {
        $base = EditorialAnalyticsEvent::query()
            ->whereDate('event_date', '>=', $start->toDateString())
            ->whereDate('event_date', '<=', $end->toDateString());
        $ownHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);

        return [
            'missing_article_events' => $this->scope($user) === 'editorial'
                ? (clone $base)
                    ->whereIn('event_type', ['article_open', 'engaged_session', 'scroll_depth', 'completion'])
                    ->whereNull('article_id')
                    ->count()
                : null,
            'unknown_attribution' => (clone $base)->whereIn('article_id', $articleIds)->where('source_channel', 'unknown')->count(),
            'self_referrals' => $ownHost
                ? (clone $base)->whereIn('article_id', $articleIds)->where('referrer_host', Str::lower($ownHost))->count()
                : 0,
            'impossible_durations' => (clone $base)->whereIn('article_id', $articleIds)->where('engaged_ms', '>', 1800000)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function filterOptions(User $user): array
    {
        $articles = $this->articlesFor($user);
        $authorIds = (clone $articles)->whereNotNull('author_id')->distinct()->pluck('author_id');
        $categoryIds = (clone $articles)->whereNotNull('category_id')->distinct()->pluck('category_id');

        return [
            'date_ranges' => self::DATE_RANGES,
            'metrics' => self::SERIES_METRICS,
            'authors' => Author::query()->whereIn('id', $authorIds)->orderBy('name')->pluck('name', 'id')->all(),
            'categories' => Category::query()->whereIn('id', $categoryIds)->orderBy('name')->pluck('name', 'id')->all(),
            'articles' => (clone $articles)->orderByDesc('published_at')->pluck('title', 'id')->all(),
            'types' => (clone $articles)->whereNotNull('type')->distinct()->orderBy('type')->pluck('type')->mapWithKeys(fn (string $type): array => [$type => Str::headline($type)])->all(),
            'sources' => self::SOURCE_LABELS,
            'devices' => self::DEVICE_LABELS,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Article>
     */
    private function filteredArticles(User $user, array $filters): Builder
    {
        $query = $this->articlesFor($user);

        if (($filters['author_id'] ?? 'all') !== 'all') {
            $query->where('author_id', (int) $filters['author_id']);
        }
        if (($filters['category_id'] ?? 'all') !== 'all') {
            $query->inCategory((int) $filters['category_id']);
        }
        if (($filters['article_id'] ?? 'all') !== 'all') {
            $query->whereKey((int) $filters['article_id']);
        }
        if (($filters['type'] ?? 'all') !== 'all') {
            $query->where('type', (string) $filters['type']);
        }

        return $query;
    }

    private function articlesFor(User $user): Builder
    {
        $query = Article::query()
            ->whereIn('status', ['published', 'archived'])
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());

        if ($user->isAdmin() || $user->hasRole('editor_in_chief')) {
            return $query;
        }

        if ($user->hasRole('editor')) {
            $supervisedAuthorIds = $user->editorialSupervisions()->active()->pluck('author_id');

            return $query->where(function (Builder $query) use ($user, $supervisedAuthorIds): void {
                $query->where('created_by', $user->id)
                    ->orWhere('assigned_editor_id', $user->id)
                    ->orWhere('supervising_editor_id', $user->id)
                    ->orWhereIn('author_id', $supervisedAuthorIds);
            });
        }

        if ($user->hasRole('author') || $user->hasRole('contributor')) {
            return $query->where('author_id', $user->teamMember?->author_id ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }

    /** @return array<string, mixed> */
    private function scopeFingerprint(User $user): array
    {
        return [
            'team_author_id' => $user->teamMember?->author_id,
            'team_updated_at' => $user->teamMember?->updated_at?->getTimestamp(),
            'supervisions' => $user->editorialSupervisions()
                ->active()
                ->orderBy('author_id')
                ->get(['author_id', 'ends_at', 'updated_at'])
                ->map(fn ($row): array => [$row->author_id, $row->ends_at?->getTimestamp(), $row->updated_at?->getTimestamp()])
                ->all(),
        ];
    }

    private function dailyQuery(Collection $articleIds, Carbon $start, Carbon $end, array $filters): Builder
    {
        $query = EditorialAnalyticsDaily::query()
            ->whereIn('article_id', $articleIds)
            ->whereBetween('day', [$start->toDateString(), $end->toDateString()]);

        if (($filters['source'] ?? 'all') !== 'all') {
            $query->where('source_channel', $filters['source']);
        }
        if (($filters['device'] ?? 'all') !== 'all') {
            $query->where('device', $filters['device']);
        }

        return $query;
    }

    private function applyViewDimensions(Builder $query, array $filters): void
    {
        if (($filters['source'] ?? 'all') !== 'all') {
            $query->where('source_channel', $filters['source']);
        }
        if (($filters['device'] ?? 'all') !== 'all') {
            $query->where('device', $filters['device']);
        }
    }

    private function applyEventDimensions(Builder $query, array $filters): void
    {
        if (($filters['source'] ?? 'all') !== 'all') {
            $query->where('source_channel', $filters['source']);
        }
        if (($filters['device'] ?? 'all') !== 'all') {
            $query->where('device', $filters['device']);
        }
    }

    /** @param array<string, mixed> $filters */
    private function dateBounds(array $filters): array
    {
        $today = today();

        return match ($filters['range']) {
            'today' => [$today->copy(), $today->copy()],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last_7_days' => [$today->copy()->subDays(6), $today->copy()],
            'current_month' => [$today->copy()->startOfMonth(), $today->copy()],
            'previous_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'custom' => [Carbon::parse($filters['custom_from'])->startOfDay(), Carbon::parse($filters['custom_to'])->startOfDay()],
            default => [$today->copy()->subDays(29), $today->copy()],
        };
    }

    private function previousDateBounds(Carbon $start, Carbon $end): array
    {
        $days = $start->diffInDays($end) + 1;
        $previousEnd = $start->copy()->subDay();

        return [$previousEnd->copy()->subDays($days - 1), $previousEnd];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $range = array_key_exists((string) ($filters['range'] ?? ''), self::DATE_RANGES)
            ? (string) $filters['range']
            : 'last_30_days';
        $customFrom = $this->safeDate($filters['custom_from'] ?? null, today()->subDays(29));
        $customTo = $this->safeDate($filters['custom_to'] ?? null, today());
        if ($customFrom->gt($customTo)) {
            [$customFrom, $customTo] = [$customTo, $customFrom];
        }

        return [
            'range' => $range,
            'custom_from' => $customFrom->toDateString(),
            'custom_to' => $customTo->toDateString(),
            'compare' => filter_var($filters['compare'] ?? true, FILTER_VALIDATE_BOOL),
            'metric' => array_key_exists((string) ($filters['metric'] ?? ''), self::SERIES_METRICS) ? (string) $filters['metric'] : 'page_views',
            'author_id' => $this->filterId($filters['author_id'] ?? 'all'),
            'category_id' => $this->filterId($filters['category_id'] ?? 'all'),
            'article_id' => $this->filterId($filters['article_id'] ?? 'all'),
            'type' => filled($filters['type'] ?? null) ? (string) $filters['type'] : 'all',
            'source' => array_key_exists((string) ($filters['source'] ?? ''), self::SOURCE_LABELS) ? (string) $filters['source'] : 'all',
            'device' => array_key_exists((string) ($filters['device'] ?? ''), self::DEVICE_LABELS) ? (string) $filters['device'] : 'all',
        ];
    }

    private function safeDate(mixed $value, Carbon $fallback): Carbon
    {
        try {
            return filled($value) ? Carbon::parse((string) $value)->startOfDay() : $fallback->copy()->startOfDay();
        } catch (\Throwable) {
            return $fallback->copy()->startOfDay();
        }
    }

    private function filterId(mixed $value): string|int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 'all';
    }

    private function scope(User $user): string
    {
        return match (true) {
            $user->isAdmin(), $user->hasRole('editor_in_chief') => 'editorial',
            $user->hasRole('editor') => 'supervised',
            default => 'author',
        };
    }

    private function scopeLabel(User $user): string
    {
        return match ($this->scope($user)) {
            'editorial' => 'Цела редакција',
            'supervised' => 'Ваши објави и автори што ги надгледувате',
            default => 'Само ваши објави',
        };
    }

    private function divide(int|float $numerator, int|float $denominator, int $precision = 1): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, $precision) : null;
    }

    private function percentage(int|float $numerator, int|float $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : null;
    }

    private function unicodeWordCount(string $html): int
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[’\'-][\p{L}\p{N}]+)*/u', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches);

        return count($matches[0]);
    }

    private function rawCoverageAvailable(Carbon $start): bool
    {
        return $start->gte(now()->subDays((int) config('editorial_analytics.raw_event_retention_days', 395))->startOfDay());
    }
}
