<?php

namespace App\Services\Editorial;

use App\Models\Author;
use App\Models\EditorialSupervision;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Operations\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditorialSupervisionService
{
    public function __construct(private readonly AdminAuditLogger $auditLogger) {}

    public function assignPrimary(User $editor, Author $author, ?User $actor = null): EditorialSupervision
    {
        $this->validatePrimaryAssignment($editor, $author);

        return DB::transaction(function () use ($editor, $author, $actor): EditorialSupervision {
            EditorialSupervision::query()
                ->where('author_id', $author->id)
                ->where('is_primary', true)
                ->whereNull('ends_at')
                ->where('editor_id', '!=', $editor->id)
                ->update(['ends_at' => now()]);

            $supervision = EditorialSupervision::query()->firstOrNew([
                'editor_id' => $editor->id,
                'author_id' => $author->id,
            ]);

            $before = $supervision->exists ? $supervision->only(['editor_id', 'author_id', 'is_primary', 'starts_at', 'ends_at']) : null;

            $supervision->fill([
                'is_primary' => true,
                'starts_at' => $supervision->starts_at ?? now(),
                'ends_at' => null,
                'created_by' => $supervision->created_by ?? $actor?->id,
            ])->save();

            $this->auditLogger->record(
                action: $supervision->wasRecentlyCreated ? 'editorial_supervision.created' : 'editorial_supervision.updated',
                auditable: $supervision,
                actor: $actor,
                before: $before ?? [],
                after: $supervision->only(['editor_id', 'author_id', 'is_primary', 'starts_at', 'ends_at']),
                metadata: ['surface' => 'editorial_supervision_sync'],
            );

            return $supervision->refresh();
        });
    }

    public function syncLaunchMapping(?User $actor = null): EditorialSupervision
    {
        $editor = $this->userFromTeamMemberSlug('elena-naumovska');
        $author = $this->authorFromTeamMemberSlug('romina-markovska');

        return $this->assignPrimary($editor, $author, $actor);
    }

    private function validatePrimaryAssignment(User $editor, Author $author): void
    {
        if (! $editor->isActive()) {
            throw ValidationException::withMessages(['editor_id' => 'Supervising editor must be active.']);
        }

        if (! ($editor->hasRole('editor') || $editor->hasRole('editor_in_chief') || $editor->isAdmin())) {
            throw ValidationException::withMessages(['editor_id' => 'Supervising user must be an Editor or Editor-in-Chief.']);
        }

        if (! $author->is_active) {
            throw ValidationException::withMessages(['author_id' => 'Author profile must be active.']);
        }

        $authorUserId = $author->teamMembers()
            ->whereNotNull('user_id')
            ->value('user_id');

        if ($authorUserId && (int) $authorUserId === (int) $editor->id) {
            throw ValidationException::withMessages(['author_id' => 'An author cannot supervise themselves.']);
        }
    }

    private function userFromTeamMemberSlug(string $slug): User
    {
        $members = TeamMember::query()
            ->where('slug', $slug)
            ->whereNotNull('user_id')
            ->with('user.roles')
            ->get();

        if ($members->count() !== 1 || ! $members->first()?->user) {
            throw ValidationException::withMessages([
                'editor' => "Cannot safely resolve exactly one editor user from team member slug [{$slug}].",
            ]);
        }

        return $members->first()->user;
    }

    private function authorFromTeamMemberSlug(string $slug): Author
    {
        $members = TeamMember::query()
            ->where('slug', $slug)
            ->whereNotNull('author_id')
            ->with('author')
            ->get();

        if ($members->count() !== 1 || ! $members->first()?->author) {
            throw ValidationException::withMessages([
                'author' => "Cannot safely resolve exactly one author from team member slug [{$slug}].",
            ]);
        }

        return $members->first()->author;
    }
}
