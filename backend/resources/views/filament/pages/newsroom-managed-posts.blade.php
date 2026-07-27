<x-filament-panels::page>
    @php
        $statusLabels = [
            'draft' => 'Работна верзија', 'submitted' => 'Поднесена', 'review' => 'Во преглед', 'in_review' => 'Во преглед',
            'changes_requested' => 'Потребна доработка', 'approved' => 'Одобрена',
            'scheduled' => 'Закажана', 'published' => 'Објавена', 'archived' => 'Архивирана',
        ];
        $statusStyles = [
            'draft' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200',
            'submitted' => 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-200',
            'review' => 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-200',
            'in_review' => 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-200',
            'changes_requested' => 'bg-orange-50 text-orange-700 dark:bg-orange-950 dark:text-orange-200',
            'approved' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200',
            'scheduled' => 'bg-cyan-50 text-cyan-700 dark:bg-cyan-950 dark:text-cyan-200',
            'published' => 'bg-success-50 text-success-700 dark:bg-success-950 dark:text-success-200',
            'archived' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
        ];
    @endphp

    <div class="space-y-5" data-testid="newsroom-managed-posts">
        <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--razbudise-accent-deep)]">Работен тек</p>
                    <h2 class="mt-2 text-xl font-bold text-[var(--razbudise-ink)] sm:text-2xl">{{ method_exists($posts, 'total') ? $posts->total() : $posts->count() }} објави во избраниот преглед</h2>
                    <p class="mt-2 text-sm leading-6 text-[var(--razbudise-muted)]">Следете ја состојбата по автор и отворете ја објавата директно во уредничкиот простор.</p>
                </div>
                @if (auth()->user()->hasPermission('articles.create'))
                    <x-filament::button tag="a" href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('create') }}" icon="heroicon-o-plus" class="min-h-11">
                        Нова објава
                    </x-filament::button>
                @endif
            </div>
        </section>

        <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-4" aria-label="Филтри за објави">
            @if ($author !== 'all' && \App\Filament\Pages\NewsroomManagedAuthors::canAccess())
                <nav class="mb-3 flex items-center gap-2 text-sm" aria-label="Патека назад кон авторите">
                    <a href="{{ $authorsReturnUrl }}" class="font-semibold text-[var(--razbudise-accent-deep)] underline decoration-[var(--razbudise-accent)] underline-offset-4">Автори</a>
                    <x-heroicon-o-chevron-right class="size-4 text-[var(--razbudise-muted)]" aria-hidden="true" />
                    <span class="text-[var(--razbudise-muted)]">Филтрирани објави</span>
                </nav>
            @endif
            <div class="flex gap-2 overflow-x-auto pb-2" role="tablist" aria-label="Состојба на објавите">
                @foreach ($statusOptions as $optionValue => $optionLabel)
                    <button type="button" wire:click="$set('status', '{{ $optionValue }}')" class="min-h-11 shrink-0 rounded-lg border px-4 text-sm font-semibold outline-none transition focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)] {{ $status === $optionValue ? 'border-[var(--razbudise-accent)] bg-[var(--razbudise-soft)] text-[var(--razbudise-accent-deep)]' : 'border-[var(--razbudise-line)] text-[var(--razbudise-muted)] hover:text-[var(--razbudise-ink)]' }}" role="tab" aria-selected="{{ $status === $optionValue ? 'true' : 'false' }}">
                        {{ $optionLabel }}
                    </button>
                @endforeach
            </div>
            @if ($authors->count() > 1)
                <div class="mt-3 max-w-sm">
                    <label for="managed-post-author" class="mb-1.5 block text-sm font-semibold text-[var(--razbudise-ink)]">Автор</label>
                    <select id="managed-post-author" wire:model.live="author" class="min-h-11 w-full rounded-lg border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] px-3 text-sm text-[var(--razbudise-ink)] focus:border-[var(--razbudise-accent)] focus:ring-[var(--razbudise-accent)]">
                        <option value="all">Сите автори</option>
                        @foreach ($authors as $authorOption)
                            <option value="{{ $authorOption->id }}">{{ $authorOption->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </section>

        <div class="space-y-5">
            @forelse ($groups as $authorName => $authorPosts)
                @php
                    $groupAuthor = $authorPosts->first()?->author;
                    $workload = $groupAuthor ? $authorWorkloads->get($groupAuthor->id) : null;
                @endphp
                <section class="overflow-hidden rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)]" aria-labelledby="author-group-{{ $groupAuthor?->id ?? md5($authorName) }}">
                    <div class="flex flex-col gap-3 border-b border-[var(--razbudise-line)] bg-[var(--razbudise-soft)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 id="author-group-{{ $groupAuthor?->id ?? md5($authorName) }}" class="font-bold text-[var(--razbudise-ink)]">{{ $authorName }}</h2>
                            <p class="mt-1 text-xs text-[var(--razbudise-muted)]">{{ $authorPosts->count() }} објави во овој преглед</p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            @if ($workload)
                                <span class="rounded-full bg-[var(--razbudise-surface)] px-2.5 py-1 text-[var(--razbudise-muted)]"><strong class="text-[var(--razbudise-ink)]">{{ $workload->pending_approval_count ?? 0 }}</strong> чекаат</span>
                                <span class="rounded-full bg-[var(--razbudise-surface)] px-2.5 py-1 text-[var(--razbudise-muted)]"><strong class="text-[var(--razbudise-ink)]">{{ $workload->changes_requested_count ?? 0 }}</strong> измени</span>
                                <span class="rounded-full bg-[var(--razbudise-surface)] px-2.5 py-1 text-[var(--razbudise-muted)]"><strong class="text-[var(--razbudise-ink)]">{{ $workload->drafts_count ?? 0 }}</strong> работни верзии</span>
                                <span class="rounded-full bg-[var(--razbudise-surface)] px-2.5 py-1 text-[var(--razbudise-muted)]"><strong class="text-[var(--razbudise-ink)]">{{ $workload->published_this_month_count ?? 0 }}</strong> овој месец</span>
                            @endif
                        </div>
                    </div>

                    @if ($groupAuthor)
                        <nav class="flex flex-wrap gap-x-4 gap-y-2 border-b border-[var(--razbudise-line)] px-5 py-3 text-xs font-semibold" aria-label="Врски за {{ $groupAuthor->name }}">
                            <a href="{{ rtrim((string) config('app.frontend_url'), '/').'/profil/'.$groupAuthor->slug }}" target="_blank" rel="noopener noreferrer" class="text-[var(--razbudise-accent-deep)] underline decoration-[var(--razbudise-accent)] underline-offset-4">Јавен профил</a>
                            <a href="{{ \App\Filament\Pages\NewsroomManagedPosts::getUrl().'?author='.$groupAuthor->id }}" class="text-[var(--razbudise-accent-deep)] underline decoration-[var(--razbudise-accent)] underline-offset-4">Сите објави</a>
                            @if (\App\Filament\Pages\NewsroomApprovalQueue::canAccess())
                                <a href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('index', ['filters' => ['editorial_queue' => ['value' => 'needs_approval'], 'author_id' => ['value' => $groupAuthor->id]]]) }}" class="text-[var(--razbudise-accent-deep)] underline decoration-[var(--razbudise-accent)] underline-offset-4">За одобрување</a>
                            @endif
                            @if (\App\Filament\Pages\NewsroomManagedAuthors::canAccess())
                                <a href="{{ \App\Filament\Pages\NewsroomManagedAuthors::getUrl().'?q='.urlencode($groupAuthor->name) }}" class="text-[var(--razbudise-accent-deep)] underline decoration-[var(--razbudise-accent)] underline-offset-4">Работен товар</a>
                            @endif
                        </nav>
                    @endif

                    <div class="divide-y divide-[var(--razbudise-line)]">
                        @foreach ($authorPosts as $article)
                            @php $unresolved = $article->unresolved_comments_count ?? $article->unresolved_editorial_comments_count ?? 0; @endphp
                            <article class="grid gap-4 p-5 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusStyles[$article->status] ?? $statusStyles['draft'] }}">{{ $statusLabels[$article->status] ?? $article->status }}</span>
                                        @if ($article->category)<span class="text-xs text-[var(--razbudise-muted)]">{{ $article->category->name }}</span>@endif
                                        @if ($unresolved > 0)<span class="inline-flex items-center gap-1 text-xs font-semibold text-orange-700 dark:text-orange-200"><x-heroicon-o-chat-bubble-left-right class="size-4" aria-hidden="true" />{{ $unresolved }} нерешени</span>@endif
                                    </div>
                                    <h3 class="mt-2 text-base font-bold leading-snug text-[var(--razbudise-ink)] sm:text-lg">{{ $article->title }}</h3>
                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-[var(--razbudise-muted)]">
                                        @if ($article->type)<span>Тип: {{ trans('admin.article_types.'.$article->type, [], 'mk') }}</span>@endif
                                        <span>Изменета {{ $article->updated_at->locale('mk')->diffForHumans() }}</span>
                                        @if ($article->supervisingEditor)<span>Уредник: {{ $article->supervisingEditor->name }}</span>@endif
                                        @if ($article->submitted_at)<span>Испратена: {{ $article->submitted_at->timezone(config('newsroom.timezone', 'Europe/Skopje'))->format('d.m.Y H:i') }}</span>@endif
                                        @if ($article->scheduled_at)<span>Закажана: {{ $article->scheduled_at->timezone(config('newsroom.timezone', 'Europe/Skopje'))->format('d.m.Y H:i') }}</span>@endif
                                        @if ($article->published_at)<span>Објавена: {{ $article->published_at->timezone(config('newsroom.timezone', 'Europe/Skopje'))->format('d.m.Y H:i') }}</span>@endif
                                        <span>Нерешени коментари: {{ $unresolved }}</span>
                                    </div>
                                    @if ($article->publication_blocker_reason)
                                        <p class="mt-3 flex items-start gap-2 rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-xs font-medium leading-5 text-orange-900 dark:border-orange-800 dark:bg-orange-950 dark:text-orange-100">
                                            <x-heroicon-o-information-circle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                            <span><strong>Зошто не е објавена?</strong> {{ $article->publication_blocker_reason }}</span>
                                        </p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-2 md:justify-end">
                                    <x-filament::button tag="a" href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('edit', ['record' => $article]) }}" size="sm" icon="heroicon-o-pencil-square" class="min-h-11">
                                        Отвори
                                    </x-filament::button>
                                    <x-filament::button tag="a" href="{{ $article->previewUrl() }}" target="_blank" rel="noreferrer" size="sm" color="gray" outlined icon="heroicon-o-arrow-top-right-on-square" class="min-h-11">
                                        Преглед
                                    </x-filament::button>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @empty
                <section class="rounded-2xl border border-dashed border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-10 text-center">
                    <x-heroicon-o-document-magnifying-glass class="mx-auto size-10 text-[var(--razbudise-muted)]" />
                    <h2 class="mt-4 text-lg font-bold text-[var(--razbudise-ink)]">Нема објави за овие филтри.</h2>
                    <p class="mt-2 text-sm text-[var(--razbudise-muted)]">Изберете друга состојба или автор.</p>
                    <button type="button" wire:click="$set('status', 'all')" class="mt-4 min-h-11 rounded-lg border border-[var(--razbudise-line)] px-4 text-sm font-semibold text-[var(--razbudise-ink)] focus-visible:ring-2 focus-visible:ring-[var(--razbudise-accent)]">Прикажи ги сите</button>
                </section>
            @endforelse
        </div>

        @if (method_exists($posts, 'links') && $posts->hasPages())
            <nav aria-label="Страници со објави">
                {{ $posts->onEachSide(1)->links() }}
            </nav>
        @endif
    </div>
</x-filament-panels::page>
