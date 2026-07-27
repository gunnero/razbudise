<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\User;
use App\Services\Operations\AdminAuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ArticleEditorialWorkflow
{
    /** @var array<string, list<string>> */
    private const TRANSITION_SOURCES = [
        'submit' => ['draft', 'changes_requested'],
        'startReview' => ['submitted', 'review'],
        'requestChanges' => ['submitted', 'review', 'in_review'],
        'approve' => ['submitted', 'review', 'in_review'],
        'schedule' => ['draft', 'submitted', 'review', 'in_review', 'changes_requested', 'approved', 'scheduled', 'failed'],
        'publish' => ['draft', 'submitted', 'review', 'in_review', 'changes_requested', 'approved', 'scheduled', 'failed'],
        'archive' => ['approved', 'scheduled', 'published', 'failed'],
    ];

    /** @var list<string> */
    private const LEGACY_PUBLICATION_SOURCES = ['approved', 'scheduled'];

    /** @var list<string> */
    private const LEGACY_SCHEDULE_SOURCES = ['approved', 'scheduled', 'failed'];

    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
        private readonly ArticleRevisionService $revisions,
        private readonly ArticlePublicationReadiness $publicationReadiness,
        private readonly ArticlePublicationPropagationDiagnostics $propagationDiagnostics,
    ) {}

    public function submit(Article $article, ?User $actor = null): Article
    {
        $this->authorize($article, $actor, 'submit');
        $this->ensureTransitionAllowed($article, 'submit');

        $before = $this->state($article);

        $article->forceFill([
            'status' => 'submitted',
            'scheduled_at' => null,
            'approved_at' => null,
            'approved_by' => null,
            'submitted_at' => now(),
            'submitted_by' => $actor?->id,
            'last_editorial_activity_at' => now(),
        ])->save();

        return $this->record($article, 'article.submitted', $before, $actor);
    }

    public function moveToReview(Article $article, ?User $actor = null): Article
    {
        return $this->startReview($article, $actor);
    }

    public function startReview(Article $article, ?User $actor = null): Article
    {
        $this->authorize($article, $actor, 'review');
        $this->ensureTransitionAllowed($article, 'startReview');

        $before = $this->state($article);

        $article->forceFill([
            'status' => 'in_review',
            'review_started_at' => now(),
            'reviewed_by' => $actor?->id,
            'assigned_editor_id' => $article->assigned_editor_id ?? $actor?->id,
            'supervising_editor_id' => $article->supervising_editor_id ?? $actor?->id,
            'scheduled_at' => null,
            'approved_at' => null,
            'approved_by' => null,
            'last_editorial_activity_at' => now(),
        ])->save();

        return $this->record($article, 'article.review_started', $before, $actor);
    }

    public function requestChanges(Article $article, ?User $actor = null): Article
    {
        $this->authorize($article, $actor, 'requestChanges');
        $this->ensureTransitionAllowed($article, 'requestChanges');

        $before = $this->state($article);

        $article->forceFill([
            'status' => 'changes_requested',
            'changes_requested_at' => now(),
            'changes_requested_by' => $actor?->id,
            'scheduled_at' => null,
            'approved_at' => null,
            'approved_by' => null,
            'last_editorial_activity_at' => now(),
        ])->save();

        return $this->record($article, 'article.changes_requested', $before, $actor);
    }

    public function approve(Article $article, ?User $actor = null): Article
    {
        $this->authorize($article, $actor, 'approve');
        $this->ensureTransitionAllowed($article, 'approve');
        $this->publicationReadiness->assertReady($article, requireApproval: false);

        $before = $this->state($article);

        $article->forceFill([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $actor?->id,
            'assigned_editor_id' => $article->assigned_editor_id ?? $actor?->id,
            'supervising_editor_id' => $article->supervising_editor_id ?? $actor?->id,
            'scheduled_at' => null,
            'last_editorial_activity_at' => now(),
        ])->save();

        return $this->record($article, 'article.approved', $before, $actor);
    }

    public function schedule(Article $article, CarbonInterface|string $scheduledAt, ?User $actor = null): Article
    {
        $this->authorize($article, $actor, 'schedule');
        $this->ensureScheduleTransitionAllowed($article, $actor);
        $this->publicationReadiness->assertReady(
            $article,
            requireApproval: ! $this->canPublishDirectly($actor),
        );

        $before = $this->state($article);
        $scheduledAt = $scheduledAt instanceof CarbonInterface ? $scheduledAt : Carbon::parse($scheduledAt);

        $article->forceFill([
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
            'published_at' => null,
            'approved_at' => $article->approved_at ?? now(),
            'approved_by' => $article->approved_by ?? $actor?->id,
            'publication_failed_at' => null,
            'publication_failure_reason' => null,
            'last_editorial_activity_at' => now(),
        ])->save();

        return $this->record($article, 'article.scheduled', $before, $actor);
    }

    public function publish(Article $article, ?User $actor = null): Article
    {
        $this->authorize($article, $actor, 'publish');
        $this->ensurePublishTransitionAllowed($article, $actor);
        $this->publicationReadiness->assertReady(
            $article,
            requireApproval: ! $this->canPublishDirectly($actor),
        );

        $before = $this->state($article);

        $article->forceFill([
            'status' => 'published',
            'published_at' => now(),
            'published_by' => $actor?->id,
            'approved_at' => $article->approved_at ?? now(),
            'approved_by' => $article->approved_by ?? $actor?->id,
            'scheduled_at' => null,
            'publication_failed_at' => null,
            'publication_failure_reason' => null,
            'last_editorial_activity_at' => now(),
        ])->save();

        $article = $this->record($article, 'article.published', $before, $actor);
        $this->propagationDiagnostics->verify($article, 'editorial_workflow');

        return $article;
    }

    public function archive(Article $article, ?User $actor = null): Article
    {
        $this->authorize($article, $actor, 'publish');
        $this->ensureTransitionAllowed($article, 'archive');

        $before = $this->state($article);

        $article->forceFill([
            'status' => 'archived',
            'archived_at' => now(),
            'archived_by' => $actor?->id,
            'last_editorial_activity_at' => now(),
        ])->save();

        return $this->record($article, 'article.archived', $before, $actor);
    }

    private function authorize(Article $article, ?User $actor, string $ability): void
    {
        if ($actor === null || $actor->can($ability, $article)) {
            return;
        }

        throw new AuthorizationException("This user cannot {$ability} this article.");
    }

    public function canTransition(Article $article, string $transition): bool
    {
        $allowedSources = self::TRANSITION_SOURCES[$transition] ?? null;

        return $allowedSources !== null && in_array($article->status, $allowedSources, true);
    }

    public function canPublish(Article $article, ?User $actor = null): bool
    {
        if (! $this->canTransition($article, 'publish')) {
            return false;
        }

        if ($actor === null) {
            return in_array($article->status, self::LEGACY_PUBLICATION_SOURCES, true);
        }

        return $this->canPublishDirectly($actor);
    }

    private function canPublishDirectly(?User $actor): bool
    {
        return $actor !== null
            && ($actor->isAdmin() || $actor->hasRole('editor_in_chief') || $actor->hasRole('editor'))
            && $actor->hasPermission('articles.publish');
    }

    private function ensureTransitionAllowed(Article $article, string $transition): void
    {
        if ($this->canTransition($article, $transition)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => "Article status [{$article->status}] cannot perform the [{$transition}] transition.",
        ]);
    }

    private function ensureScheduleTransitionAllowed(Article $article, ?User $actor): void
    {
        if ($this->canTransition($article, 'schedule')
            && (
                in_array($article->status, self::LEGACY_SCHEDULE_SOURCES, true)
                || $this->canPublishDirectly($actor)
            )) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => "Article status [{$article->status}] cannot perform the [schedule] transition.",
        ]);
    }

    private function ensurePublishTransitionAllowed(Article $article, ?User $actor): void
    {
        if ($this->canPublish($article, $actor)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => "Article status [{$article->status}] cannot perform the [publish] transition.",
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function record(Article $article, string $action, array $before, ?User $actor): Article
    {
        $article = $article->refresh();

        $this->auditLogger->record(
            action: $action,
            auditable: $article,
            actor: $actor,
            before: $before,
            after: $this->state($article),
            metadata: ['surface' => 'editorial_workflow'],
        );

        $this->revisions->capture($article, $actor, $action);

        return $article;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Article $article): array
    {
        return [
            'status' => $article->status,
            'published_at' => $article->published_at?->toDateTimeString(),
            'scheduled_at' => $article->scheduled_at?->toDateTimeString(),
            'submitted_at' => $article->submitted_at?->toDateTimeString(),
            'approved_at' => $article->approved_at?->toDateTimeString(),
            'assigned_editor_id' => $article->assigned_editor_id,
            'supervising_editor_id' => $article->supervising_editor_id,
        ];
    }
}
