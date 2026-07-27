<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthorArticleResource;
use App\Http\Resources\AuthorDetailResource;
use App\Http\Resources\AuthorListResource;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class AuthorController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $search = trim(request()->string('search')->toString());
        $sort = request()->string('sort', 'recent')->toString();
        $sort = in_array($sort, ['recent', 'active', 'articles', 'alphabetical'], true) ? $sort : 'recent';

        $query = Author::query()
            ->active()
            ->with('avatar')
            ->with(['latestPublishedArticle.category'])
            ->withCount([
                'articles as published_articles_count' => fn ($query) => $query->published(),
                'articles as recent_articles_count' => fn ($query) => $query->published()->where('published_at', '>=', now()->subDays(90)),
            ])
            ->withMax(['articles as latest_published_at' => fn ($query) => $query->published()], 'published_at')
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'));

        match ($sort) {
            'active' => $query->orderByDesc('recent_articles_count')->orderByRaw('CASE WHEN latest_published_at IS NULL THEN 1 ELSE 0 END')->orderByDesc('latest_published_at'),
            'articles' => $query->orderByDesc('published_articles_count')->orderByRaw('CASE WHEN latest_published_at IS NULL THEN 1 ELSE 0 END')->orderByDesc('latest_published_at'),
            'alphabetical' => $query->orderBy('name'),
            default => $query->orderByRaw('CASE WHEN latest_published_at IS NULL THEN 1 ELSE 0 END')->orderByDesc('latest_published_at'),
        };

        $authors = $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(min(max(request()->integer('per_page', 12), 1), 48))
            ->withQueryString();

        $this->loadEditorialFocus($authors->getCollection());

        $activeAuthors = Author::query()->active();
        $publishedArticleCount = (clone $activeAuthors)
            ->withCount(['articles as published_articles_count' => fn ($query) => $query->published()])
            ->get()
            ->sum('published_articles_count');

        return AuthorListResource::collection($authors)->additional([
            'directory_stats' => [
                'author_count' => (clone $activeAuthors)->count(),
                'published_article_count' => $publishedArticleCount,
                'active_contributor_count' => (clone $activeAuthors)
                    ->whereHas('articles', fn ($query) => $query->published()->where('published_at', '>=', now()->subDays(90)))
                    ->count(),
            ],
        ]);
    }

    public function show(string $slug): AuthorDetailResource
    {
        $author = $this->findActiveAuthor($slug)
            ->load(['avatar', 'seoMetadata.ogImage', 'latestPublishedArticle.category'])
            ->loadCount([
                'articles as published_articles_count' => fn ($query) => $query->published(),
            ]);

        $this->loadEditorialFocus(collect([$author]));

        return AuthorDetailResource::make($author);
    }

    public function articles(string $slug): AnonymousResourceCollection
    {
        $author = $this->findActiveAuthor($slug);

        $articles = $author->articles()
            ->published()
            ->with(['category', 'featuredImage', 'sponsoredContentDetail'])
            ->latest('published_at')
            ->paginate(request()->integer('per_page', 12));

        return AuthorArticleResource::collection($articles);
    }

    private function findActiveAuthor(string $slug): Author
    {
        return Author::query()
            ->active()
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /** @param Collection<int, Author> $authors */
    private function loadEditorialFocus(Collection $authors): void
    {
        if ($authors->isEmpty()) {
            return;
        }

        $focusByAuthor = Category::query()
            ->select(['categories.id', 'categories.name', 'categories.slug', 'articles.author_id'])
            ->selectRaw('COUNT(articles.id) as published_articles_count')
            ->join('articles', 'articles.category_id', '=', 'categories.id')
            ->whereIn('articles.author_id', $authors->pluck('id')->all())
            ->where('articles.status', 'published')
            ->whereNotNull('articles.published_at')
            ->where('articles.published_at', '<=', now())
            ->groupBy('categories.id', 'categories.name', 'categories.slug', 'articles.author_id')
            ->orderByDesc('published_articles_count')
            ->orderBy('categories.name')
            ->get()
            ->groupBy('author_id');

        $authors->each(function (Author $author) use ($focusByAuthor): void {
            $author->setRelation('editorialFocus', ($focusByAuthor->get($author->id) ?? collect())->take(3)->values());
        });
    }
}
