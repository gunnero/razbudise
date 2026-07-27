<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ArticleRevisionService
{
    private const ARTICLE_FIELDS = [
        'title', 'slug', 'excerpt', 'body_html', 'status', 'type', 'author_id', 'category_id',
        'featured_image_id', 'published_at', 'scheduled_at', 'is_sponsored', 'is_ai_generated', 'is_ai_assisted',
        'supervising_editor_id', 'assigned_editor_id', 'submitted_at', 'submitted_by', 'review_started_at',
        'reviewed_by', 'changes_requested_at', 'changes_requested_by', 'approved_at', 'approved_by',
        'published_by', 'rejected_at', 'rejected_by', 'archived_at', 'archived_by', 'publication_failed_at',
        'publication_failure_reason', 'last_editorial_activity_at',
    ];

    public function capture(Article $article, ?User $actor, string $reason = 'saved'): ArticleRevision
    {
        return DB::transaction(function () use ($article, $actor, $reason): ArticleRevision {
            $locked = Article::query()->lockForUpdate()->findOrFail($article->id);

            return $locked->revisions()->create([
                'version' => ((int) $locked->revisions()->max('version')) + 1,
                'created_by' => $actor?->id,
                'reason' => $reason,
                'snapshot' => $this->snapshot($locked),
            ]);
        });
    }

    public function restore(ArticleRevision $revision, User $actor): Article
    {
        return DB::transaction(function () use ($revision, $actor): Article {
            $article = Article::query()->lockForUpdate()->findOrFail($revision->article_id);
            $snapshot = $revision->snapshot;

            $article->fill(Arr::only($snapshot['article'] ?? [], self::ARTICLE_FIELDS));
            $article->updated_by = $actor->id;
            $article->save();
            $article->tags()->sync($snapshot['tag_ids'] ?? []);
            $article->blocks()->delete();
            $article->blocks()->createMany($snapshot['blocks'] ?? []);

            if ($snapshot['seo'] ?? null) {
                $article->seoMetadata()->updateOrCreate([], $snapshot['seo']);
            } else {
                $article->seoMetadata()->delete();
            }

            if ($snapshot['sponsor'] ?? null) {
                $article->sponsoredContentDetail()->updateOrCreate([], $snapshot['sponsor']);
            } else {
                $article->sponsoredContentDetail()->delete();
            }

            $this->capture($article, $actor, "restored revision {$revision->version}");

            return $article->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Article $article): array
    {
        $article->load(['blocks', 'tags', 'seoMetadata', 'sponsoredContentDetail']);

        return [
            'article' => Arr::only($article->attributesToArray(), self::ARTICLE_FIELDS),
            'tag_ids' => $article->tags->modelKeys(),
            'blocks' => $article->blocks->map->only(['type', 'data', 'sort_order'])->values()->all(),
            'seo' => $article->seoMetadata?->only([
                'seo_title', 'meta_description', 'canonical_url', 'og_title', 'og_description', 'og_image_id', 'breadcrumbs', 'schema_json',
            ]),
            'sponsor' => $article->sponsoredContentDetail?->only([
                'sponsor_name', 'sponsor_logo_id', 'sponsor_url', 'disclosure_label', 'campaign_start_at', 'campaign_end_at',
            ]),
        ];
    }
}
