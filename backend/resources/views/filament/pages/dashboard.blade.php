<x-filament-panels::page>
    @php
        $roleLabels = [
            'administrator' => 'Администратор',
            'editor_in_chief' => 'Главен и одговорен уредник',
            'editor' => 'Уредник',
            'author' => 'Автор',
            'staff' => 'Член на редакцијата',
        ];
        $authorDashboard = $workspaceRole === 'author';
        $metricLabels = $authorDashboard ? [
            'drafts' => ['Работни верзии', 'Ваши објави што сè уште ги подготвувате'],
            'submitted' => ['Испратени', 'Ваши објави испратени до редакцијата'],
            'changes_requested' => ['Потребна доработка', 'Ваши објави што треба да се доработат'],
            'approved' => ['Одобрени', 'Ваши објави подготвени за закажување'],
            'scheduled' => ['Закажани', 'Ваши објави со термин за објавување'],
            'published' => ['Објавени', 'Сите ваши објавени текстови'],
        ] : [
            'needs_approval' => ['Чекаат одобрување', 'Поднесени објави за ваша одлука'],
            'changes_requested' => ['Потребна доработка', 'Објави што треба да се доработат'],
            'in_review' => ['Во преглед', 'Активни уреднички прегледи'],
            'scheduled_today' => ['Закажани денес', 'Подготвени за денешно објавување'],
            'published_today' => ['Објавени денес', 'Текстови објавени во текот на денот'],
            'failed_publications' => ['Неуспешни објавувања', 'Објави што бараат итна проверка'],
        ];
        $metricIcons = $authorDashboard ? [
            'drafts' => 'heroicon-o-document-text',
            'submitted' => 'heroicon-o-paper-airplane',
            'changes_requested' => 'heroicon-o-arrow-uturn-left',
            'approved' => 'heroicon-o-check-badge',
            'scheduled' => 'heroicon-o-calendar-days',
            'published' => 'heroicon-o-check-circle',
        ] : [
            'needs_approval' => 'heroicon-o-clipboard-document-check',
            'changes_requested' => 'heroicon-o-arrow-uturn-left',
            'in_review' => 'heroicon-o-document-magnifying-glass',
            'scheduled_today' => 'heroicon-o-calendar-days',
            'published_today' => 'heroicon-o-check-circle',
            'failed_publications' => 'heroicon-o-exclamation-triangle',
        ];
        $statusLabels = [
            'draft' => 'Работна верзија', 'submitted' => 'Испратена на проверка', 'in_review' => 'Во преглед',
            'changes_requested' => 'Потребна доработка', 'approved' => 'Одобрена',
            'scheduled' => 'Закажана', 'published' => 'Објавена', 'archived' => 'Архивирана',
        ];
    @endphp

    <div class="space-y-6" data-testid="newsroom-dashboard">
        <section class="overflow-hidden rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--razbudise-accent-deep)]">{{ $roleLabels[$workspaceRole] ?? 'Редакција' }}</p>
                    <h2 class="mt-2 text-2xl font-bold text-[var(--razbudise-ink)] sm:text-3xl">Добредојде, {{ auth()->user()->name }}</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-[var(--razbudise-muted)] sm:text-base">
                        @if ($workspaceRole === 'author')
                            Овде се вашите работни верзии, уреднички забелешки и денешни објави.
                        @elseif ($workspaceRole === 'editor')
                            Следете ги авторите што ги уредувате и решете ги најважните прегледи прво.
                        @else
                            Еве што бара внимание во редакцијата денес.
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    @if (auth()->user()->hasPermission('articles.create'))
                        <x-filament::button tag="a" href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('create') }}" icon="heroicon-o-plus" class="min-h-11">
                            Нова објава
                        </x-filament::button>
                    @endif
                    <x-filament::button tag="a" href="{{ \App\Filament\Pages\NewsroomManagedPosts::getUrl() }}" color="gray" outlined icon="heroicon-o-document-text" class="min-h-11">
                        Отвори ги објавите
                    </x-filament::button>
                </div>
            </div>
        </section>

        <section aria-labelledby="newsroom-metrics-heading">
            <div class="mb-3 flex items-end justify-between gap-4">
                <div>
                    <h2 id="newsroom-metrics-heading" class="text-lg font-bold text-[var(--razbudise-ink)]">
                        {{ $authorDashboard ? 'Мои објави' : ($workspaceRole === 'editor' ? 'Мој уреднички опсег' : 'Денес во редакцијата') }}
                    </h2>
                    <p class="mt-1 text-sm text-[var(--razbudise-muted)]">Бројки што водат кон конкретна следна акција.</p>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
                @foreach ($metrics as $metricKey => $metric)
                    @php
                        $key = is_string($metricKey) ? $metricKey : data_get($metric, 'key', '');
                        $count = (int) data_get($metric, 'count', 0);
                        $delta = data_get($metric, 'delta');
                    @endphp
                    <a
                        href="{{ $metricUrls[$key] ?? \App\Filament\Pages\NewsroomManagedPosts::getUrl() }}"
                        aria-label="{{ data_get($metric, 'label', $metricLabels[$key][0] ?? $key) }}: {{ number_format($count) }}"
                        data-metric-key="{{ $key }}"
                        class="group min-h-36 rounded-xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-4 outline-none transition hover:border-[var(--razbudise-accent)] focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-3xl font-bold tabular-nums text-[var(--razbudise-ink)]">{{ number_format($count) }}</span>
                            <span class="flex size-9 items-center justify-center rounded-full bg-[var(--razbudise-soft)] text-[var(--razbudise-accent-deep)]" aria-hidden="true">
                                <x-dynamic-component :component="$metricIcons[$key] ?? 'heroicon-o-chart-bar'" class="size-5" />
                            </span>
                        </div>
                        <h3 class="mt-4 text-sm font-semibold text-[var(--razbudise-ink)] group-hover:text-[var(--razbudise-accent-deep)]">{{ data_get($metric, 'label', $metricLabels[$key][0] ?? $key) }}</h3>
                        <p class="mt-1 text-xs leading-5 text-[var(--razbudise-muted)]">{{ data_get($metric, 'description', $metricLabels[$key][1] ?? '') }}</p>
                        @if ($delta !== null)
                            <p class="mt-2 text-xs font-semibold text-[var(--razbudise-accent-deep)]">
                                {{ $delta > 0 ? '+' : '' }}{{ $delta }} од вчера
                            </p>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>

        @if ($authorDashboard && collect($requestedChanges)->isNotEmpty())
            <section
                class="overflow-hidden rounded-2xl border border-orange-200 bg-[var(--razbudise-surface)] dark:border-orange-800"
                aria-labelledby="requested-changes-heading"
                data-testid="author-requested-changes"
            >
                <div class="flex items-start gap-3 border-b border-orange-200 bg-orange-50 px-5 py-4 dark:border-orange-800 dark:bg-orange-950/35">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-200" aria-hidden="true">
                        <x-heroicon-o-arrow-uturn-left class="size-5" />
                    </span>
                    <div>
                        <h2 id="requested-changes-heading" class="font-bold text-[var(--razbudise-ink)]">Потребна доработка</h2>
                        <p class="mt-1 text-sm leading-5 text-[var(--razbudise-muted)]">Отворете ја објавата, проверете ги забелешките и испратете ја повторно на проверка.</p>
                    </div>
                </div>

                <div class="grid gap-px bg-[var(--razbudise-line)] md:grid-cols-2">
                    @foreach ($requestedChanges as $requestedChange)
                        @php
                            $article = data_get($requestedChange, 'article');
                            $requester = data_get($requestedChange, 'requester');
                            $requestedAt = data_get($requestedChange, 'requested_at');
                            $deadlineAt = data_get($requestedChange, 'deadline_at');
                            $unresolvedComments = (int) data_get($requestedChange, 'unresolved_comments', 0);
                        @endphp
                        @if ($article)
                            <article
                                class="flex min-w-0 flex-col bg-[var(--razbudise-surface)] p-5"
                                data-requested-change-article="{{ $article->getKey() }}"
                                data-unresolved-comments="{{ $unresolvedComments }}"
                            >
                                <h3 class="line-clamp-2 text-base font-bold leading-6 text-[var(--razbudise-ink)]">{{ $article->title }}</h3>
                                <dl class="mt-4 grid gap-x-5 gap-y-3 text-sm sm:grid-cols-2">
                                    <div class="min-w-0">
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-[var(--razbudise-muted)]">Уредник</dt>
                                        <dd class="mt-1 truncate font-medium text-[var(--razbudise-ink)]">{{ $requester?->name ?? 'Редакција' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-[var(--razbudise-muted)]">Побарано на</dt>
                                        <dd class="mt-1 font-medium tabular-nums text-[var(--razbudise-ink)]">
                                            {{ $requestedAt?->timezone(config('newsroom.timezone', 'Europe/Skopje'))->format('d.m.Y') ?? 'Нема податок' }}
                                        </dd>
                                    </div>
                                    @if ($deadlineAt)
                                        <div>
                                            <dt class="text-xs font-semibold uppercase tracking-wide text-[var(--razbudise-muted)]">Рок</dt>
                                            <dd @class([
                                                'mt-1 font-medium tabular-nums',
                                                'text-danger-700 dark:text-danger-300' => $deadlineAt->isBefore(today(config('newsroom.timezone', 'Europe/Skopje'))),
                                                'text-[var(--razbudise-ink)]' => ! $deadlineAt->isBefore(today(config('newsroom.timezone', 'Europe/Skopje'))),
                                            ])>{{ $deadlineAt->format('d.m.Y') }}</dd>
                                        </div>
                                    @endif
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-[var(--razbudise-muted)]">Нерешени забелешки</dt>
                                        <dd class="mt-1 font-bold tabular-nums text-[var(--razbudise-ink)]">{{ $unresolvedComments }}</dd>
                                    </div>
                                </dl>

                                @can('update', $article)
                                    <div class="mt-auto pt-5">
                                        <x-filament::button
                                            tag="a"
                                            href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('edit', ['record' => $article]) }}"
                                            icon="heroicon-o-pencil-square"
                                            class="min-h-11"
                                        >
                                            Отвори и поправи
                                        </x-filament::button>
                                    </div>
                                @endcan
                            </article>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_minmax(20rem,0.75fr)]">
            <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)]" aria-labelledby="urgent-heading">
                <div class="border-b border-[var(--razbudise-line)] px-5 py-4">
                    <div class="flex items-center gap-3">
                        <span class="flex size-9 items-center justify-center rounded-full bg-orange-50 text-orange-700 dark:bg-orange-950 dark:text-orange-200" aria-hidden="true">
                            <x-heroicon-o-exclamation-triangle class="size-5" />
                        </span>
                        <div>
                            <h2 id="urgent-heading" class="font-bold text-[var(--razbudise-ink)]">Итно внимание</h2>
                            <p class="text-sm text-[var(--razbudise-muted)]">Ризици и блокади што не треба да чекаат.</p>
                        </div>
                    </div>
                </div>
                <div class="divide-y divide-[var(--razbudise-line)]">
                    @forelse ($urgentItems as $item)
                        @php $article = data_get($item, 'article'); @endphp
                        <article class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold {{ data_get($item, 'severity') === 'danger' ? 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-200' : 'bg-orange-50 text-orange-700 dark:bg-orange-950 dark:text-orange-200' }}">
                                        {{ data_get($item, 'blocking') ? 'Блокира' : 'Проверка' }}
                                    </span>
                                    @if (data_get($item, 'age'))<span class="text-xs text-[var(--razbudise-muted)]">{{ data_get($item, 'age') }}</span>@endif
                                </div>
                                <h3 class="mt-2 truncate font-semibold text-[var(--razbudise-ink)]">{{ $article?->title ?? data_get($item, 'reason', 'Редакциска проверка') }}</h3>
                                <p class="mt-1 text-sm text-[var(--razbudise-muted)]">{{ data_get($item, 'reason') }}</p>
                                <p class="mt-2 text-xs text-[var(--razbudise-muted)]">
                                    Автор: {{ data_get($item, 'author.name', 'не е доделен') }}
                                    <span aria-hidden="true"> · </span>
                                    Уредник: {{ data_get($item, 'editor.name', 'не е доделен') }}
                                </p>
                            </div>
                            @if ($article)
                                <x-filament::button tag="a" href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('edit', ['record' => $article]) }}" size="sm" color="gray" outlined class="min-h-11 shrink-0">
                                    Отвори
                                </x-filament::button>
                            @elseif (data_get($item, 'author') && \App\Filament\Pages\NewsroomManagedAuthors::canAccess())
                                <x-filament::button tag="a" href="{{ \App\Filament\Pages\NewsroomManagedAuthors::getUrl().'?q='.urlencode((string) data_get($item, 'author.name')) }}" size="sm" color="gray" outlined class="min-h-11 shrink-0">
                                    Отвори автор
                                </x-filament::button>
                            @endif
                        </article>
                    @empty
                        <div class="p-8 text-center">
                            <x-heroicon-o-check-circle class="mx-auto size-8 text-success-600" />
                            <p class="mt-3 font-semibold text-[var(--razbudise-ink)]">Нема итни блокади.</p>
                            <p class="mt-1 text-sm text-[var(--razbudise-muted)]">Редакцискиот тек е чист во моментов.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            @if (\App\Filament\Pages\NewsroomApprovalQueue::canAccess())
                <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)]" aria-labelledby="approval-preview-heading">
                    <div class="flex items-center justify-between gap-4 border-b border-[var(--razbudise-line)] px-5 py-4">
                        <div>
                            <h2 id="approval-preview-heading" class="font-bold text-[var(--razbudise-ink)]">Следни за одобрување</h2>
                            <p class="text-sm text-[var(--razbudise-muted)]">Најдолго чеканите се први.</p>
                        </div>
                        <a href="{{ \App\Filament\Pages\NewsroomApprovalQueue::getUrl() }}" class="text-sm font-semibold text-[var(--razbudise-accent-deep)] underline decoration-[var(--razbudise-accent)] underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">Сите</a>
                    </div>
                    <div class="divide-y divide-[var(--razbudise-line)]">
                        @forelse ($approvalQueue as $item)
                            @php $article = data_get($item, 'article', is_object($item) ? $item : null); @endphp
                            @if ($article)
                                <a href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('edit', ['record' => $article]) }}" class="block p-4 outline-none hover:bg-[var(--razbudise-soft)] focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--razbudise-accent)]">
                                    <p class="line-clamp-2 text-sm font-semibold text-[var(--razbudise-ink)]">{{ $article->title }}</p>
                                    <div class="mt-2 flex items-center justify-between gap-3 text-xs text-[var(--razbudise-muted)]">
                                        <span>{{ $article->author?->name ?? 'Без автор' }}</span>
                                        <span>{{ data_get($item, 'overdue') ? 'Доцни' : data_get($item, 'waiting_since')?->locale('mk')->diffForHumans() }}</span>
                                    </div>
                                </a>
                            @endif
                        @empty
                            <p class="p-6 text-sm text-[var(--razbudise-muted)]">Нема објави што чекаат одобрување.</p>
                        @endforelse
                    </div>
                </section>
            @endif
        </div>

        <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)]" aria-labelledby="today-heading">
            <div class="border-b border-[var(--razbudise-line)] px-5 py-4">
                <h2 id="today-heading" class="font-bold text-[var(--razbudise-ink)]">Денес</h2>
                <p class="text-sm text-[var(--razbudise-muted)]">Објавување, прегледи и најнови поднесувања.</p>
            </div>
            <div class="grid gap-px bg-[var(--razbudise-line)] sm:grid-cols-2 xl:grid-cols-5">
                @foreach ([
                    'scheduled' => 'Закажани',
                    'published' => 'Објавени',
                    'due_for_review' => 'Рок за преглед',
                    'overdue' => 'Доцнат',
                    'latest_submissions' => 'Најнови поднесувања',
                ] as $todayKey => $todayLabel)
                    @php $todayItems = collect($today[$todayKey] ?? []); @endphp
                    <div class="min-w-0 bg-[var(--razbudise-surface)] p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-[var(--razbudise-ink)]">{{ $todayLabel }}</h3>
                            <span class="text-lg font-bold tabular-nums text-[var(--razbudise-accent-deep)]">{{ $todayItems->count() }}</span>
                        </div>
                        <div class="mt-3 space-y-3">
                            @forelse ($todayItems->take(3) as $article)
                                <a href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('edit', ['record' => $article]) }}" class="block border-t border-[var(--razbudise-line)] pt-3 text-sm font-medium leading-5 text-[var(--razbudise-ink)] outline-none hover:text-[var(--razbudise-accent-deep)] focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">
                                    {{ \Illuminate\Support\Str::limit($article->title, 58) }}
                                </a>
                            @empty
                                <p class="text-xs leading-5 text-[var(--razbudise-muted)]">Нема објави во оваа група.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        @if (in_array($workspaceRole, ['administrator', 'editor_in_chief'], true) && collect($editorWorkload)->isNotEmpty())
            <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)]" aria-labelledby="editor-workload-heading" data-testid="editor-workload">
                <div class="border-b border-[var(--razbudise-line)] px-5 py-4">
                    <h2 id="editor-workload-heading" class="font-bold text-[var(--razbudise-ink)]">Уреднички оптоварувања</h2>
                    <p class="text-sm text-[var(--razbudise-muted)]">Оперативен преглед за распределба на работа. Податоците не се рангирање на уредниците.</p>
                </div>
                <div class="grid gap-px bg-[var(--razbudise-line)] lg:grid-cols-2 xl:grid-cols-3">
                    @foreach ($editorWorkload as $workload)
                        @php $editor = data_get($workload, 'editor'); @endphp
                        <article class="bg-[var(--razbudise-surface)] p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="truncate font-bold text-[var(--razbudise-ink)]">{{ $editor?->name ?? 'Уредник' }}</h3>
                                    <p class="mt-1 text-xs text-[var(--razbudise-muted)]">{{ data_get($workload, 'authors_managed', 0) }} автори во активен опсег</p>
                                </div>
                                @if (data_get($workload, 'posts_overdue', 0) > 0)
                                    <span class="rounded-full bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 dark:bg-orange-950 dark:text-orange-200">{{ data_get($workload, 'posts_overdue') }} доцнат</span>
                                @endif
                            </div>
                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                                <div><dt class="text-xs text-[var(--razbudise-muted)]">Чекаат</dt><dd class="mt-1 text-lg font-bold tabular-nums text-[var(--razbudise-ink)]">{{ data_get($workload, 'posts_waiting', 0) }}</dd></div>
                                <div><dt class="text-xs text-[var(--razbudise-muted)]">Во преглед</dt><dd class="mt-1 text-lg font-bold tabular-nums text-[var(--razbudise-ink)]">{{ data_get($workload, 'posts_in_review', 0) }}</dd></div>
                                <div><dt class="text-xs text-[var(--razbudise-muted)]">Одобрени</dt><dd class="mt-1 text-lg font-bold tabular-nums text-[var(--razbudise-ink)]">{{ data_get($workload, 'approved_this_week', 0) }}</dd></div>
                                <div><dt class="text-xs text-[var(--razbudise-muted)]">Просек</dt><dd class="mt-1 text-lg font-bold tabular-nums text-[var(--razbudise-ink)]">{{ data_get($workload, 'avg_review_hours') !== null ? number_format((float) data_get($workload, 'avg_review_hours'), 1).' ч.' : 'нема' }}</dd></div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            @if ($managedAuthors->isNotEmpty())
                <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)]" aria-labelledby="workload-heading">
                    <div class="flex items-center justify-between gap-4 border-b border-[var(--razbudise-line)] px-5 py-4">
                        <div>
                            <h2 id="workload-heading" class="font-bold text-[var(--razbudise-ink)]">Авторски тек</h2>
                            <p class="text-sm text-[var(--razbudise-muted)]">Работна состојба без рангирање на луѓето.</p>
                        </div>
                        @if (\App\Filament\Pages\NewsroomManagedAuthors::canAccess())
                            <a href="{{ \App\Filament\Pages\NewsroomManagedAuthors::getUrl() }}" class="text-sm font-semibold text-[var(--razbudise-accent-deep)] underline decoration-[var(--razbudise-accent)] underline-offset-4">Сите</a>
                        @endif
                    </div>
                    <div class="divide-y divide-[var(--razbudise-line)]">
                        @foreach ($managedAuthors as $author)
                            <div class="flex items-center justify-between gap-4 px-5 py-4">
                                <div class="min-w-0">
                                    <p class="truncate font-semibold text-[var(--razbudise-ink)]">{{ $author->name }}</p>
                                    <p class="mt-1 text-xs text-[var(--razbudise-muted)]">Последна активност: {{ $author->last_activity_at ? \Illuminate\Support\Carbon::parse($author->last_activity_at)->locale('mk')->diffForHumans() : 'нема' }}</p>
                                </div>
                                <div class="flex shrink-0 gap-3 text-center">
                                    <span><b class="block text-lg text-[var(--razbudise-ink)]">{{ $author->pending_approval_count ?? 0 }}</b><small class="text-[var(--razbudise-muted)]">чекаат</small></span>
                                    <span><b class="block text-lg text-[var(--razbudise-ink)]">{{ $author->changes_requested_count ?? 0 }}</b><small class="text-[var(--razbudise-muted)]">измени</small></span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if (\App\Filament\Pages\NewsroomActivity::canAccess())
                <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)]" aria-labelledby="activity-preview-heading">
                    <div class="flex items-center justify-between gap-4 border-b border-[var(--razbudise-line)] px-5 py-4">
                        <div>
                            <h2 id="activity-preview-heading" class="font-bold text-[var(--razbudise-ink)]">Последна активност</h2>
                            <p class="text-sm text-[var(--razbudise-muted)]">Најнови промени во вашиот опсег.</p>
                        </div>
                        <a href="{{ \App\Filament\Pages\NewsroomActivity::getUrl() }}" class="text-sm font-semibold text-[var(--razbudise-accent-deep)] underline decoration-[var(--razbudise-accent)] underline-offset-4">Сите</a>
                    </div>
                    <ol class="divide-y divide-[var(--razbudise-line)]">
                        @forelse ($recentActivity as $log)
                            @php
                                $activityItem = app(\App\Services\Newsroom\NewsroomActivityPresenter::class)
                                    ->present($log, auth()->user());
                            @endphp
                            <li class="flex gap-3 p-4">
                                <span class="mt-1 size-2 shrink-0 rounded-full bg-[var(--razbudise-accent)]" aria-hidden="true"></span>
                                <div class="min-w-0">
                                    <p class="text-sm text-[var(--razbudise-ink)]"><strong>{{ $log->actor?->name ?? 'Системата' }}</strong> · {{ $activityItem['label'] }}</p>
                                    @if ($activityItem['subject'])
                                        @if ($activityItem['url'])
                                            <a href="{{ $activityItem['url'] }}" class="mt-1 block truncate rounded text-sm font-medium text-[var(--razbudise-ink)] outline-none hover:text-[var(--razbudise-accent-deep)] focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">{{ $activityItem['subject'] }}</a>
                                        @else
                                            <p class="mt-1 truncate text-sm font-medium text-[var(--razbudise-ink)]">{{ $activityItem['subject'] }}</p>
                                        @endif
                                    @endif
                                    <time class="mt-1 block text-xs text-[var(--razbudise-muted)]" datetime="{{ $log->created_at->toAtomString() }}">{{ $log->created_at->locale('mk')->diffForHumans() }}</time>
                                </div>
                            </li>
                        @empty
                            <li class="p-6 text-sm text-[var(--razbudise-muted)]">Нема неодамнешна активност.</li>
                        @endforelse
                    </ol>
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
