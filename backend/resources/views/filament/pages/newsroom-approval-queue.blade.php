<x-filament-panels::page>
    <div class="space-y-5" data-testid="newsroom-approval-queue">
        <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--razbudise-accent-deep)]">Редакциска одлука</p>
                    <h2 class="mt-2 text-xl font-bold text-[var(--razbudise-ink)] sm:text-2xl">{{ $queue->total() }} објави чекаат преглед</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-[var(--razbudise-muted)]">Најдолго чеканите и задоцнетите објави се прикажани први. Проверете ги блокадите пред одобрување.</p>
                    @if ($queue->total() > 0)
                        <p class="mt-1 text-xs text-[var(--razbudise-muted)]">Прикажани {{ $queue->firstItem() }} до {{ $queue->lastItem() }} од {{ $queue->total() }}.</p>
                    @endif
                </div>
                <x-filament::button tag="a" href="{{ \App\Filament\Pages\NewsroomManagedPosts::getUrl().'?status=in_review' }}" color="gray" outlined icon="heroicon-o-document-magnifying-glass" class="min-h-11">
                    Активни прегледи
                </x-filament::button>
            </div>
        </section>

        <div class="space-y-4" role="list" aria-label="Објави што чекаат одобрување">
            @forelse ($queue as $item)
                @php
                    $article = data_get($item, 'article', is_object($item) ? $item : null);
                    $checklist = collect(data_get($item, 'checklist', []));
                    $blocking = (int) data_get($item, 'blocking_issues', $checklist->where('state', 'blocking')->count());
                    $unresolved = (int) data_get($item, 'unresolved_comments', $article?->unresolved_editorial_comments_count ?? 0);
                    $image = $article?->featuredImage?->admin_preview_url;
                    $authorAvatar = $article?->author
                        ? app(\App\Services\Authors\AuthorAvatarResolver::class)->forAuthor($article->author, request())
                        : null;
                    $editUrl = $article ? \App\Filament\Resources\Articles\ArticleResource::getUrl('edit', ['record' => $article]) : null;
                    $revisionsUrl = $article ? \App\Filament\Resources\Articles\ArticleResource::getUrl('edit', ['record' => $article, 'relation' => '1']).'#relationManagerTabs' : null;
                @endphp
                @continue(! $article)

                <article class="overflow-hidden rounded-2xl border {{ data_get($item, 'overdue') ? 'border-orange-400' : 'border-[var(--razbudise-line)]' }} bg-[var(--razbudise-surface)]" role="listitem" data-testid="approval-card-{{ $article->id }}">
                    <div class="grid lg:grid-cols-[12rem_minmax(0,1fr)_17rem]">
                        <div class="min-h-40 bg-[var(--razbudise-soft)] lg:min-h-full">
                            @if ($image)
                                <img src="{{ $image }}" alt="{{ $article->featuredImage?->alt_text ?: 'Главна слика за '.$article->title }}" class="h-full max-h-56 w-full object-cover lg:max-h-none" loading="lazy" />
                            @else
                                <div class="flex h-full min-h-40 items-center justify-center p-6 text-center text-sm text-[var(--razbudise-muted)]">
                                    <div><x-heroicon-o-photo class="mx-auto size-8" /><span class="mt-2 block">Нема главна слика</span></div>
                                </div>
                            @endif
                        </div>

                        <div class="min-w-0 p-5 sm:p-6">
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                @if (data_get($item, 'overdue'))
                                    <span class="rounded-full bg-orange-50 px-2.5 py-1 font-semibold text-orange-700 dark:bg-orange-950 dark:text-orange-200">Доцни со преглед</span>
                                @else
                                    <span class="rounded-full bg-blue-50 px-2.5 py-1 font-semibold text-blue-700 dark:bg-blue-950 dark:text-blue-200">Чека преглед</span>
                                @endif
                                <span class="text-[var(--razbudise-muted)]">{{ $article->type }}</span>
                                @if ($article->category)<span class="text-[var(--razbudise-muted)]">{{ $article->category->name }}</span>@endif
                            </div>

                            <h2 class="mt-3 text-xl font-bold leading-tight text-[var(--razbudise-ink)] sm:text-2xl">{{ $article->title }}</h2>
                            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm text-[var(--razbudise-muted)]">
                                <span class="inline-flex items-center gap-2">
                                    @if ($authorAvatar)
                                        <img
                                            src="{{ data_get($authorAvatar, 'responsive_urls.thumbnail', data_get($authorAvatar, 'url')) }}"
                                            alt="{{ data_get($authorAvatar, 'alt_text', 'Профилна слика за '.$article->author->name) }}"
                                            class="size-8 shrink-0 rounded-full border border-[var(--razbudise-line)] object-cover"
                                            loading="lazy"
                                        />
                                    @else
                                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)]" aria-hidden="true">
                                            <x-heroicon-o-user class="size-4" />
                                        </span>
                                    @endif
                                    <span>{{ $article->author?->name ?? 'Без автор' }}</span>
                                </span>
                                <span class="inline-flex items-center gap-1.5"><x-heroicon-o-clock class="size-4" aria-hidden="true" />{{ data_get($item, 'reading_time', $article->reading_time_minutes) }} мин. читање</span>
                                <span class="inline-flex items-center gap-1.5"><x-heroicon-o-document-text class="size-4" aria-hidden="true" />{{ number_format((int) data_get($item, 'word_count', 0)) }} зборови</span>
                            </div>

                            <div class="mt-5 grid gap-3 sm:grid-cols-3">
                                @foreach (['seo' => 'SEO', 'images' => 'Слики', 'sources' => 'Извори'] as $readinessKey => $readinessLabel)
                                    @php
                                        $readiness = data_get($item, $readinessKey, []);
                                        $percent = (int) data_get($readiness, 'percent', 0);
                                        $notRequired = $readinessKey === 'sources' && data_get($readiness, 'required') === false;
                                    @endphp
                                    <div class="rounded-lg border border-[var(--razbudise-line)] p-3">
                                        <div class="flex items-center justify-between gap-2 text-xs">
                                            <span class="font-semibold text-[var(--razbudise-ink)]">{{ $readinessLabel }}</span>
                                            <span class="text-[var(--razbudise-muted)]">{{ $notRequired ? 'не е задолжително' : $percent.'%' }}</span>
                                        </div>
                                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--razbudise-soft)]" role="progressbar" aria-label="{{ $readinessLabel }} подготвеност" aria-valuenow="{{ $notRequired ? 100 : $percent }}" aria-valuemin="0" aria-valuemax="100">
                                            <div class="h-full rounded-full {{ $percent === 100 || $notRequired ? 'bg-success-600' : 'bg-[var(--razbudise-accent)]' }}" style="width: {{ $notRequired ? 100 : $percent }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2 text-xs">
                                @if ($blocking > 0)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 font-semibold text-red-700 dark:bg-red-950 dark:text-red-200"><x-heroicon-o-no-symbol class="size-4" aria-hidden="true" />{{ $blocking }} блокади</span>
                                @endif
                                @if ($unresolved > 0)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 font-semibold text-orange-700 dark:bg-orange-950 dark:text-orange-200"><x-heroicon-o-chat-bubble-left-right class="size-4" aria-hidden="true" />{{ $unresolved }} нерешени коментари</span>
                                @endif
                                <span class="inline-flex items-center gap-1 text-[var(--razbudise-muted)]"><x-heroicon-o-calendar class="size-4" aria-hidden="true" />Поднесена {{ data_get($item, 'waiting_since')?->locale('mk')->diffForHumans() ?? $article->submitted_at?->locale('mk')->diffForHumans() }}</span>
                            </div>
                        </div>

                        <aside class="border-t border-[var(--razbudise-line)] bg-[var(--razbudise-soft)] p-5 lg:border-l lg:border-t-0" aria-label="Дејства за {{ $article->title }}">
                            <h3 class="text-sm font-bold text-[var(--razbudise-ink)]">Следна одлука</h3>
                            <p class="mt-1 text-xs leading-5 text-[var(--razbudise-muted)]">Отворете ја целата објава ако треба да проверите содржина, коментари или ревизии.</p>
                            <div class="mt-4 grid gap-2">
                                <x-filament::button tag="a" href="{{ $editUrl }}" icon="heroicon-o-document-magnifying-glass" class="min-h-11 w-full justify-center">
                                    Отвори преглед
                                </x-filament::button>
                                @can('review', $article)
                                    @if ($article->status === 'submitted')
                                        <x-filament::button wire:click="startReview({{ $article->id }})" wire:loading.attr="disabled" wire:target="startReview({{ $article->id }})" color="gray" outlined icon="heroicon-o-eye" class="min-h-11 w-full justify-center">
                                            Започни преглед
                                        </x-filament::button>
                                    @endif
                                @endcan
                                @can('approve', $article)
                                    <x-filament::button wire:click="approve({{ $article->id }})" wire:confirm="Да ја одобриме оваа објава?" wire:loading.attr="disabled" wire:target="approve({{ $article->id }})" color="success" icon="heroicon-o-check" class="min-h-11 w-full justify-center" :disabled="$blocking > 0">
                                        Одобри
                                    </x-filament::button>
                                @endcan
                                @can('requestChanges', $article)
                                    <x-filament::button wire:click="requestChanges({{ $article->id }})" wire:confirm="Да ја вратиме објавата за измени?" wire:loading.attr="disabled" wire:target="requestChanges({{ $article->id }})" color="warning" outlined icon="heroicon-o-arrow-uturn-left" class="min-h-11 w-full justify-center">
                                        Побарај измени
                                    </x-filament::button>
                                @endcan
                            </div>

                            <div class="mt-5 border-t border-[var(--razbudise-line)] pt-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-[var(--razbudise-muted)]">Дополнителни дејства</p>
                                <div class="mt-3 grid gap-2">
                                    @if (auth()->user()?->hasPermission('articles.review') && auth()->user()?->can('update', $article))
                                        {{ ($this->assignEditorAction)(['articleId' => $article->id]) }}
                                    @endif

                                    <x-filament::button tag="a" href="{{ $revisionsUrl }}" color="gray" outlined icon="heroicon-o-clock" class="min-h-11 w-full justify-center">
                                        Прегледај ревизии
                                    </x-filament::button>

                                    <x-filament::button tag="a" href="{{ $article->previewUrl() }}" target="_blank" rel="noopener noreferrer" color="gray" outlined icon="heroicon-o-arrow-top-right-on-square" class="min-h-11 w-full justify-center">
                                        Преглед во живо
                                        <span class="sr-only"> (се отвора во нов прозорец)</span>
                                    </x-filament::button>

                                    @if (auth()->user()?->hasPermission('editorial.feedback') && auth()->user()?->can('review', $article))
                                        {{ ($this->addInternalNoteAction)(['articleId' => $article->id]) }}
                                    @endif
                                </div>
                            </div>
                        </aside>
                    </div>
                </article>
            @empty
                <section class="rounded-2xl border border-dashed border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-10 text-center">
                    <x-heroicon-o-check-circle class="mx-auto size-10 text-success-600" />
                    <h2 class="mt-4 text-lg font-bold text-[var(--razbudise-ink)]">Редицата е исчистена.</h2>
                    <p class="mt-2 text-sm text-[var(--razbudise-muted)]">Во моментов нема објави што чекаат одобрување.</p>
                </section>
            @endforelse
        </div>

        @if ($queue->hasPages())
            <nav aria-label="Страници со објави што чекаат одобрување">
                {{ $queue->onEachSide(1)->links() }}
            </nav>
        @endif
    </div>
</x-filament-panels::page>
