@php
    use Filament\Support\Enums\Alignment;
    use Filament\Support\View\Components\BadgeComponent;
    use Illuminate\View\ComponentAttributeBag;

    $notifications = $this->getNotifications();
    $notificationGroups = $this->groupNotifications($notifications);
    $unreadNotificationsCount = $this->getUnreadNotificationsCount();
    $hasNotifications = $notifications->count();
    $isPaginated = $notifications instanceof \Illuminate\Contracts\Pagination\Paginator && $notifications->hasPages();
    $pollingInterval = $this->getPollingInterval();
@endphp

<div class="fi-no-database newsroom-notifications">
    <x-filament::modal
        :alignment="$hasNotifications ? null : Alignment::Center"
        close-button
        :description="$hasNotifications ? null : 'Кога објава или вашата сметка бара внимание, известувањето ќе се појави тука.'"
        :heading="$hasNotifications ? null : 'Нема известувања'"
        :icon="$hasNotifications ? null : \Filament\Support\Icons\Heroicon::OutlinedBellSlash"
        icon-color="gray"
        id="database-notifications"
        slide-over
        :sticky-header="$hasNotifications"
        teleport="body"
        width="md"
        class="fi-no-database"
        :attributes="
            new ComponentAttributeBag([
                'wire:poll.' . $pollingInterval => $pollingInterval ? '' : false,
            ])
        "
    >
        @if ($trigger = $this->getTrigger())
            <x-slot name="trigger">
                {{ $trigger->with(['unreadNotificationsCount' => $unreadNotificationsCount]) }}
            </x-slot>
        @endif

        @if ($hasNotifications)
            <x-slot name="header">
                <div>
                    <h2 class="fi-modal-heading">
                        Известувања

                        @if ($unreadNotificationsCount)
                            <span
                                aria-label="{{ $unreadNotificationsCount }} непрочитани известувања"
                                {{
                                    (new ComponentAttributeBag)->color(BadgeComponent::class, 'primary')->class([
                                        'fi-badge fi-size-xs',
                                    ])
                                }}
                            >
                                {{ $unreadNotificationsCount }}
                            </span>
                        @endif
                    </h2>

                    <div class="fi-ac">
                        @if ($unreadNotificationsCount && $this->markAllNotificationsAsReadAction?->isVisible())
                            {{ $this->markAllNotificationsAsReadAction }}
                        @endif

                        @if ($this->clearNotificationsAction?->isVisible())
                            {{ $this->clearNotificationsAction }}
                        @endif
                    </div>
                </div>
            </x-slot>

            <div class="space-y-6" aria-live="polite" aria-label="Редакциски известувања">
                @foreach ($notificationGroups as $groupKey => $group)
                    <section aria-labelledby="newsroom-notification-group-{{ $groupKey }}">
                        <h3
                            id="newsroom-notification-group-{{ $groupKey }}"
                            class="px-4 pb-2 text-xs font-bold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-300"
                        >
                            {{ $group['label'] }}
                        </h3>

                        <div class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-white/10 dark:border-white/10">
                            @foreach ($group['notifications'] as $notification)
                                <div
                                    @class([
                                        'fi-no-notification-read-ctn' => ! $notification->unread(),
                                        'fi-no-notification-unread-ctn' => $notification->unread(),
                                    ])
                                >
                                    {{ $this->getNotification($notification)->inline() }}
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            @if ($broadcastChannel = $this->getBroadcastChannel())
                @script
                    <script>
                        window.addEventListener('EchoLoaded', () => {
                            window.Echo.private(@js($broadcastChannel)).listen(
                                '.database-notifications.sent',
                                () => setTimeout(() => $wire.call('$refresh'), 500),
                            )
                        })

                        if (window.Echo) {
                            window.dispatchEvent(new CustomEvent('EchoLoaded'))
                        }
                    </script>
                @endscript
            @endif

            @if ($isPaginated)
                <x-slot name="footer">
                    <x-filament::pagination :paginator="$notifications" />
                </x-slot>
            @endif
        @endif
    </x-filament::modal>
</div>
