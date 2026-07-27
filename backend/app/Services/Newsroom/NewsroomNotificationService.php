<?php

namespace App\Services\Newsroom;

use App\Filament\Pages\EditProfile;
use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class NewsroomNotificationService
{
    public const NEEDS_ACTION = 'needs_action';

    public const EDITORIAL_UPDATES = 'editorial_updates';

    public const PUBLICATION_EVENTS = 'publication_events';

    public const ACCOUNT_EVENTS = 'account_events';

    public function articleAuditEvent(Article $article, string $action, ?User $actor = null): void
    {
        $event = match ($action) {
            'article.submitted' => [
                'category' => self::NEEDS_ACTION,
                'title' => 'Нова објава чека преглед',
                'body' => $this->articleBody($article, 'е испратена на уреднички преглед.'),
                'icon' => 'heroicon-o-inbox-arrow-down',
                'color' => 'warning',
                'recipients' => $this->reviewersFor($article),
            ],
            'article.review_started' => [
                'category' => self::EDITORIAL_UPDATES,
                'title' => 'Започна уредничкиот преглед',
                'body' => $this->articleBody($article, 'е преземена за преглед.'),
                'icon' => 'heroicon-o-pencil-square',
                'color' => 'info',
                'recipients' => $this->articleStakeholders($article),
            ],
            'article.changes_requested' => [
                'category' => self::NEEDS_ACTION,
                'title' => 'Потребна доработка',
                'body' => $this->changeRequestBody($article),
                'icon' => 'heroicon-o-arrow-uturn-left',
                'color' => 'warning',
                'recipients' => $this->articleAuthors($article),
            ],
            'article.approved' => [
                'category' => self::EDITORIAL_UPDATES,
                'title' => 'Објавата е одобрена',
                'body' => $this->articleBody($article, 'е подготвена за закажување или објавување.'),
                'icon' => 'heroicon-o-check-circle',
                'color' => 'success',
                'recipients' => $this->articleStakeholders($article),
            ],
            'article.scheduled' => [
                'category' => self::PUBLICATION_EVENTS,
                'title' => 'Објавата е закажана',
                'body' => $this->articleBody($article, 'има закажан термин за објавување.'),
                'icon' => 'heroicon-o-calendar-days',
                'color' => 'info',
                'recipients' => $this->articleStakeholders($article),
            ],
            'article.published' => [
                'category' => self::PUBLICATION_EVENTS,
                'title' => 'Објавата е објавена',
                'body' => $this->articleBody($article, 'е достапна на RazbudiSe.'),
                'icon' => 'heroicon-o-rocket-launch',
                'color' => 'success',
                'recipients' => $this->articleStakeholders($article),
            ],
            'article.archived' => [
                'category' => self::PUBLICATION_EVENTS,
                'title' => 'Објавата е архивирана',
                'body' => $this->articleBody($article, 'повеќе не е активна.'),
                'icon' => 'heroicon-o-archive-box',
                'color' => 'gray',
                'recipients' => $this->articleStakeholders($article),
            ],
            'article.publication_failed' => [
                'category' => self::NEEDS_ACTION,
                'title' => 'Објавувањето не успеа',
                'body' => $this->articleBody($article, 'бара уредничка проверка пред повторен обид.'),
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'recipients' => $this->reviewersFor($article),
            ],
            default => null,
        };

        if ($event === null) {
            return;
        }

        $this->send(
            recipients: $event['recipients']->reject(fn (User $user): bool => $user->is($actor)),
            category: $event['category'],
            title: $event['title'],
            body: $event['body'],
            url: ArticleResource::getUrl('edit', ['record' => $article]),
            icon: $event['icon'],
            color: $event['color'],
        );
    }

    /** @param list<string> $changes */
    public function accountUpdated(User $user, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $labels = [
            'status' => 'статус',
            'roles' => 'улоги и дозволи',
            'password' => 'лозинка',
            'email' => 'е-пошта',
            'name' => 'име',
        ];
        $changedLabels = collect($changes)
            ->map(fn (string $change): string => $labels[$change] ?? $change)
            ->implode(', ');

        $this->send(
            recipients: collect([$user]),
            category: self::ACCOUNT_EVENTS,
            title: 'Вашата корисничка сметка е ажурирана',
            body: 'Променети податоци: '.$changedLabels.'.',
            url: EditProfile::getUrl(),
            icon: 'heroicon-o-user-circle',
            color: 'info',
        );
    }

    public function accountCreated(User $user): void
    {
        $this->send(
            recipients: collect([$user]),
            category: self::ACCOUNT_EVENTS,
            title: 'Добредојдовте во редакцијата на RazbudiSe',
            body: 'Вашата корисничка сметка е подготвена. Проверете го профилот и доделените улоги.',
            url: EditProfile::getUrl(),
            icon: 'heroicon-o-user-plus',
            color: 'success',
        );
    }

    /** @return Collection<int, User> */
    private function reviewersFor(Article $article): Collection
    {
        $editorIds = collect([$article->assigned_editor_id, $article->supervising_editor_id])
            ->filter()
            ->map(fn ($id): int => (int) $id);

        return User::query()
            ->where('status', 'active')
            ->where(function (Builder $query) use ($article, $editorIds): void {
                $query
                    ->whereKey($editorIds)
                    ->orWhereHas('roles', fn (Builder $query): Builder => $query->whereIn('name', ['admin', 'super_admin', 'editor_in_chief']))
                    ->orWhereHas('editorialSupervisions', fn (Builder $query): Builder => $query
                        ->active()
                        ->where('author_id', $article->author_id));
            })
            ->get();
    }

    /** @return Collection<int, User> */
    private function articleAuthors(Article $article): Collection
    {
        return User::query()
            ->where('status', 'active')
            ->whereHas('teamMember', fn (Builder $query): Builder => $query->where('author_id', $article->author_id))
            ->get();
    }

    /** @return Collection<int, User> */
    private function articleStakeholders(Article $article): Collection
    {
        $userIds = collect([
            $article->created_by,
            $article->submitted_by,
            $article->assigned_editor_id,
            $article->supervising_editor_id,
        ])->filter()->map(fn ($id): int => (int) $id);

        return User::query()
            ->where('status', 'active')
            ->where(function (Builder $query) use ($article, $userIds): void {
                $query
                    ->whereKey($userIds)
                    ->orWhereHas('teamMember', fn (Builder $query): Builder => $query->where('author_id', $article->author_id));
            })
            ->get();
    }

    private function articleBody(Article $article, string $suffix): string
    {
        return '„'.str($article->title)->limit(110).'“ '.$suffix;
    }

    private function changeRequestBody(Article $article): string
    {
        $request = $article->changeRequests()->latest()->first();

        if (! $request) {
            return $this->articleBody($article, 'е вратена за доработка.');
        }

        $body = $this->articleBody($article, 'е вратена за доработка.');
        $body .= ' '.str($request->summary)->squish()->limit(180);

        if ($request->deadline_at) {
            $body .= ' Рок: '.$request->deadline_at->format('d.m.Y').'.';
        }

        return $body;
    }

    /** @param Collection<int, User> $recipients */
    private function send(
        Collection $recipients,
        string $category,
        string $title,
        string $body,
        string $url,
        string $icon,
        string $color,
    ): void {
        $recipients = $recipients->unique('id')->values();

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::make()
                ->title($title)
                ->body($body)
                ->icon($icon)
                ->iconColor($color)
                ->viewData(['newsroom_category' => $category])
                ->actions([
                    Action::make('open')
                        ->label('Отвори')
                        ->url($url)
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipients);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
