@php
    $authorView = auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor');
    $activeChangeRequest = $record->latestChangeRequest()
        ->with([
            'requester',
            'comments' => fn ($query) => $query
                ->when(
                    $authorView,
                    fn ($visible) => $visible->where(
                        'visibility',
                        \App\Models\ArticleEditorialComment::VISIBILITY_AUTHOR,
                    ),
                ),
            'comments.author',
            'comments.addressedBy',
            'comments.resolver',
            'comments.replies' => fn ($query) => $query
                ->when(
                    $authorView,
                    fn ($visible) => $visible->where('visibility', \App\Models\ArticleEditorialComment::VISIBILITY_AUTHOR),
                )
                ->with('author'),
        ])
        ->first();
@endphp

@if ($record->status === 'changes_requested' && $activeChangeRequest)
    <x-filament::section
        class="border-warning-300 bg-warning-50 dark:border-warning-700 dark:bg-warning-950/40"
        data-testid="requested-change-panel"
    >
        <x-slot name="heading">Потребна доработка</x-slot>
        <x-slot name="description">Прегледајте ги забелешките, направете ги измените и испратете ја објавата повторно на проверка.</x-slot>

        <div class="space-y-4">
            <div class="rounded-lg border border-warning-200 bg-white/80 p-3 dark:border-warning-800 dark:bg-black/10">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $activeChangeRequest->summary }}</p>
                <dl class="mt-3 grid gap-2 text-xs text-gray-600 dark:text-gray-300 sm:grid-cols-2">
                    <div>
                        <dt class="font-medium">Побарал</dt>
                        <dd>{{ $activeChangeRequest->requester?->name ?? 'Редакција' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium">Побарано на</dt>
                        <dd>{{ $activeChangeRequest->created_at?->format('d.m.Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium">Рок</dt>
                        <dd @class(['font-semibold text-danger-700 dark:text-danger-300' => $activeChangeRequest->deadline_at?->isBefore(today())])>
                            {{ $activeChangeRequest->deadline_at?->format('d.m.Y') ?? 'Без зададен рок' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium">Нерешени забелешки</dt>
                        <dd>{{ $activeChangeRequest->comments->where('is_resolved', false)->count() }}</dd>
                    </div>
                </dl>
            </div>

            <ol class="space-y-2" aria-label="Забелешки за доработка">
                @foreach ($activeChangeRequest->comments as $comment)
                    <li @class([
                        'rounded-lg border p-3 text-sm',
                        'border-danger-300 bg-danger-50/80 dark:border-danger-800 dark:bg-danger-950/30' => $comment->is_blocking && ! $comment->is_resolved,
                        'border-warning-200 bg-white/80 dark:border-warning-800 dark:bg-black/10' => ! $comment->is_blocking && ! $comment->is_resolved,
                        'border-success-200 bg-success-50/70 opacity-80 dark:border-success-800 dark:bg-success-950/20' => $comment->is_resolved,
                    ])>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($comment->is_blocking)
                                    <span class="rounded-full bg-danger-100 px-2 py-0.5 text-xs font-semibold text-danger-800 dark:bg-danger-900 dark:text-danger-100">Блокира</span>
                                @else
                                    <span class="rounded-full bg-warning-100 px-2 py-0.5 text-xs font-semibold text-warning-900 dark:bg-warning-900 dark:text-warning-100">Препорака</span>
                                @endif
                                @if ($comment->is_resolved)
                                    <span class="text-xs font-medium text-success-700 dark:text-success-300">Решено</span>
                                @elseif ($comment->addressed_at)
                                    <span class="text-xs font-medium text-info-700 dark:text-info-300">Означено како адресирано</span>
                                @else
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Нерешено</span>
                                @endif
                            </div>
                            <time class="text-xs text-gray-500 dark:text-gray-400" datetime="{{ $comment->created_at?->toAtomString() }}">
                                {{ $comment->created_at?->format('d.m.Y H:i') }}
                            </time>
                        </div>
                        <p class="mt-2 leading-6 text-gray-900 dark:text-gray-100">{{ $comment->body }}</p>

                        @if ($comment->replies->isNotEmpty())
                            <ul class="mt-3 space-y-2 border-l-2 border-gray-200 pl-3 dark:border-white/10" aria-label="Одговори">
                                @foreach ($comment->replies as $reply)
                                    <li class="text-xs leading-5 text-gray-700 dark:text-gray-300">
                                        <strong>{{ $reply->author?->name ?? 'Редакција' }}:</strong>
                                        {{ $reply->body }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ol>

            <p class="text-xs leading-5 text-gray-600 dark:text-gray-300">
                Одговорете или означете ставка како адресирана во „Уреднички коментари“. Потоа користете „Испрати повторно на проверка“.
            </p>
        </div>
    </x-filament::section>
@endif
