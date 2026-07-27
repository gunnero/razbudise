<?php

namespace App\Services\Seo;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Redirect;
use App\Models\SiteSetting;
use App\Models\Tag;
use App\Support\UrlPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SeoHealthCheckService
{
    /**
     * @return array{
     *     totals: array{articles_checked: int, categories_checked: int, tags_checked: int, authors_checked: int, issues: int, issues_by_severity: array{critical: int, warning: int, nice_to_have: int}},
     *     issues: array<int, array<string, mixed>>
     * }
     */
    public function summary(): array
    {
        $issues = [];
        $hasDefaultOgImage = $this->hasDefaultOgImage();
        $articles = Article::query()
            ->published()
            ->select(['id', 'title', 'slug', 'excerpt', 'status', 'published_at', 'featured_image_id', 'old_url'])
            ->with([
                'featuredImage:id',
                'seoMetadata:id,seoable_type,seoable_id,meta_description,canonical_url,og_image_id',
                'seoMetadata.ogImage:id',
            ])
            ->orderByDesc('published_at')
            ->get();

        foreach ($articles as $article) {
            $description = trim((string) ($article->seoMetadata?->meta_description ?: $article->excerpt));

            if ($description === '') {
                $issues[] = $this->articleIssue($article, 'missing_meta_description', 'Published article is missing a meta description or excerpt fallback.');
            }

            if (! $hasDefaultOgImage && ! $article->seoMetadata?->ogImage && ! $article->featuredImage) {
                $issues[] = $this->articleIssue($article, 'missing_og_image', 'Published article is missing an Open Graph or featured image.');
            }

            if ($article->seoMetadata?->canonical_url
                && ! $this->canonicalUrlMatchesPublicUrl((string) $article->seoMetadata->canonical_url, $article)) {
                $issues[] = $this->articleIssue($article, 'stale_canonical_url', 'Article canonical URL does not match the configured public frontend URL.');
            }

            if ($article->old_url
                && ! $this->oldUrlMatchesPublicUrl((string) $article->old_url, $article)
                && ! $this->hasActiveRedirect((string) $article->old_url)) {
                $issues[] = $this->articleIssue($article, 'missing_redirect', 'Imported article has an old URL without an active 301 redirect.');
            }
        }

        $categories = Category::query()
            ->where('is_active', true)
            ->select(['id', 'name', 'slug', 'description', 'is_active', 'sort_order'])
            ->with('seoMetadata')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        foreach ($categories as $category) {
            $description = trim((string) ($category->seoMetadata?->meta_description ?: $category->description));

            if ($description === '') {
                $issues[] = $this->publicPageIssue(
                    $category,
                    'category',
                    'category_missing_meta_description',
                    'Public category page is missing a meta description or description fallback.',
                    "/category/{$category->slug}",
                );
            }

            if ($category->seoMetadata?->canonical_url
                && ! $this->canonicalUrlMatchesPublicPath((string) $category->seoMetadata->canonical_url, "/category/{$category->slug}")) {
                $issues[] = $this->publicPageIssue(
                    $category,
                    'category',
                    'category_stale_canonical_url',
                    'Category canonical URL does not match the configured public frontend URL.',
                    "/category/{$category->slug}",
                );
            }
        }

        $tags = Tag::query()
            ->select(['id', 'name', 'slug'])
            ->with('seoMetadata')
            ->orderBy('name')
            ->get();

        foreach ($tags as $tag) {
            $description = trim((string) $tag->seoMetadata?->meta_description);

            if ($description === '') {
                $issues[] = $this->publicPageIssue(
                    $tag,
                    'tag',
                    'tag_missing_meta_description',
                    'Public tag page is missing a meta description.',
                    "/tag/{$tag->slug}",
                );
            }

            if ($tag->seoMetadata?->canonical_url
                && ! $this->canonicalUrlMatchesPublicPath((string) $tag->seoMetadata->canonical_url, "/tag/{$tag->slug}")) {
                $issues[] = $this->publicPageIssue(
                    $tag,
                    'tag',
                    'tag_stale_canonical_url',
                    'Tag canonical URL does not match the configured public frontend URL.',
                    "/tag/{$tag->slug}",
                );
            }
        }

        $authors = Author::query()
            ->active()
            ->select(['id', 'name', 'slug', 'short_bio', 'is_active', 'sort_order'])
            ->with('seoMetadata')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        foreach ($authors as $author) {
            $description = trim((string) ($author->seoMetadata?->meta_description ?: $author->short_bio));

            if ($description === '') {
                $issues[] = $this->publicPageIssue(
                    $author,
                    'author',
                    'author_missing_meta_description',
                    'Public author page is missing a meta description or short bio fallback.',
                    "/profil/{$author->slug}",
                );
            }

            if ($author->seoMetadata?->canonical_url
                && ! $this->canonicalUrlMatchesPublicPath((string) $author->seoMetadata->canonical_url, "/profil/{$author->slug}")) {
                $issues[] = $this->publicPageIssue(
                    $author,
                    'author',
                    'author_stale_canonical_url',
                    'Author canonical URL does not match the configured public frontend URL.',
                    "/profil/{$author->slug}",
                );
            }
        }

        return [
            'totals' => [
                'articles_checked' => $articles->count(),
                'categories_checked' => $categories->count(),
                'tags_checked' => $tags->count(),
                'authors_checked' => $authors->count(),
                'issues' => count($issues),
                'issues_by_severity' => $this->issuesBySeverity($issues),
            ],
            'issues' => $issues,
        ];
    }

    private function hasActiveRedirect(string $oldUrl): bool
    {
        $path = parse_url($oldUrl, PHP_URL_PATH) ?: $oldUrl;

        return Redirect::query()
            ->where('is_active', true)
            ->where('status_code', 301)
            ->where(function (Builder $query) use ($oldUrl, $path): void {
                $query
                    ->where('source_url', $oldUrl)
                    ->orWhere('source_url', $path);
            })
            ->exists();
    }

    private function oldUrlMatchesPublicUrl(string $oldUrl, Article $article): bool
    {
        return $this->normalizedPath($oldUrl) !== ''
            && $this->normalizedPath($oldUrl) === $this->normalizedPath($article->publicUrl());
    }

    private function canonicalUrlMatchesPublicUrl(string $canonicalUrl, Article $article): bool
    {
        return $this->normalizedUrl($canonicalUrl) !== ''
            && $this->normalizedUrl($canonicalUrl) === $this->normalizedUrl($article->publicUrl());
    }

    private function canonicalUrlMatchesPublicPath(string $canonicalUrl, string $path): bool
    {
        return $this->normalizedUrl($canonicalUrl) !== ''
            && $this->normalizedUrl($canonicalUrl) === $this->normalizedUrl($this->publicUrlForPath($path));
    }

    private function normalizedUrl(?string $url): string
    {
        return rtrim(trim((string) $url), '/');
    }

    private function normalizedPath(?string $url): string
    {
        return UrlPath::normalize($url);
    }

    /**
     * @return array<string, mixed>
     */
    private function articleIssue(Article $article, string $code, string $message): array
    {
        return [
            'code' => $code,
            'severity' => $this->severityForIssue($code),
            'message' => $message,
            'article_id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'url' => $article->publicUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPageIssue(Model $model, string $type, string $code, string $message, string $path): array
    {
        return [
            'code' => $code,
            'severity' => $this->severityForIssue($code),
            'message' => $message,
            'type' => $type,
            'id' => $model->getKey(),
            'title' => $model->getAttribute('title') ?? $model->getAttribute('name'),
            'slug' => $model->getAttribute('slug'),
            'url' => $this->publicUrlForPath($path),
        ];
    }

    private function publicUrlForPath(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }

    private function hasDefaultOgImage(): bool
    {
        $value = SiteSetting::query()
            ->where('key', 'default_og_image')
            ->where('is_public', true)
            ->value('value');

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     * @return array{critical: int, warning: int, nice_to_have: int}
     */
    private function issuesBySeverity(array $issues): array
    {
        $counts = [
            'critical' => 0,
            'warning' => 0,
            'nice_to_have' => 0,
        ];

        foreach ($issues as $issue) {
            $severity = (string) ($issue['severity'] ?? 'warning');
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }

        return $counts;
    }

    private function severityForIssue(string $code): string
    {
        return match ($code) {
            'missing_redirect',
            'stale_canonical_url',
            'category_stale_canonical_url',
            'tag_stale_canonical_url',
            'author_stale_canonical_url' => 'critical',
            'tag_missing_meta_description' => 'nice_to_have',
            default => 'warning',
        };
    }
}
