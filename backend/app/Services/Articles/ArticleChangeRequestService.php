<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\ArticleChangeRequest;
use App\Models\ArticleEditorialComment;
use App\Models\User;
use App\Services\Operations\AdminAuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ArticleChangeRequestService
{
    public function __construct(
        private readonly ArticleEditorialWorkflow $workflow,
        private readonly AdminAuditLogger $auditLogger,
    ) {}

    /**
     * @param array{
     *     summary?: mixed,
     *     deadline_at?: mixed,
     *     comments?: array<int, array{body?: mixed, is_blocking?: mixed}>
     * } $data
     */
    public function request(Article $article, User $actor, array $data): Article
    {
        $validated = Validator::make($data, [
            'summary' => ['required', 'string', 'max:2000'],
            'deadline_at' => ['nullable', 'date', 'after_or_equal:today'],
            'comments' => ['required', 'array', 'min:1', 'max:30'],
            'comments.*.body' => ['required', 'string', 'max:5000'],
            'comments.*.is_blocking' => ['sometimes', 'boolean'],
        ], [
            'summary.required' => 'Внесете јасно резиме на потребната доработка.',
            'deadline_at.after_or_equal' => 'Рокот не може да биде во минатото.',
            'comments.required' => 'Додајте најмалку една конкретна забелешка.',
            'comments.min' => 'Додајте најмалку една конкретна забелешка.',
            'comments.*.body.required' => 'Секоја забелешка мора да содржи конкретна насока.',
        ])->validate();

        return DB::transaction(function () use ($article, $actor, $validated): Article {
            $changeRequest = $article->changeRequests()->create([
                'requested_by' => $actor->id,
                'summary' => trim($validated['summary']),
                'deadline_at' => $validated['deadline_at'] ?? null,
            ]);

            foreach ($validated['comments'] as $comment) {
                $article->editorialComments()->create([
                    'author_id' => $actor->id,
                    'change_request_id' => $changeRequest->id,
                    'body' => trim((string) $comment['body']),
                    'visibility' => ArticleEditorialComment::VISIBILITY_AUTHOR,
                    'is_blocking' => (bool) ($comment['is_blocking'] ?? false),
                    'is_resolved' => false,
                ]);
            }

            $article = $this->workflow->requestChanges($article, $actor);

            $this->auditLogger->record(
                action: 'article.change_request_created',
                auditable: $article,
                actor: $actor,
                before: [],
                after: [
                    'change_request_id' => $changeRequest->id,
                    'summary' => $changeRequest->summary,
                    'deadline_at' => $changeRequest->deadline_at?->toDateString(),
                    'comment_count' => count($validated['comments']),
                    'blocking_comment_count' => collect($validated['comments'])
                        ->where('is_blocking', true)
                        ->count(),
                ],
                metadata: ['surface' => 'requested_changes_modal'],
            );

            return $article;
        });
    }

    public function submitForReview(Article $article, User $actor): Article
    {
        return DB::transaction(function () use ($article, $actor): Article {
            $isResubmission = $article->status === 'changes_requested';
            $changeRequest = $isResubmission
                ? $article->changeRequests()->whereNull('resubmitted_at')->latest()->first()
                : null;

            $article = $this->workflow->submit($article, $actor);

            if ($changeRequest instanceof ArticleChangeRequest) {
                $changeRequest->forceFill([
                    'resubmitted_at' => now(),
                    'resubmitted_by' => $actor->id,
                ])->save();

                $this->auditLogger->record(
                    action: 'article.change_request_resubmitted',
                    auditable: $article,
                    actor: $actor,
                    before: ['change_request_id' => $changeRequest->id, 'resubmitted_at' => null],
                    after: [
                        'change_request_id' => $changeRequest->id,
                        'resubmitted_at' => $changeRequest->resubmitted_at?->toDateTimeString(),
                    ],
                    metadata: ['surface' => 'editorial_workflow'],
                );
            }

            return $article;
        });
    }

    public function markAddressed(ArticleEditorialComment $comment, User $actor): ArticleEditorialComment
    {
        $comment->loadMissing('article');

        if (
            $comment->visibility !== ArticleEditorialComment::VISIBILITY_AUTHOR
            || $comment->parent_id !== null
            || $comment->is_resolved
            || ! $actor->can('update', $comment->article)
        ) {
            throw new AuthorizationException('This comment cannot be marked as addressed by this user.');
        }

        $before = [
            'comment_id' => $comment->id,
            'addressed_at' => $comment->addressed_at?->toDateTimeString(),
            'addressed_by' => $comment->addressed_by,
        ];

        $comment->forceFill([
            'addressed_at' => now(),
            'addressed_by' => $actor->id,
        ])->save();

        $this->auditLogger->record(
            action: 'article.comment_addressed',
            auditable: $comment->article,
            actor: $actor,
            before: $before,
            after: [
                'comment_id' => $comment->id,
                'addressed_at' => $comment->addressed_at?->toDateTimeString(),
                'addressed_by' => $comment->addressed_by,
            ],
            metadata: ['surface' => 'editorial_comments'],
        );

        return $comment->refresh();
    }
}
