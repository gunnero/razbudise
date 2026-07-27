<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\HomepageSection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ArticlePublicationPropagationDiagnostics
{
    /**
     * Verify every database-backed public discovery surface immediately after
     * publication and leave structured context when propagation is incomplete.
     * Frontend editorial reads intentionally use no-store requests, so a valid
     * database result is immediately visible without an operational cache clear.
     *
     * @return array<string, bool|int|string|null|array<int, string>>
     */
    public function verify(Article $article, string $surface): array
    {
        try {
            return $this->inspectAndLog($article, $surface);
        } catch (Throwable $exception) {
            $context = [
                'article_id' => $article->id,
                'slug' => $article->slug,
                'status' => $article->status,
                'published_at' => $article->published_at?->toIso8601String(),
                'author_id' => $article->author_id,
                'category_id' => $article->category_id,
                'surface' => $surface,
                'checks' => [],
                'failures' => ['diagnostic_exception'],
                'exception' => $exception::class,
            ];

            // Publication is already committed at this point. A diagnostic
            // failure must be observable, but must never roll back or interrupt
            // a successfully published article.
            Log::error('Article publication propagation diagnostics failed.', $context);

            return $context;
        }
    }

    /** @return array<string, bool|int|string|null|array<int, string>|array<string, bool>> */
    private function inspectAndLog(Article $article, string $surface): array
    {
        $article = $article->fresh(['author', 'category', 'categories']) ?? $article;

        $checks = [
            'public_route' => Article::query()->published()->whereKey($article->id)->exists(),
            'category_archive' => $this->isVisibleInCategoryArchive($article),
            'author_archive' => $this->isVisibleInAuthorArchive($article),
            'homepage_eligible' => $this->isHomepageEligible($article),
            'search_eligible' => $article->shouldBeSearchable(),
        ];
        $failures = collect($checks)
            ->filter(fn (bool $passed): bool => ! $passed)
            ->keys()
            ->values()
            ->all();
        $context = [
            'article_id' => $article->id,
            'slug' => $article->slug,
            'status' => $article->status,
            'published_at' => $article->published_at?->toIso8601String(),
            'author_id' => $article->author_id,
            'category_id' => $article->category_id,
            'surface' => $surface,
            'checks' => $checks,
            'failures' => $failures,
        ];

        if ($failures !== []) {
            Log::warning('Article publication propagation check failed.', $context);
        } else {
            Log::info('Article publication propagation verified.', $context);
        }

        return $context;
    }

    private function isVisibleInCategoryArchive(Article $article): bool
    {
        if (! $article->category_id || ! $article->category?->is_active) {
            return false;
        }

        return Article::query()
            ->published()
            ->inCategory($article->category_id)
            ->whereKey($article->id)
            ->exists();
    }

    private function isVisibleInAuthorArchive(Article $article): bool
    {
        if (! $article->author_id || ! $article->author?->is_active) {
            return false;
        }

        return $article->author
            ->articles()
            ->published()
            ->whereKey($article->id)
            ->exists();
    }

    private function isHomepageEligible(Article $article): bool
    {
        $sections = HomepageSection::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($sections->isEmpty()) {
            return true;
        }

        $articleCategorySlugs = collect([$article->category?->slug])
            ->merge($article->categories->pluck('slug'))
            ->filter()
            ->unique();

        return $sections->contains(function (HomepageSection $section) use ($article, $articleCategorySlugs): bool {
            $configuration = $section->configuration ?? [];

            if ($section->type === 'manual') {
                return in_array($article->slug, $configuration['article_slugs'] ?? [], true);
            }

            if ($section->type === 'category') {
                return $articleCategorySlugs->contains($configuration['category_slug'] ?? null);
            }

            if ($section->type !== 'latest') {
                return false;
            }

            if (($configuration['exclude_default_category'] ?? true)
                && in_array($article->category?->slug, ['uncategorized', 'pocetna'], true)) {
                return false;
            }

            $freshnessDays = max(0, min((int) ($configuration['freshness_days'] ?? 0), 3650));

            return $freshnessDays === 0
                || $article->published_at?->gte(now()->subDays($freshnessDays))
                || ($configuration['fallback_strategy'] ?? 'evergreen') === 'evergreen';
        });
    }
}
