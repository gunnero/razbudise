<x-filament-panels::page>
    <div class="space-y-5" data-testid="newsroom-activity">
        <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--razbudise-accent-deep)]">Тек на промени</p>
                    <h2 class="mt-2 text-xl font-bold text-[var(--razbudise-ink)] sm:text-2xl">Последни уреднички активности</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-[var(--razbudise-muted)]">Кој направил промена, на која објава и кога. Се прикажуваат само активности во вашиот дозволен опсег.</p>
                </div>
                <div class="w-full sm:w-64">
                    <label for="activity-action" class="mb-1.5 block text-sm font-semibold text-[var(--razbudise-ink)]">Вид на активност</label>
                    <select id="activity-action" wire:model.live="action" class="min-h-11 w-full rounded-lg border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] px-3 text-sm text-[var(--razbudise-ink)] focus:border-[var(--razbudise-accent)] focus:ring-[var(--razbudise-accent)]">
                        <option value="all">Сите активности</option>
                        <option value="submitted">Поднесување</option>
                        <option value="review_started">Преглед</option>
                        <option value="changes_requested">Потребна доработка</option>
                        <option value="approved">Одобрување</option>
                        <option value="scheduled">Закажување</option>
                        <option value="published">Објавување</option>
                    </select>
                </div>
            </div>
        </section>

        <div class="space-y-6">
            @forelse ($activityGroups as $date => $logs)
                <section aria-labelledby="activity-date-{{ $date }}">
                    <div class="mb-2 flex items-center gap-3">
                        <h2 id="activity-date-{{ $date }}" class="text-sm font-bold uppercase tracking-[0.08em] text-[var(--razbudise-muted)]">{{ \Illuminate\Support\Carbon::parse($date)->locale('mk')->translatedFormat('l, d F Y') }}</h2>
                        <span class="h-px flex-1 bg-[var(--razbudise-line)]" aria-hidden="true"></span>
                    </div>
                    <ol class="overflow-hidden rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)]">
                        @foreach ($logs as $log)
                            @php
                                $activityItem = app(\App\Services\Newsroom\NewsroomActivityPresenter::class)
                                    ->present($log, auth()->user());
                            @endphp
                            <li class="flex gap-4 border-b border-[var(--razbudise-line)] p-4 last:border-b-0 sm:p-5">
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[var(--razbudise-soft)] text-[var(--razbudise-accent-deep)]" aria-hidden="true">
                                    <x-dynamic-component :component="$activityItem['icon']" class="size-5" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm leading-6 text-[var(--razbudise-ink)]">
                                        <strong>{{ $log->actor?->name ?? 'Системата' }}</strong>
                                        {{ $activityItem['label'] }}
                                    </p>
                                    @if ($activityItem['subject'])
                                        @if ($activityItem['url'])
                                            <a href="{{ $activityItem['url'] }}" class="mt-1 block truncate font-semibold text-[var(--razbudise-ink)] outline-none hover:text-[var(--razbudise-accent-deep)] focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">{{ $activityItem['subject'] }}</a>
                                        @else
                                            <p class="mt-1 truncate font-semibold text-[var(--razbudise-ink)]">{{ $activityItem['subject'] }}</p>
                                        @endif
                                    @endif
                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--razbudise-muted)]">
                                        <time datetime="{{ $log->created_at->toAtomString() }}">{{ $log->created_at->timezone(config('newsroom.timezone', 'Europe/Skopje'))->format('H:i') }}</time>
                                        <span>{{ $log->created_at->locale('mk')->diffForHumans() }}</span>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @empty
                <section class="rounded-2xl border border-dashed border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-10 text-center">
                    <x-heroicon-o-clock class="mx-auto size-10 text-[var(--razbudise-muted)]" />
                    <h2 class="mt-4 text-lg font-bold text-[var(--razbudise-ink)]">Нема активности во овој преглед.</h2>
                    <p class="mt-2 text-sm text-[var(--razbudise-muted)]">Изберете друг вид активност или проверете подоцна.</p>
                </section>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
