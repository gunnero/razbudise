<?php

namespace App\Services\Newsroom;

use App\Filament\Pages\NewsroomManagedAuthors;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Authors\AuthorResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\Author;
use App\Models\EditorialSupervision;
use App\Models\User;

class NewsroomActivityPresenter
{
    /**
     * @return array{label: string, icon: string, subject: string|null, url: string|null}
     */
    public function present(AdminAuditLog $log, User $viewer): array
    {
        $event = config('newsroom.activity_events', [])[$log->action] ?? [];

        return [
            'label' => (string) ($event['label'] ?? str($log->action)->replace(['.', '_'], [' ', ' '])),
            'icon' => (string) ($event['icon'] ?? 'heroicon-o-clock'),
            'subject' => $this->subject($log),
            'url' => $this->destination($log, $viewer),
        ];
    }

    private function subject(AdminAuditLog $log): ?string
    {
        return match (true) {
            $log->auditable instanceof Article => $log->auditable->title,
            $log->auditable instanceof Author => $log->auditable->name,
            $log->auditable instanceof User => $log->auditable->name,
            $log->auditable instanceof EditorialSupervision => $log->auditable->author?->name,
            default => null,
        };
    }

    private function destination(AdminAuditLog $log, User $viewer): ?string
    {
        $subject = $log->auditable;

        if ($subject instanceof Article && $viewer->can('view', $subject)) {
            return ArticleResource::getUrl('edit', ['record' => $subject]);
        }

        if ($subject instanceof Author) {
            return $this->authorDestination($subject, $viewer);
        }

        if ($subject instanceof EditorialSupervision && $subject->author) {
            return $this->authorDestination($subject->author, $viewer);
        }

        if ($subject instanceof User) {
            if (UserResource::canEdit($subject)) {
                return UserResource::getUrl('edit', ['record' => $subject]);
            }

            if ($subject->teamMember?->author && NewsroomManagedAuthors::canAccess()) {
                return NewsroomManagedAuthors::getUrl(['q' => $subject->teamMember->author->name]);
            }
        }

        return null;
    }

    private function authorDestination(Author $author, User $viewer): ?string
    {
        if ($viewer->isAdmin() || ($viewer->hasRole('editor_in_chief') && $viewer->hasPermission('authors.manage'))) {
            return AuthorResource::getUrl('edit', ['record' => $author]);
        }

        if (NewsroomManagedAuthors::canAccess()) {
            return NewsroomManagedAuthors::getUrl(['q' => $author->name]);
        }

        return null;
    }
}
