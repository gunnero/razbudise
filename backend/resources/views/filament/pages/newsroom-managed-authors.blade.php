<x-filament-panels::page>
    <div class="space-y-5" data-testid="newsroom-managed-authors">
        <section class="rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-5 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--razbudise-accent-deep)]">Авторски тим</p>
                    <h2 class="mt-2 text-xl font-bold text-[var(--razbudise-ink)] sm:text-2xl">{{ $authors->count() }} автори во вашиот опсег</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-[var(--razbudise-muted)]">Брз преглед на работниот тек, последната активност и поврзаноста на авторскиот профил.</p>
                </div>
                @if (\App\Filament\Resources\TeamMembers\TeamMemberResource::canCreate())
                    <x-filament::button tag="a" href="{{ \App\Filament\Resources\TeamMembers\TeamMemberResource::getUrl('create') }}" icon="heroicon-o-user-plus" class="min-h-11">
                        Нов член на тимот
                    </x-filament::button>
                @endif
            </div>
        </section>

        <section class="grid gap-4 rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-4 md:grid-cols-2 {{ $canUseManagementFilters ? 'xl:grid-cols-5' : '' }}" aria-label="Филтри за автори">
            <div>
                <label for="author-search" class="mb-1.5 block text-sm font-semibold text-[var(--razbudise-ink)]">Пребарај автор</label>
                <div class="relative">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-3.5 size-4 text-[var(--razbudise-muted)]" aria-hidden="true" />
                    <input id="author-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Име или презиме" class="min-h-11 w-full rounded-lg border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] pl-10 pr-3 text-sm text-[var(--razbudise-ink)] placeholder:text-[var(--razbudise-muted)] focus:border-[var(--razbudise-accent)] focus:ring-[var(--razbudise-accent)]" />
                </div>
            </div>
            <div>
                <label for="author-status" class="mb-1.5 block text-sm font-semibold text-[var(--razbudise-ink)]">Авторски профил</label>
                <select id="author-status" wire:model.live="status" class="min-h-11 w-full rounded-lg border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] px-3 text-sm text-[var(--razbudise-ink)] focus:border-[var(--razbudise-accent)] focus:ring-[var(--razbudise-accent)]">
                    <option value="all">Сите</option>
                    <option value="active">Активни</option>
                    <option value="inactive">Неактивни</option>
                </select>
            </div>
            @if ($canUseManagementFilters)
                <div>
                    <label for="author-account-status" class="mb-1.5 block text-sm font-semibold text-[var(--razbudise-ink)]">Корисничка сметка</label>
                    <select id="author-account-status" wire:model.live="accountStatus" class="min-h-11 w-full rounded-lg border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] px-3 text-sm text-[var(--razbudise-ink)] focus:border-[var(--razbudise-accent)] focus:ring-[var(--razbudise-accent)]">
                        <option value="all">Сите сметки</option>
                        <option value="active">Активни сметки</option>
                        <option value="disabled">Оневозможени сметки</option>
                        <option value="unlinked">Без поврзана сметка</option>
                    </select>
                </div>
                <div>
                    <label for="author-supervising-editor" class="mb-1.5 block text-sm font-semibold text-[var(--razbudise-ink)]">Одговорен уредник</label>
                    <select id="author-supervising-editor" wire:model.live="supervisingEditor" class="min-h-11 w-full rounded-lg border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] px-3 text-sm text-[var(--razbudise-ink)] focus:border-[var(--razbudise-accent)] focus:ring-[var(--razbudise-accent)]">
                        <option value="all">Сите уредници</option>
                        <option value="unassigned">Без уредник</option>
                        @foreach ($supervisingEditors as $editor)
                            <option value="{{ $editor->id }}">{{ $editor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="author-department" class="mb-1.5 block text-sm font-semibold text-[var(--razbudise-ink)]">Оддел</label>
                    <select id="author-department" wire:model.live="department" class="min-h-11 w-full rounded-lg border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] px-3 text-sm text-[var(--razbudise-ink)] focus:border-[var(--razbudise-accent)] focus:ring-[var(--razbudise-accent)]">
                        <option value="all">Сите оддели</option>
                        @foreach ($departments as $teamDepartment)
                            <option value="{{ $teamDepartment->id }}">{{ $teamDepartment->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </section>

        <div class="grid gap-5 xl:grid-cols-2">
            @forelse ($authors as $author)
                @php
                    $avatar = app(\App\Services\Authors\AuthorAvatarResolver::class)->forAuthor($author, request());
                    $editor = $author->primarySupervision?->editor;
                    $linkedEditorialUser = $author->teamMembers
                        ->pluck('user')
                        ->filter()
                        ->first();
                    $hasLinkedAccount = filled($linkedEditorialUser);
                    $canManageAccount = (auth()->user()->isAdmin() || auth()->user()->hasRole('editor_in_chief'))
                        && auth()->user()->hasPermission('editorial.users.manage');
                    $profileFields = collect([$author->name, $author->short_bio, $author->full_bio, $author->job_title, $author->slug]);
                    $profilePercent = (int) round(($profileFields->filter(fn ($value) => filled($value))->count() / $profileFields->count()) * 100);
                @endphp
                <article class="flex h-full flex-col overflow-hidden rounded-2xl border border-[var(--razbudise-line)] bg-[var(--razbudise-surface)]" data-testid="author-card-{{ $author->id }}">
                    <div class="flex gap-4 border-b border-[var(--razbudise-line)] p-5">
                        <img src="{{ data_get($avatar, 'url') }}" alt="{{ data_get($avatar, 'alt_text', 'Профилна слика за '.$author->name) }}" class="size-20 shrink-0 rounded-xl border border-[var(--razbudise-line)] object-cover" loading="lazy" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <h2 class="text-lg font-bold text-[var(--razbudise-ink)]">{{ $author->name }}</h2>
                                    <p class="mt-1 text-sm text-[var(--razbudise-muted)]">{{ $author->job_title ?: 'Авторски профил' }}</p>
                                </div>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $author->is_active ? 'bg-success-50 text-success-700 dark:bg-success-950 dark:text-success-200' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' }}">{{ $author->is_active ? 'Активен' : 'Неактивен' }}</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                                <span class="rounded-full bg-[var(--razbudise-soft)] px-2.5 py-1 text-[var(--razbudise-muted)]">{{ $editor ? 'Уредник: '.$editor->name : 'Нема доделен уредник' }}</span>
                                <span class="rounded-full bg-[var(--razbudise-soft)] px-2.5 py-1 text-[var(--razbudise-muted)]">{{ $hasLinkedAccount ? 'Поврзана сметка' : 'Нема поврзана сметка' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-px bg-[var(--razbudise-line)] sm:grid-cols-5" aria-label="Работен тек за {{ $author->name }}">
                        @foreach ([
                            'drafts_count' => ['Работни верзии', 'working_versions'],
                            'pending_approval_count' => ['Чекаат', 'waiting'],
                            'changes_requested_count' => ['Измени', 'changes'],
                            'scheduled_count' => ['Закажани', 'scheduled'],
                            'published_this_month_count' => ['Овој месец', 'this_month'],
                        ] as $countAttribute => [$countLabel, $metric])
                            <a
                                href="{{ \App\Filament\Pages\NewsroomManagedPosts::authorDrilldownUrl($author->id, $metric, $drilldownContext) }}"
                                class="group bg-[var(--razbudise-surface)] px-3 py-4 text-center outline-none transition hover:bg-[var(--razbudise-soft)] focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--razbudise-accent)]"
                                aria-label="{{ $countLabel }} за {{ $author->name }}: {{ $author->{$countAttribute} ?? 0 }}. Отвори филтрирани објави."
                            >
                                <strong class="block text-xl font-bold tabular-nums text-[var(--razbudise-ink)]">{{ $author->{$countAttribute} ?? 0 }}</strong>
                                <span class="mt-1 block text-[0.7rem] text-[var(--razbudise-muted)] underline-offset-4 group-hover:text-[var(--razbudise-accent-deep)] group-hover:underline">{{ $countLabel }}</span>
                            </a>
                        @endforeach
                    </div>

                    <div class="flex flex-1 flex-col justify-between gap-5 p-5">
                        <div>
                            <div class="flex items-center justify-between gap-3 text-xs">
                                <span class="font-semibold text-[var(--razbudise-ink)]">Комплетност на профилот</span>
                                <span class="text-[var(--razbudise-muted)]">{{ $profilePercent }}%</span>
                            </div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--razbudise-soft)]" role="progressbar" aria-label="Комплетност на профилот за {{ $author->name }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $profilePercent }}">
                                <div class="h-full rounded-full {{ $profilePercent === 100 ? 'bg-success-600' : 'bg-[var(--razbudise-accent)]' }}" style="width: {{ $profilePercent }}%"></div>
                            </div>
                            <p class="mt-3 text-xs text-[var(--razbudise-muted)]">Последна редакциска активност: {{ $author->last_activity_at ? \Illuminate\Support\Carbon::parse($author->last_activity_at)->locale('mk')->diffForHumans() : 'нема активност' }}</p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <x-filament::button tag="a" href="{{ \App\Filament\Pages\NewsroomManagedPosts::getUrl().'?author='.$author->id }}" size="sm" icon="heroicon-o-document-text" class="min-h-11">
                                Погледни објави
                            </x-filament::button>
                            <x-filament::button tag="a" href="{{ rtrim((string) config('app.frontend_url'), '/').'/profil/'.$author->slug }}" target="_blank" rel="noopener noreferrer" size="sm" color="gray" outlined icon="heroicon-o-arrow-top-right-on-square" class="min-h-11">
                                Јавен профил
                            </x-filament::button>
                            @if (auth()->user()->hasPermission('articles.create'))
                                <x-filament::button tag="a" href="{{ \App\Filament\Resources\Articles\ArticleResource::getUrl('create').'?author_id='.$author->id }}" size="sm" color="gray" outlined icon="heroicon-o-plus" class="min-h-11">
                                    Нова објава
                                </x-filament::button>
                            @endif
                            @if (\App\Filament\Resources\Authors\AuthorResource::canEdit($author))
                                <x-filament::button tag="a" href="{{ \App\Filament\Resources\Authors\AuthorResource::getUrl('edit', ['record' => $author]) }}" size="sm" color="gray" outlined icon="heroicon-o-pencil-square" class="min-h-11">
                                    Уреди профил
                                </x-filament::button>
                            @endif
                            @if ($author->teamMembers->first() && \App\Filament\Resources\TeamMembers\TeamMemberResource::canEdit($author->teamMembers->first()))
                                <x-filament::button tag="a" href="{{ \App\Filament\Resources\TeamMembers\TeamMemberResource::getUrl('edit', ['record' => $author->teamMembers->first()]) }}" size="sm" color="gray" outlined icon="heroicon-o-users" class="min-h-11">
                                    Тимски податоци
                                </x-filament::button>
                            @endif
                            @if ($canManageAccount && $linkedEditorialUser)
                                @if (\App\Filament\Resources\Users\UserResource::canEdit($linkedEditorialUser))
                                    <x-filament::button tag="a" href="{{ \App\Filament\Resources\Users\UserResource::getUrl('edit', ['record' => $linkedEditorialUser]) }}" size="sm" color="gray" outlined icon="heroicon-o-arrow-path-rounded-square" class="min-h-11">
                                        Прераспредели
                                    </x-filament::button>
                                @endif
                                <x-filament::button
                                    wire:click="setAccountStatus({{ $author->id }}, '{{ $linkedEditorialUser->isActive() ? 'disabled' : 'active' }}')"
                                    wire:confirm="{{ $linkedEditorialUser->isActive() ? 'Да ја оневозможиме оваа корисничка сметка?' : 'Да ја активираме оваа корисничка сметка?' }}"
                                    wire:loading.attr="disabled"
                                    wire:target="setAccountStatus({{ $author->id }}, '{{ $linkedEditorialUser->isActive() ? 'disabled' : 'active' }}')"
                                    size="sm"
                                    :color="$linkedEditorialUser->isActive() ? 'danger' : 'success'"
                                    outlined
                                    :icon="$linkedEditorialUser->isActive() ? 'heroicon-o-user-minus' : 'heroicon-o-user-plus'"
                                    class="min-h-11"
                                >
                                    {{ $linkedEditorialUser->isActive() ? 'Оневозможи пристап' : 'Активирај пристап' }}
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <section class="rounded-2xl border border-dashed border-[var(--razbudise-line)] bg-[var(--razbudise-surface)] p-10 text-center xl:col-span-2">
                    <x-heroicon-o-user-group class="mx-auto size-10 text-[var(--razbudise-muted)]" />
                    <h2 class="mt-4 text-lg font-bold text-[var(--razbudise-ink)]">Нема автори за овие филтри.</h2>
                    <p class="mt-2 text-sm text-[var(--razbudise-muted)]">Проверете го името или сменете ја состојбата.</p>
                </section>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
