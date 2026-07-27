<?php

namespace App\Livewire;

use Filament\Livewire\DatabaseNotifications;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Support\Collection;

class NewsroomDatabaseNotifications extends DatabaseNotifications
{
    /** @var array<string, string> */
    private const GROUP_LABELS = [
        'needs_action' => 'Потребно дејство',
        'editorial_updates' => 'Уреднички новости',
        'publication_events' => 'Објавување',
        'account_events' => 'Сметка',
    ];

    /**
     * @return array<string, array{label: string, notifications: Collection<int, mixed>}>
     */
    public function groupNotifications(DatabaseNotificationCollection|Paginator $notifications): array
    {
        $items = $notifications instanceof Paginator
            ? collect($notifications->items())
            : collect($notifications);

        $grouped = [];

        foreach (self::GROUP_LABELS as $key => $label) {
            $matching = $items
                ->filter(fn ($notification): bool => data_get($notification->data, 'viewData.newsroom_category', 'editorial_updates') === $key)
                ->values();

            if ($matching->isNotEmpty()) {
                $grouped[$key] = [
                    'label' => $label,
                    'notifications' => $matching,
                ];
            }
        }

        return $grouped;
    }

    public function render(): View
    {
        return view('livewire.newsroom-database-notifications');
    }
}
