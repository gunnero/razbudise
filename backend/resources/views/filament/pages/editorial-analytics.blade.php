@php
    $options = $report['filter_options'] ?? [];
    $series = collect($report['series'] ?? []);
    $seriesMax = max(1, (int) $series->max('value'));
    $seriesCount = max(1, $series->count() - 1);
    $authorRows = collect($report['authors'] ?? []);
    $showAuthorFirst24 = $authorRows->contains(fn (array $author): bool => $author['first_24_hour_views'] !== null);
    $showAuthorFirst7 = $authorRows->contains(fn (array $author): bool => $author['first_7_day_views'] !== null);
    $chartPoints = $series->map(function (array $point, int $index) use ($seriesMax, $seriesCount): string {
        $x = 20 + (($index / $seriesCount) * 760);
        $y = 180 - (((int) $point['value'] / $seriesMax) * 140);
        return round($x, 1).','.round($y, 1);
    })->implode(' ');
    $formatMetric = function (string $key, mixed $value): string {
        if ($value === null) return 'Нема податоци';
        if (in_array($key, ['average_scroll_depth', 'completion_rate'], true)) return number_format((float) $value, 1).'%';
        if (in_array($key, ['average_engaged_seconds', 'engaged_time_seconds'], true)) return number_format((float) $value, 1).' сек.';
        if ($key === 'average_views_per_article') return number_format((float) $value, 1);
        return number_format((int) $value);
    };
@endphp

<x-filament-panels::page>
    <div class="space-y-6" data-testid="editorial-analytics-v2">
        @if (! ($report['has_author_link'] ?? false))
            <div class="rounded-xl border border-warning-300 bg-warning-50 p-5 text-warning-900 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-100">
                Вашата сметка сè уште не е поврзана со авторски профил. Администратор треба да ја поврзе преку Тим / Crew.
            </div>
        @endif

        <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6" aria-labelledby="analytics-scope-heading">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.15em] text-[var(--razbudise-accent-deep)]">Уреднички перформанси</p>
                    <h2 id="analytics-scope-heading" class="mt-2 text-xl font-bold text-[var(--razbudise-ink)]">{{ $report['scope_label'] }}</h2>
                    <p class="mt-1 text-sm text-[var(--razbudise-muted)]">{{ $report['date']['label'] }}</p>
                </div>
                <div class="text-sm text-[var(--razbudise-muted)]">
                    {{ $report['near_realtime_notice'] }}
                    @if ($report['rollup_updated_at'])
                        <span class="block">Последна агрегација: {{ \Illuminate\Support\Carbon::parse($report['rollup_updated_at'])->locale('mk')->diffForHumans() }}</span>
                    @endif
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-4 sm:p-5" aria-label="Филтри за аналитика">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <label class="space-y-1.5">
                    <span class="text-sm font-semibold text-[var(--razbudise-ink)]">Период</span>
                    <select wire:model.live="range" class="min-h-11 w-full rounded-lg border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] text-sm text-[var(--razbudise-ink)]">
                        @foreach (($options['date_ranges'] ?? []) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </label>
                @if ($range === 'custom')
                    <label class="space-y-1.5"><span class="text-sm font-semibold text-[var(--razbudise-ink)]">Од</span><input type="date" wire:model.live="customFrom" class="min-h-11 w-full rounded-lg border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] text-sm text-[var(--razbudise-ink)]"></label>
                    <label class="space-y-1.5"><span class="text-sm font-semibold text-[var(--razbudise-ink)]">До</span><input type="date" wire:model.live="customTo" class="min-h-11 w-full rounded-lg border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] text-sm text-[var(--razbudise-ink)]"></label>
                @endif
                <label class="space-y-1.5"><span class="text-sm font-semibold text-[var(--razbudise-ink)]">Автор</span><select wire:model.live="authorId" class="min-h-11 w-full rounded-lg border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] text-sm text-[var(--razbudise-ink)]"><option value="all">Сите во мојот опсег</option>@foreach (($options['authors'] ?? []) as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
                <label class="space-y-1.5"><span class="text-sm font-semibold text-[var(--razbudise-ink)]">Рубрика</span><select wire:model.live="categoryId" class="min-h-11 w-full rounded-lg border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] text-sm text-[var(--razbudise-ink)]"><option value="all">Сите рубрики</option>@foreach (($options['categories'] ?? []) as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
                <label class="space-y-1.5"><span class="text-sm font-semibold text-[var(--razbudise-ink)]">Објава</span><select wire:model.live="articleId" class="min-h-11 w-full rounded-lg border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] text-sm text-[var(--razbudise-ink)]"><option value="all">Сите објави</option>@foreach (($options['articles'] ?? []) as $id => $label)<option value="{{ $id }}">{{ \Illuminate\Support\Str::limit($label, 70) }}</option>@endforeach</select></label>
                <label class="space-y-1.5"><span class="text-sm font-semibold text-[var(--razbudise-ink)]">Тип</span><select wire:model.live="articleType" class="min-h-11 w-full rounded-lg border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] text-sm text-[var(--razbudise-ink)]"><option value="all">Сите типови</option>@foreach (($options['types'] ?? []) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <label class="space-y-1.5"><span class="text-sm font-semibold text-[var(--razbudise-ink)]">Извор</span><select wire:model.live="source" class="min-h-11 w-full rounded-lg border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] text-sm text-[var(--razbudise-ink)]"><option value="all">Сите извори</option>@foreach (($options['sources'] ?? []) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <label class="space-y-1.5"><span class="text-sm font-semibold text-[var(--razbudise-ink)]">Уред</span><select wire:model.live="device" class="min-h-11 w-full rounded-lg border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] text-sm text-[var(--razbudise-ink)]"><option value="all">Сите уреди</option>@foreach (($options['devices'] ?? []) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-4">
                <label class="flex min-h-11 items-center gap-2 text-sm font-medium text-[var(--razbudise-ink)]"><input type="checkbox" wire:model.live="compare" class="rounded border-[var(--razbudise-line)] text-[var(--razbudise-accent)]"> Спореди со претходниот период</label>
                <button type="button" wire:click="resetFilters" class="min-h-11 rounded-lg border border-[var(--razbudise-line)] px-4 text-sm font-semibold text-[var(--razbudise-ink)] hover:border-[var(--razbudise-accent)]">Исчисти филтри</button>
            </div>
        </section>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach (($report['metric_cards'] ?? []) as $card)
                <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5">
                    <p class="text-sm font-medium text-[var(--razbudise-muted)]">{{ $card['label'] }}</p>
                    <p class="mt-2 text-3xl font-bold tabular-nums text-[var(--razbudise-ink)]">{{ $formatMetric($card['key'], $card['value']) }}</p>
                    @if ($compare)
                        <p class="mt-2 text-xs {{ ($card['absolute'] ?? 0) > 0 ? 'text-success-600' : (($card['absolute'] ?? 0) < 0 ? 'text-danger-600' : 'text-[var(--razbudise-muted)]') }}">
                            @if ($card['percent'] !== null)
                                {{ $card['absolute'] > 0 ? '+' : '' }}{{ number_format($card['percent'], 1) }}% · {{ $card['absolute'] > 0 ? '+' : '' }}{{ number_format($card['absolute'], 1) }}
                            @else
                                Претходно: {{ $formatMetric($card['key'], $card['before']) }}
                            @endif
                        </p>
                    @endif
                </section>
            @endforeach
        </div>

        <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6" aria-labelledby="analytics-trend-heading">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div><p class="text-xs font-semibold uppercase tracking-[0.14em] text-[var(--razbudise-accent-deep)]">Движење</p><h2 id="analytics-trend-heading" class="mt-1 text-lg font-bold text-[var(--razbudise-ink)]">{{ ($options['metrics'] ?? [])[$metric] ?? 'Прегледи' }}</h2></div>
                <select wire:model.live="metric" aria-label="Метрика за графиконот" class="min-h-11 rounded-lg border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] text-sm text-[var(--razbudise-ink)]">@foreach (($options['metrics'] ?? []) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </div>
            @if ($series->sum('value') > 0)
                <div class="mt-5 overflow-hidden" role="img" aria-label="Линиски графикон за {{ ($options['metrics'] ?? [])[$metric] ?? 'избраната метрика' }}">
                    <svg viewBox="0 0 800 200" class="h-52 w-full" preserveAspectRatio="none" aria-hidden="true">
                        <line x1="20" y1="180" x2="780" y2="180" stroke="currentColor" class="text-[var(--razbudise-line)]" />
                        <polyline points="{{ $chartPoints }}" fill="none" stroke="var(--razbudise-accent)" stroke-width="3" vector-effect="non-scaling-stroke" />
                    </svg>
                    <div class="mt-2 flex justify-between text-xs text-[var(--razbudise-muted)]"><span>{{ $series->first()['day'] ?? '' }}</span><span>{{ $series->last()['day'] ?? '' }}</span></div>
                </div>
            @else
                <p class="mt-5 rounded-xl bg-[var(--razbudise-soft)] p-5 text-sm text-[var(--razbudise-muted)]">Сè уште нема измерени податоци за оваа метрика и период.</p>
            @endif
        </section>

        @if ($report['article_detail'])
            <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-[var(--razbudise-accent-deep)]">Детали за објава</p>
                <h2 class="mt-2 text-xl font-bold text-[var(--razbudise-ink)]">{{ $report['article_detail']['title'] }}</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    @if ($report['article_detail']['published_at'])
                        <div><dt class="text-[var(--razbudise-muted)]">Објавено</dt><dd class="mt-1 font-semibold text-[var(--razbudise-ink)]">{{ $report['article_detail']['published_at']->locale('mk')->translatedFormat('j F Y, H:i') }}</dd></div>
                    @endif
                    @foreach (['author' => 'Автор', 'editor' => 'Уредник', 'category' => 'Рубрика', 'type' => 'Тип', 'word_count' => 'Зборови', 'reading_time' => 'Минути читање', 'first_24_hour_views' => 'Први 24 часа', 'first_7_day_views' => 'Први 7 дена'] as $key => $label)
                        @if ($report['article_detail'][$key] !== null)<div><dt class="text-[var(--razbudise-muted)]">{{ $label }}</dt><dd class="mt-1 font-semibold text-[var(--razbudise-ink)]">{{ $report['article_detail'][$key] }}</dd></div>@endif
                    @endforeach
                </dl>
                <dl class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (['page_views' => 'Прегледи', 'unique_visitors' => 'Уникатни читатели', 'engaged_time_seconds' => 'Вкупно активно време', 'average_engaged_seconds' => 'Просечно активно време', 'average_scroll_depth' => 'Просечна длабочина', 'completion_rate' => 'Дочитување', 'outbound_clicks' => 'Надворешни кликови', 'social_shares' => 'Споделувања', 'homepage_clicks' => 'Кликови од почетна', 'category_clicks' => 'Кликови од рубрики', 'author_clicks' => 'Кликови од автор', 'search_clicks' => 'Кликови од пребарување', 'related_clicks' => 'Кликови од поврзани'] as $key => $label)
                        <div class="rounded-xl bg-[var(--razbudise-soft)] p-4"><dt class="text-xs font-medium text-[var(--razbudise-muted)]">{{ $label }}</dt><dd class="mt-1 text-lg font-bold tabular-nums text-[var(--razbudise-ink)]">{{ $formatMetric($key, $report['article_detail'][$key]) }}</dd></div>
                    @endforeach
                </dl>
                @if (! empty($report['article_detail']['referrers']))
                    <div class="mt-6 border-t border-[var(--razbudise-line)] pt-5">
                        <h3 class="text-sm font-bold text-[var(--razbudise-ink)]">Најчести надворешни препораки</h3>
                        <ol class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($report['article_detail']['referrers'] as $referrer)
                                <li class="flex items-center justify-between gap-3 rounded-lg bg-[var(--razbudise-soft)] px-3 py-2 text-sm"><span class="truncate text-[var(--razbudise-muted)]">{{ $referrer['host'] }}</span><strong class="tabular-nums text-[var(--razbudise-ink)]">{{ number_format($referrer['visits']) }}</strong></li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            </section>
        @endif

        <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
            <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6">
                <h2 class="text-lg font-bold text-[var(--razbudise-ink)]">Најуспешни објави</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[780px] text-left text-sm">
                        <thead class="text-xs uppercase text-[var(--razbudise-muted)]"><tr><th class="py-2 pr-4">Објава</th><th class="py-2 pr-4">Автор</th><th class="py-2 pr-4">Прегледи</th><th class="py-2 pr-4">Читатели</th><th class="py-2">Дочитување</th></tr></thead>
                        <tbody class="divide-y divide-[var(--razbudise-line)]">
                            @forelse (($report['top_articles'] ?? []) as $article)
                                <tr><td class="py-3 pr-4"><button type="button" wire:click="$set('articleId', '{{ $article['article_id'] }}')" class="max-w-md text-left font-semibold text-[var(--razbudise-accent-deep)] hover:underline">{{ $article['title'] }}</button></td><td class="py-3 pr-4">{{ $article['author'] ?? '—' }}</td><td class="py-3 pr-4 tabular-nums">{{ number_format($article['page_views']) }}</td><td class="py-3 pr-4 tabular-nums">{{ $article['unique_visitors'] === null ? '—' : number_format($article['unique_visitors']) }}</td><td class="py-3">{{ $article['completion_rate'] === null ? '—' : number_format($article['completion_rate'], 1).'%' }}</td></tr>
                            @empty<tr><td colspan="5" class="py-5 text-[var(--razbudise-muted)]">Нема измерени објави за избраниот период.</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="space-y-6">
                <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5">
                    <h2 class="text-lg font-bold text-[var(--razbudise-ink)]">Читатели во последни 5 минути</h2>
                    <p class="mt-3 text-4xl font-bold tabular-nums text-[var(--razbudise-ink)]">{{ number_format($report['realtime']['active_readers'] ?? 0) }}</p>
                    <p class="mt-2 text-xs text-[var(--razbudise-muted)]">Блиску до реално време, со доцнење до 5 минути.</p>
                    @if (! empty($report['realtime']['articles']))
                        <ol class="mt-4 space-y-2 border-t border-[var(--razbudise-line)] pt-4 text-sm">
                            @foreach ($report['realtime']['articles'] as $article)
                                <li class="flex items-start justify-between gap-3"><span class="line-clamp-2 text-[var(--razbudise-muted)]">{{ $article['title'] }}</span><strong class="shrink-0 tabular-nums text-[var(--razbudise-ink)]">{{ number_format($article['readers']) }}</strong></li>
                            @endforeach
                        </ol>
                    @endif
                </section>
                @foreach ([['title' => 'Извори', 'rows' => $report['sources']], ['title' => 'Уреди', 'rows' => $report['devices']], ['title' => 'Рубрики', 'rows' => $report['categories']]] as $breakdown)
                    <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5">
                        <h2 class="text-lg font-bold text-[var(--razbudise-ink)]">{{ $breakdown['title'] }}</h2>
                        <ol class="mt-4 space-y-3 text-sm">@forelse ($breakdown['rows'] as $row)<li class="flex items-center justify-between gap-3"><span class="text-[var(--razbudise-muted)]">{{ $row['label'] ?? $row['name'] }}</span><strong class="tabular-nums text-[var(--razbudise-ink)]">{{ number_format($row['value']) }}</strong></li>@empty<li class="text-[var(--razbudise-muted)]">Нема податоци.</li>@endforelse</ol>
                    </section>
                @endforeach
            </div>
        </div>

        @if (! empty($report['site_interactions']))
            <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6">
                <h2 class="text-lg font-bold text-[var(--razbudise-ink)]">Интеракции низ порталот</h2>
                <p class="mt-1 text-sm text-[var(--razbudise-muted)]">Уреднички повици и линкови во избраниот период. Овој преглед е достапен само за главен уредник и администратор.</p>
                <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    @foreach ($report['site_interactions'] as $interaction)
                        <div class="rounded-xl bg-[var(--razbudise-soft)] p-4"><dt class="text-xs font-medium text-[var(--razbudise-muted)]">{{ $interaction['label'] }}</dt><dd class="mt-1 text-xl font-bold tabular-nums text-[var(--razbudise-ink)]">{{ number_format($interaction['value']) }}</dd></div>
                    @endforeach
                </dl>
            </section>
        @endif

        @if ($report['author_detail'])
            <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-[var(--razbudise-accent-deep)]">Детали за автор</p>
                <h2 class="mt-2 text-xl font-bold text-[var(--razbudise-ink)]">{{ $report['author_detail']['name'] }}</h2>
                @if ($report['author_detail']['first_24_hour_views'] !== null || $report['author_detail']['first_7_day_views'] !== null)
                    <p class="mt-1 text-sm text-[var(--razbudise-muted)]">Почетните прегледи се вкупно за објавите објавени во избраниот период, измерени од времето на објавување.</p>
                @endif
                <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (['articles_published' => 'Објави во периодот', 'publication_frequency' => 'Објави дневно', 'first_24_hour_views' => 'Прегледи во првите 24 часа', 'first_7_day_views' => 'Прегледи во првите 7 дена', 'page_views' => 'Прегледи', 'unique_visitors' => 'Уникатни читатели', 'average_views_per_article' => 'Просек по објава', 'engaged_time_seconds' => 'Вкупно активно време', 'average_engaged_seconds' => 'Просечно активно време', 'average_scroll_depth' => 'Просечна длабочина', 'completion_rate' => 'Дочитување', 'outbound_clicks' => 'Надворешни кликови', 'social_shares' => 'Споделувања', 'return_visitors' => 'Повратни читатели'] as $key => $label)
                        @if ($report['author_detail'][$key] !== null)
                            <div class="rounded-xl bg-[var(--razbudise-soft)] p-4"><dt class="text-xs font-medium text-[var(--razbudise-muted)]">{{ $label }}</dt><dd class="mt-1 text-lg font-bold tabular-nums text-[var(--razbudise-ink)]">{{ $key === 'publication_frequency' ? number_format((float) $report['author_detail'][$key], 2) : $formatMetric($key, $report['author_detail'][$key]) }}</dd></div>
                        @endif
                    @endforeach
                </dl>
            </section>
        @endif

        @if (($report['authors'] ?? collect())->isNotEmpty())
            <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6">
                <h2 class="text-lg font-bold text-[var(--razbudise-ink)]">Аналитика по автор</h2>
                @if ($showAuthorFirst24 || $showAuthorFirst7)
                    <p class="mt-1 text-sm text-[var(--razbudise-muted)]">Почетните прегледи се мерат за објавите објавени во избраниот период.</p>
                @endif
                <div class="mt-4 overflow-x-auto"><table class="w-full {{ $showAuthorFirst24 || $showAuthorFirst7 ? 'min-w-[1320px]' : 'min-w-[1120px]' }} text-left text-sm"><thead class="text-xs uppercase text-[var(--razbudise-muted)]"><tr><th class="py-2 pr-4">Автор</th><th class="py-2 pr-4">Објави</th>@if ($showAuthorFirst24)<th class="py-2 pr-4">Први 24 ч.</th>@endif @if ($showAuthorFirst7)<th class="py-2 pr-4">Први 7 д.</th>@endif<th class="py-2 pr-4">Прегледи</th><th class="py-2 pr-4">Читатели</th><th class="py-2 pr-4">Просек</th><th class="py-2 pr-4">Активно време</th><th class="py-2 pr-4">Длабочина</th><th class="py-2 pr-4">Дочитување</th><th class="py-2 pr-4">Надворешни</th><th class="py-2">Споделувања</th></tr></thead><tbody class="divide-y divide-[var(--razbudise-line)]">@foreach ($report['authors'] as $author)<tr><td class="py-3 pr-4"><button type="button" wire:click="$set('authorId', '{{ $author['author_id'] }}')" class="font-semibold text-[var(--razbudise-accent-deep)] hover:underline">{{ $author['name'] }}</button></td><td class="py-3 pr-4">{{ $author['articles_published'] }}</td>@if ($showAuthorFirst24)<td class="py-3 pr-4">{{ $author['first_24_hour_views'] === null ? '—' : number_format($author['first_24_hour_views']) }}</td>@endif @if ($showAuthorFirst7)<td class="py-3 pr-4">{{ $author['first_7_day_views'] === null ? '—' : number_format($author['first_7_day_views']) }}</td>@endif<td class="py-3 pr-4">{{ number_format($author['page_views']) }}</td><td class="py-3 pr-4">{{ $author['unique_visitors'] === null ? '—' : number_format($author['unique_visitors']) }}</td><td class="py-3 pr-4">{{ $author['average_views_per_article'] === null ? '—' : number_format($author['average_views_per_article'], 1) }}</td><td class="py-3 pr-4">{{ $author['average_engaged_seconds'] === null ? '—' : number_format($author['average_engaged_seconds'], 1).' сек.' }}</td><td class="py-3 pr-4">{{ $author['average_scroll_depth'] === null ? '—' : number_format($author['average_scroll_depth'], 1).'%' }}</td><td class="py-3 pr-4">{{ $author['completion_rate'] === null ? '—' : number_format($author['completion_rate'], 1).'%' }}</td><td class="py-3 pr-4">{{ number_format($author['outbound_clicks']) }}</td><td class="py-3">{{ number_format($author['social_shares']) }}</td></tr>@endforeach</tbody></table></div>
            </section>
        @endif

        <details class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 text-sm">
            <summary class="cursor-pointer font-semibold text-[var(--razbudise-ink)]">Квалитет и приватност на податоците</summary>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (['missing_article_events' => 'Настани без објава', 'unknown_attribution' => 'Непознат извор', 'self_referrals' => 'Самопрепораки', 'impossible_durations' => 'Невозможни траења'] as $key => $label)<div class="rounded-xl bg-[var(--razbudise-soft)] p-4"><span class="text-[var(--razbudise-muted)]">{{ $label }}</span><strong class="mt-1 block text-lg text-[var(--razbudise-ink)]">{{ ($report['data_quality'][$key] ?? null) === null ? '—' : number_format($report['data_quality'][$key]) }}</strong></div>@endforeach
            </div>
            <p class="mt-4 text-[var(--razbudise-muted)]">Не се чуваат сурови IP адреси. Идентификаторите се хеширани, ботовите и административните прегледи се исклучуваат, а CSV не содржи читателски идентификатори.</p>
        </details>
    </div>
</x-filament-panels::page>
