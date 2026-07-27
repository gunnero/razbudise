@php
    use App\Support\AdminTranslations;

    $stateClasses = [
        'complete' => 'border-success-200 bg-success-50 text-success-800 dark:border-success-800 dark:bg-success-950/40 dark:text-success-200',
        'information' => 'border-info-200 bg-info-50 text-info-900 dark:border-info-800 dark:bg-info-950/40 dark:text-info-100',
        'warning' => 'border-warning-200 bg-warning-50 text-warning-900 dark:border-warning-800 dark:bg-warning-950/40 dark:text-warning-100',
        'blocking' => 'border-danger-200 bg-danger-50 text-danger-900 dark:border-danger-800 dark:bg-danger-950/40 dark:text-danger-100',
    ];
    $stateIcons = [
        'complete' => 'heroicon-o-check-circle',
        'information' => 'heroicon-o-information-circle',
        'warning' => 'heroicon-o-exclamation-circle',
        'blocking' => 'heroicon-o-x-circle',
    ];
    $workflow = app(\App\Services\Articles\ArticleEditorialWorkflow::class);
    $workflowAccess = [
        'submit' => (auth()->user()?->can('submit', $record) ?? false) && $workflow->canTransition($record, 'submit'),
        'start_review' => (auth()->user()?->can('review', $record) ?? false) && $workflow->canTransition($record, 'startReview'),
        'request_changes' => (auth()->user()?->can('requestChanges', $record) ?? false) && $workflow->canTransition($record, 'requestChanges'),
        'approve' => (auth()->user()?->can('approve', $record) ?? false) && $workflow->canTransition($record, 'approve'),
        'schedule' => (auth()->user()?->can('schedule', $record) ?? false) && $workflow->canTransition($record, 'schedule'),
        'publish' => (auth()->user()?->can('publish', $record) ?? false) && $workflow->canPublish($record, auth()->user()),
    ];
@endphp

<aside class="newsroom-review-sidebar" aria-label="Уредувачка проверка и работен тек" data-testid="newsroom-review-sidebar">
    <div class="space-y-4 xl:sticky xl:top-6">
        @include('filament.resources.articles.requested-change-panel', ['record' => $record])

        <x-filament::section>
            <x-slot name="heading">Уредувачка состојба</x-slot>
            <x-slot name="description">Кој работи на објавата и каде се наоѓа во процесот.</x-slot>

            <dl class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 pb-3 dark:border-white/10">
                    <dt class="text-gray-500 dark:text-gray-400">Статус</dt>
                    <dd>
                        <span class="inline-flex rounded-md bg-primary-50 px-2 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-950/50 dark:text-primary-300">
                            {{ AdminTranslations::articleStatus($record->status) }}
                        </span>
                    </dd>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Автор</dt>
                    <dd class="text-right font-medium text-gray-950 dark:text-white">{{ $record->author?->name ?? 'Не е избран' }}</dd>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Надлежен уредник</dt>
                    <dd class="text-right font-medium text-gray-950 dark:text-white">{{ $record->supervisingEditor?->name ?? 'Не е доделен' }}</dd>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Прегледувач</dt>
                    <dd class="text-right font-medium text-gray-950 dark:text-white">{{ $record->assignedEditor?->name ?? 'Не е доделен' }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Проверка пред објавување</x-slot>
            <x-slot name="description">
                {{ $checklistSummary['complete'] }} од {{ $checklistSummary['total'] }} проверки се завршени.
                @if ($checklistSummary['blocking'] > 0)
                    {{ $checklistSummary['blocking'] }} блокираат објавување.
                @endif
            </x-slot>

            <div class="mb-4 grid grid-cols-3 gap-2" aria-label="Резиме на проверката">
                <div class="rounded-lg border border-success-200 bg-success-50 px-2 py-2 text-center dark:border-success-800 dark:bg-success-950/40">
                    <strong class="block text-lg text-success-700 dark:text-success-300">{{ $checklistSummary['complete'] }}</strong>
                    <span class="text-xs text-success-800 dark:text-success-200">Завршени</span>
                </div>
                <div class="rounded-lg border border-warning-200 bg-warning-50 px-2 py-2 text-center dark:border-warning-800 dark:bg-warning-950/40">
                    <strong class="block text-lg text-warning-700 dark:text-warning-300">{{ $checklistSummary['warning'] }}</strong>
                    <span class="text-xs text-warning-900 dark:text-warning-100">Совети</span>
                </div>
                <div class="rounded-lg border border-danger-200 bg-danger-50 px-2 py-2 text-center dark:border-danger-800 dark:bg-danger-950/40">
                    <strong class="block text-lg text-danger-700 dark:text-danger-300">{{ $checklistSummary['blocking'] }}</strong>
                    <span class="text-xs text-danger-900 dark:text-danger-100">Блокади</span>
                </div>
            </div>

            <ul class="space-y-2" aria-label="Проверки на објавата">
                @foreach ($checklist as $key => $item)
                    @php
                        $displayState = $item['complete']
                            ? 'complete'
                            : ($item['severity'] ?? $item['state']);
                    @endphp
                    <li @class([
                        'rounded-lg border px-3 py-2 text-sm',
                        $stateClasses[$displayState] ?? $stateClasses['warning'],
                    ])
                        data-checklist-key="{{ $key }}"
                        data-checklist-state="{{ $item['state'] }}"
                        data-checklist-severity="{{ $item['severity'] ?? $item['state'] }}"
                    >
                        <div class="flex items-start gap-2">
                        <x-filament::icon
                            :icon="$stateIcons[$displayState] ?? $stateIcons['warning']"
                            class="mt-0.5 h-5 w-5 shrink-0"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 flex-1">
                            <strong class="block font-semibold">{{ $item['label'] }}</strong>
                            @if (filled($item['message']))
                                <span class="mt-0.5 block text-xs opacity-90">{{ $item['message'] }}</span>
                            @endif
                        </div>
                        </div>

                        @if (! $item['complete'] && (filled($item['why']) || filled($item['how']) || filled($item['action_label'])))
                            <details class="mt-2 border-t border-current/15 pt-2 text-xs">
                                <summary class="cursor-pointer font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                                    Зошто и како да се поправи
                                </summary>
                                <div class="mt-2 space-y-2 leading-5">
                                    @if (filled($item['why']))
                                        <p><strong>Зошто:</strong> {{ $item['why'] }}</p>
                                    @endif
                                    @if (filled($item['how']))
                                        <p><strong>Како:</strong> {{ $item['how'] }}</p>
                                    @endif
                                    @if (filled($item['action_label']) && filled($item['target']))
                                        <button
                                            type="button"
                                            class="inline-flex min-h-9 items-center border-b-2 border-primary-500 font-semibold text-current transition hover:text-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:hover:text-primary-300"
                                            data-checklist-target="{{ $item['target'] }}"
                                            x-data
                                            x-on:click="
                                                const target = document.getElementById(@js($item['target']));
                                                if (target) {
                                                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                                    window.setTimeout(() => {
                                                        const control = target.matches('input, textarea, select, button')
                                                            ? target
                                                            : target.querySelector('input, textarea, select, button, [contenteditable=true]');
                                                        control?.focus({ preventScroll: true });
                                                    }, 250);
                                                }
                                            "
                                        >
                                            {{ $item['action_label'] }}
                                        </button>
                                    @endif
                                </div>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="mt-4 grid grid-cols-2 gap-2 border-t border-gray-200 pt-4 text-sm dark:border-white/10">
                <div>
                    <span class="block text-gray-500 dark:text-gray-400">Нерешени коментари</span>
                    <strong class="text-gray-950 dark:text-white" data-newsroom-unresolved-comments="{{ $unresolvedComments }}">{{ $unresolvedComments }}</strong>
                </div>
                <div>
                    <span class="block text-gray-500 dark:text-gray-400">Ревизии</span>
                    <strong class="text-gray-950 dark:text-white">{{ $revisionCount }}</strong>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section class="newsroom-review-workflow-desktop">
            <x-slot name="heading">Работен тек</x-slot>
            <x-slot name="description">Достапни се само дејствата што ги дозволува вашата улога.</x-slot>

            @include('filament.resources.articles.workflow-actions', [
                'includeSave' => true,
                'layout' => 'desktop',
                'record' => $record,
                'workflowAccess' => $workflowAccess,
            ])
        </x-filament::section>
    </div>

    <div
        class="newsroom-mobile-workflow"
        data-testid="newsroom-mobile-workflow"
        x-cloak
        x-data="{ workflowOpen: false }"
        x-on:keydown.escape.window="if (workflowOpen) { workflowOpen = false; $nextTick(() => $refs.workflowToggle.focus()) }"
    >
        <div
            class="newsroom-mobile-workflow__backdrop"
            x-cloak
            x-show="workflowOpen"
            x-transition.opacity
            x-on:click="workflowOpen = false; $nextTick(() => $refs.workflowToggle.focus())"
            aria-hidden="true"
        ></div>

        <section
            id="newsroom-mobile-workflow-sheet"
            class="newsroom-mobile-workflow__sheet"
            x-cloak
            x-show="workflowOpen"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="translate-y-4 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="translate-y-0 opacity-100"
            x-transition:leave-end="translate-y-4 opacity-0"
            x-trap.noscroll.noautofocus="workflowOpen"
            role="dialog"
            aria-modal="true"
            aria-labelledby="newsroom-mobile-workflow-title"
        >
            <div class="newsroom-mobile-workflow__sheet-header">
                <div>
                    <h2 id="newsroom-mobile-workflow-title" class="font-bold text-[var(--razbudise-ink)]">Работен тек</h2>
                    <p class="mt-1 text-xs leading-5 text-[var(--razbudise-muted)]">Прикажани се само дејствата што ги дозволува вашата улога.</p>
                </div>
                <button
                    type="button"
                    class="newsroom-mobile-workflow__close"
                    x-ref="workflowClose"
                    x-on:click="workflowOpen = false; $nextTick(() => $refs.workflowToggle.focus())"
                    aria-label="Затвори ги дејствата"
                >
                    <x-heroicon-o-x-mark class="size-5" aria-hidden="true" />
                </button>
            </div>

            @include('filament.resources.articles.workflow-actions', [
                'includeSave' => true,
                'layout' => 'mobile',
                'record' => $record,
                'workflowAccess' => $workflowAccess,
            ])
        </section>

        <div
            class="newsroom-mobile-workflow__bar"
            x-show="! workflowOpen"
            x-transition.opacity
            aria-label="Залепени дејства за објавата"
        >
            <x-filament::button
                type="button"
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                icon="heroicon-o-check"
                class="min-h-11"
            >
                Зачувај
            </x-filament::button>
            <button
                type="button"
                class="newsroom-mobile-workflow__toggle"
                x-ref="workflowToggle"
                x-on:click="workflowOpen = true; $nextTick(() => $refs.workflowClose.focus())"
                x-bind:aria-expanded="workflowOpen.toString()"
                aria-controls="newsroom-mobile-workflow-sheet"
            >
                <x-heroicon-o-adjustments-horizontal class="size-5" aria-hidden="true" />
                <span>Работен тек</span>
            </button>
        </div>
    </div>
</aside>
