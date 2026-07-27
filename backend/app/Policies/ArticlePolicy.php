<?php

namespace App\Policies;

use App\Models\Article;
use App\Models\User;

class ArticlePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('articles.view') || $user->hasPermission('articles.publish');
    }

    public function view(User $user, Article $article): bool
    {
        if ($user->hasRole('editor_in_chief')) {
            return $this->viewAny($user);
        }

        if ($user->hasRole('editor')) {
            return $this->viewAny($user) && $this->isInEditorScope($user, $article);
        }

        if ($user->hasRole('author') || $user->hasRole('contributor')) {
            return $article->author_id === $user->teamMember?->author_id;
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('articles.create') || $user->hasPermission('articles.publish');
    }

    public function update(User $user, Article $article): bool
    {
        if ($user->hasRole('editor_in_chief')) {
            return $user->hasPermission('articles.update');
        }

        if ($user->hasRole('editor')) {
            return $user->hasPermission('articles.update') && $this->isInEditorScope($user, $article);
        }

        if ($user->hasRole('author') || $user->hasRole('contributor')) {
            return $article->author_id === $user->teamMember?->author_id
                && in_array($article->status, ['draft', 'changes_requested', 'submitted', 'review'], true)
                && $user->hasPermission('articles.update');
        }

        return false;
    }

    public function submit(User $user, Article $article): bool
    {
        return $this->update($user, $article)
            && in_array($article->status, ['draft', 'changes_requested'], true);
    }

    public function review(User $user, Article $article): bool
    {
        if (! in_array($article->status, ['submitted', 'review', 'in_review'], true)) {
            return false;
        }

        if ($user->hasRole('editor_in_chief')) {
            return $user->hasPermission('articles.review');
        }

        if ($user->hasRole('editor')) {
            return $user->hasPermission('articles.review') && $this->isInEditorScope($user, $article);
        }

        return false;
    }

    public function requestChanges(User $user, Article $article): bool
    {
        return in_array($article->status, ['submitted', 'review', 'in_review'], true)
            && $this->review($user, $article);
    }

    public function approve(User $user, Article $article): bool
    {
        if (! in_array($article->status, ['submitted', 'review', 'in_review'], true)) {
            return false;
        }

        if ($user->hasRole('editor_in_chief')) {
            return $user->hasPermission('articles.approve');
        }

        if ($user->hasRole('editor')) {
            return $user->hasPermission('articles.approve') && $this->isInEditorScope($user, $article);
        }

        return false;
    }

    public function schedule(User $user, Article $article): bool
    {
        return $this->publish($user, $article);
    }

    public function publish(User $user, Article $article): bool
    {
        if ($user->hasRole('editor_in_chief')) {
            return $user->hasPermission('articles.publish');
        }

        if ($user->hasRole('editor')) {
            return $user->hasPermission('articles.publish') && $this->isInEditorScope($user, $article);
        }

        return false;
    }

    public function manageSeo(User $user, Article $article): bool
    {
        if ($user->hasRole('editor_in_chief')) {
            return $user->hasPermission('seo.manage');
        }

        if ($user->hasRole('editor')) {
            return $user->hasPermission('seo.manage') && $this->isInEditorScope($user, $article);
        }

        return false;
    }

    public function delete(User $user, Article $article): bool
    {
        if ($user->hasRole('author') || $user->hasRole('contributor')) {
            return $article->author_id === $user->teamMember?->author_id
                && $article->status === 'draft'
                && $user->hasPermission('articles.update');
        }

        return $user->hasPermission('articles.update') || $user->hasPermission('articles.publish');
    }

    public function restore(User $user, Article $article): bool
    {
        return $user->isAdmin()
            || $user->hasRole('editor_in_chief')
            || ($user->hasRole('editor') && $this->isInEditorScope($user, $article));
    }

    public function forceDelete(User $user, Article $article): bool
    {
        return false;
    }

    private function isInEditorScope(User $user, Article $article): bool
    {
        if ((int) $article->author_id === (int) ($user->teamMember?->author_id ?? 0)
            || (int) $article->assigned_editor_id === (int) $user->id
            || (int) $article->supervising_editor_id === (int) $user->id
            || (int) $article->created_by === (int) $user->id
            || (int) $article->updated_by === (int) $user->id) {
            return true;
        }

        return $user->editorialSupervisions()
            ->active()
            ->where('author_id', $article->author_id)
            ->exists();
    }
}
