<x-filament-panels::page>
    @php
        $timezone = config('newsroom.timezone', 'Europe/Skopje');
        $days = collect(\Carbon\CarbonPeriod::create($from, '1 day', $to))->map(fn ($day) => $day->timezone($timezone));
        $periodLabel = $mode === 'today'
            ? $from->locale('mk')->translatedFormat('l, d F Y')
            : ($mode === 'month'
                ? $from->copy()->addDays(7)->locale('mk')->translatedFormat('F Y')
                : $from->locale('mk')->translatedFormat('d M').' до '.$to->locale('mk')->translatedFormat('d M Y'));
        $statusLabels = [
            'approved' => 'Одобрена, незакажана', 'scheduled' => 'Закажана',
            'published' => 'Објавена', 'failed' => 'Неуспешно објавување',
        ];
        $editorialWorkflow = app(\App\Services\Articles\ArticleEditorialWorkflow::class);
    @endphp

    <div class="space-y-5" data-testid="newsroom-calendar">
        <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6">
            <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--razbudise-accent-deep)]">План на објавување</p>
                    <h2 class="mt-2 text-xl font-bold text-[var(--razbudise-ink)] sm:text-2xl">{{ $periodLabel }}</h2>
                    <p class="mt-2 text-sm leading-6 text-[var(--razbudise-muted)]">Закажани и одобрени објави, како и неуспешни обиди што бараат интервенција.</p>
                </div>
                <div class="flex flex-wrap gap-2" role="group" aria-label="Избери период">
                    @foreach (['today' => 'Денес', 'week' => 'Недела', 'month' => 'Месец'] as $viewMode => $viewLabel)
                        <button type="button" wire:click="setMode('{{ $viewMode }}')" class="min-h-11 rounded-lg border px-4 text-sm font-semibold outline-none focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)] {{ $mode === $viewMode ? 'border-[var(--razbudise-accent)] bg-[var(--razbudise-soft)] text-[var(--razbudise-accent-deep)]' : 'border-[var(--razbudise-line)] text-[var(--razbudise-muted)]' }}" aria-pressed="{{ $mode === $viewMode ? 'true' : 'false' }}">{{ $viewLabel }}</button>
                    @endforeach
                </div>
            </div>
        </section>

        <nav class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-3" aria-label="Навигација низ календарот">
            <button type="button" wire:click="previousPeriod" class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-[var(--razbudise-line)] px-3 text-sm font-semibold text-[var(--razbudise-ink)] outline-none hover:bg-[var(--razbudise-soft)] focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">
                <x-heroicon-o-chevron-left class="size-4" aria-hidden="true" /> Претходно
            </button>
            <button type="button" wire:click="goToday" class="min-h-11 rounded-lg px-4 text-sm font-semibold text-[var(--razbudise-accent-deep)] underline decoration-[var(--razbudise-accent)] underline-offset-4 outline-none focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">Оди на денес</button>
            <button type="button" wire:click="nextPeriod" class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-[var(--razbudise-line)] px-3 text-sm font-semibold text-[var(--razbudise-ink)] outline-none hover:bg-[var(--razbudise-soft)] focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">
                Следно <x-heroicon-o-chevron-right class="size-4" aria-hidden="true" />
            </button>
        </nav>

        <section class="grid gap-3 {{ $mode === 'today' ? 'grid-cols-1' : 'sm:grid-cols-2 md:grid-cols-4 xl:grid-cols-7' }}" aria-label="Календарски денови">
            @foreach ($days as $day)
                @php
                    $dateKey = $day->toDateString();
                    $dayEvents = collect($eventsByDate[$dateKey] ?? []);
                    $isToday = $dateKey === now($timezone)->toDateString();
                @endphp
                <article class="min-h-40 rounded-xl border {{ $isToday ? 'border-[var(--razbudise-accent)]' : 'border-[var(--razbudise-line)]' }} bg-[var(--razbudise-surface)] p-3" data-testid="calendar-day-{{ $dateKey }}">
                    <header class="flex items-center justify-between gap-2 border-b border-[var(--razbudise-line)] pb-2">
                        <div>
                            <p class="text-xs font-semibold uppercase text-[var(--razbudise-muted)]">{{ $day->locale('mk')->translatedFormat('D') }}</p>
                            <p class="mt-0.5 text-lg font-bold text-[var(--razbudise-ink)]">{{ $day->format('d') }}</p>
                        </div>
                        @if ($isToday)<span class="rounded-full bg-[var(--razbudise-accent)] px-2 py-1 text-[0.65rem] font-semibold text-white">Денес</span>@endif
                    </header>

                    <div class="mt-3 space-y-2">
                        @forelse ($dayEvents as $article)
                            @php
                                $failed = filled($article->publication_failed_at) && $article->status !== 'published';
                                $eventStatus = $failed ? 'failed' : $article->status;
                                $eventTime = $article->scheduled_at?->timezone($timezone)->format('H:i');
                                $responsibleEditor = $article->assignedEditor ?? $article->supervisingEditor;
                                $canSchedule = (auth()->user()->can('schedule', $article))
                                    && $editorialWorkflow->canTransition($article, 'schedule');
                                $canPublish = (auth()->user()->can('publish', $article))
                                    && $editorialWorkflow->canPublish($article, auth()->user());
                                $canAssignEditor = auth()->user()->isAdmin()
                                    || (auth()->user()->hasRole('editor_in_chief') && auth()->user()->hasPermission('editorial.users.manage'));
                            @endphp
                            <article class="rounded-lg border p-3 {{ $failed ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950' : 'border-[var(--razbudise-line)] bg-[var(--razbudise-soft)]' }}" data-testid="calendar-event-{{ $article->id }}">
                                <a href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('edit', ['record' => $article]) }}" class="block rounded outline-none transition focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">
                                    <div class="flex items-center justify-between gap-2 text-[0.68rem] font-semibold">
                                        <span class="{{ $failed ? 'text-red-700 dark:text-red-200' : 'text-[var(--razbudise-accent-deep)]' }}">{{ $statusLabels[$eventStatus] ?? $eventStatus }}</span>
                                        <span class="text-[var(--razbudise-muted)]">
                                            @if ($eventTime)
                                                <time datetime="{{ $article->scheduled_at->toAtomString() }}">{{ $eventTime }}</time>
                                            @else
                                                Нема термин
                                            @endif
                                        </span>
                                    </div>
                                    <h3 class="mt-2 line-clamp-3 text-xs font-semibold leading-5 text-[var(--razbudise-ink)]">{{ $article->title }}</h3>
                                </a>

                                <dl class="mt-2 space-y-1 border-t border-[var(--razbudise-line)] pt-2 text-[0.68rem] text-[var(--razbudise-muted)]">
                                    <div class="flex gap-1"><dt class="sr-only">Автор</dt><dd class="truncate">{{ $article->author?->name ?? 'Без автор' }}</dd></div>
                                    <div class="flex gap-1"><dt class="sr-only">Категорија</dt><dd class="truncate">{{ $article->category?->name ?? 'Без категорија' }}</dd></div>
                                    <div class="flex gap-1"><dt class="sr-only">Уредник</dt><dd class="truncate">{{ $responsibleEditor?->name ?? 'Без доделен уредник' }}</dd></div>
                                </dl>

                                <div class="mt-3 grid gap-1.5 border-t border-[var(--razbudise-line)] pt-3" aria-label="Дејства за {{ $article->title }}">
                                    <a href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('edit', ['record' => $article]) }}" class="inline-flex min-h-11 items-center rounded-md border border-[var(--razbudise-line)] px-2 text-[0.7rem] font-semibold text-[var(--razbudise-ink)] outline-none hover:border-[var(--razbudise-accent)] focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">
                                        Отвори
                                    </a>
                                    @if ($canSchedule)
                                            <button type="button" wire:click="mountAction('reschedule', { article: {{ $article->id }} })" wire:loading.attr="disabled" wire:target="mountAction" class="min-h-11 rounded-md border border-[var(--razbudise-line)] px-2 text-left text-[0.7rem] font-semibold text-[var(--razbudise-ink)] outline-none hover:border-[var(--razbudise-accent)] focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">
                                                {{ $article->scheduled_at ? 'Презакажи' : 'Закажи термин' }}
                                            </button>
                                    @endif
                                    @if ($canPublish)
                                            <button type="button" wire:click="mountAction('publishNow', { article: {{ $article->id }} })" wire:loading.attr="disabled" wire:target="mountAction" class="min-h-11 rounded-md border border-[var(--razbudise-accent)] px-2 text-left text-[0.7rem] font-semibold text-[var(--razbudise-accent-deep)] outline-none hover:bg-[var(--razbudise-surface)] focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">
                                                Објави сега
                                            </button>
                                    @endif
                                    @can('update', $article)
                                        @if ($canAssignEditor)
                                            <button type="button" wire:click="mountAction('assignEditor', { article: {{ $article->id }} })" wire:loading.attr="disabled" wire:target="mountAction" class="min-h-11 rounded-md border border-[var(--razbudise-line)] px-2 text-left text-[0.7rem] font-semibold text-[var(--razbudise-ink)] outline-none hover:border-[var(--razbudise-accent)] focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">
                                                Додели уредник
                                            </button>
                                        @endif
                                    @endcan
                                </div>
                            </article>
                        @empty
                            <p class="py-3 text-xs text-[var(--razbudise-muted)]">Нема планирани објави.</p>
                        @endforelse
                    </div>
                </article>
            @endforeach
        </section>

        <div class="flex flex-wrap gap-4 rounded-xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-4 text-xs text-[var(--razbudise-muted)]" aria-label="Легенда">
            <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-emerald-500" aria-hidden="true"></span>Одобрена или закажана</span>
            <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-red-500" aria-hidden="true"></span>Неуспешно објавување</span>
            <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-full bg-[var(--razbudise-accent)]" aria-hidden="true"></span>Денешен датум</span>
        </div>
    </div>
</x-filament-panels::page>
