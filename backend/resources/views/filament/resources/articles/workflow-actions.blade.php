@php
    $includeSave = $includeSave ?? true;
    $layout = $layout ?? 'desktop';
@endphp

<div
    @class([
        'grid gap-2',
        'sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2' => $layout === 'desktop',
        'newsroom-mobile-workflow__actions' => $layout === 'mobile',
    ])
    role="group"
    aria-label="Дејства за објавата"
>
    @if ($includeSave)
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
    @endif

    @if ($workflowAccess['submit'])
        <x-filament::button type="button" wire:click="mountAction('send_to_review')" color="warning" icon="heroicon-o-paper-airplane" class="min-h-11">
            Испрати на преглед
        </x-filament::button>
    @endif

    @if ($workflowAccess['start_review'])
        <x-filament::button type="button" wire:click="mountAction('start_review')" color="gray" outlined icon="heroicon-o-pencil-square" class="min-h-11">
            Започни преглед
        </x-filament::button>
    @endif

    @if ($workflowAccess['request_changes'])
        <x-filament::button type="button" wire:click="mountAction('request_changes')" color="warning" outlined icon="heroicon-o-exclamation-triangle" class="min-h-11">
            Побарај измени
        </x-filament::button>
    @endif

    @if ($workflowAccess['approve'])
        <x-filament::button type="button" wire:click="mountAction('approve')" color="success" icon="heroicon-o-check-circle" class="min-h-11">
            Одобри
        </x-filament::button>
    @endif

    @if ($workflowAccess['schedule'])
        <x-filament::button type="button" wire:click="mountAction('schedule')" color="info" outlined icon="heroicon-o-calendar-days" class="min-h-11">
            Закажи
        </x-filament::button>
    @endif

    @if ($workflowAccess['publish'])
        <x-filament::button type="button" wire:click="mountAction('publish')" color="success" outlined icon="heroicon-o-rocket-launch" class="min-h-11">
            Објави
        </x-filament::button>
    @endif

    <x-filament::button
        tag="a"
        :href="$record->previewUrl()"
        target="_blank"
        rel="noopener noreferrer"
        color="gray"
        outlined
        icon="heroicon-o-arrow-top-right-on-square"
        class="min-h-11"
    >
        Преглед
    </x-filament::button>
</div>
