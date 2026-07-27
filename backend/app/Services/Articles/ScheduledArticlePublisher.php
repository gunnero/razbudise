<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Services\Operations\AdminAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduledArticlePublisher
{
    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
        private readonly ArticlePublicationReadiness $publicationReadiness,
        private readonly ArticlePublicationPropagationDiagnostics $propagationDiagnostics,
    ) {}

    public function publishDue(?Carbon $now = null): int
    {
        $now ??= now();
        $published = 0;

        Article::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')
            ->each(function (Article $article) use (&$published, $now): void {
                try {
                    $didPublish = DB::transaction(function () use ($article, $now): bool {
                        $locked = Article::query()->lockForUpdate()->findOrFail($article->id);

                        if ($locked->status !== 'scheduled' || ! $locked->scheduled_at?->lte($now)) {
                            return false;
                        }

                        // Re-read and revalidate immediately before the write so
                        // a stale queue snapshot cannot bypass a new blocker.
                        $this->publicationReadiness->assertReady($locked, requireApproval: true);
                        $before = [
                            'status' => $locked->status,
                            'published_at' => $locked->published_at?->toDateTimeString(),
                            'scheduled_at' => $locked->scheduled_at?->toDateTimeString(),
                        ];

                        $locked->forceFill([
                            'status' => 'published',
                            'published_at' => $locked->scheduled_at ?? $now,
                            'scheduled_at' => null,
                            'publication_failed_at' => null,
                            'publication_failure_reason' => null,
                            'last_editorial_activity_at' => $now,
                        ])->save();

                        $this->auditLogger->record(
                            action: 'article.published',
                            auditable: $locked,
                            actor: null,
                            before: $before,
                            after: [
                                'status' => 'published',
                                'published_at' => $locked->published_at?->toDateTimeString(),
                                'scheduled_at' => null,
                            ],
                            metadata: ['surface' => 'scheduled_article_publisher'],
                        );

                        return true;
                    });

                    if ($didPublish) {
                        $published++;
                        $fresh = $article->fresh();

                        if ($fresh) {
                            $this->propagationDiagnostics->verify($fresh, 'scheduled_article_publisher');
                        }
                    }
                } catch (ValidationException $exception) {
                    $fresh = $article->fresh();

                    if ($fresh?->status === 'scheduled') {
                        $this->failPublication(
                            $fresh,
                            $now,
                            $this->publicationReadiness->failureSummary($exception),
                        );
                    }
                }
            });

        return $published;
    }

    private function failPublication(Article $article, Carbon $now, string $reason): void
    {
        $before = [
            'status' => $article->status,
            'scheduled_at' => $article->scheduled_at?->toDateTimeString(),
            'publication_failure_reason' => $article->publication_failure_reason,
        ];

        $article->forceFill([
            'status' => 'failed',
            'publication_failed_at' => $now,
            'publication_failure_reason' => $reason,
            'last_editorial_activity_at' => $now,
        ])->save();

        $this->auditLogger->record(
            'article.publication_failed',
            $article,
            null,
            $before,
            [
                'status' => 'failed',
                'publication_failed_at' => $now->toDateTimeString(),
                'publication_failure_reason' => $reason,
            ],
            ['surface' => 'scheduled_article_publisher'],
        );
    }
}
