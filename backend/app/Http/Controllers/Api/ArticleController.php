<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleCardResource;
use App\Http\Resources\ArticleDetailResource;
use App\Models\Article;
use App\Services\Search\ArticleSearchService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ArticleController extends Controller
{
    public function index(Request $request, ArticleSearchService $search): AnonymousResourceCollection
    {
        $articles = $search->databaseSearch('', [
            'type' => $request->query('type'),
            'category' => $request->query('category'),
            'tag' => $request->query('tag'),
            'author' => $request->query('author'),
            'sponsored' => $request->query('sponsored'),
            'published_from' => $request->query('published_from'),
            'published_to' => $request->query('published_to'),
        ], $request->integer('per_page', 12));

        return ArticleCardResource::collection($articles);
    }

    public function events(): AnonymousResourceCollection
    {
        $articles = Article::query()
            ->published()
            ->where('type', 'event')
            ->with(['author', 'category', 'featuredImage', 'sponsoredContentDetail'])
            ->latest('published_at')
            ->paginate(request()->integer('per_page', 12));

        return ArticleCardResource::collection($articles);
    }

    public function show(string $slug): ArticleDetailResource
    {
        $article = Article::query()
            ->published()
            ->where('slug', $slug)
            ->with([
                'author.avatar',
                'category',
                'featuredImage',
                'blocks',
                'tags',
                'seoMetadata.ogImage',
                'sponsoredContentDetail.sponsorLogo',
            ])
            ->firstOrFail();

        return ArticleDetailResource::make($article);
    }

    public function related(string $slug): AnonymousResourceCollection
    {
        $article = Article::query()
            ->published()
            ->where('slug', $slug)
            ->with(['tags'])
            ->firstOrFail();
        $limit = request()->integer('limit', 3);

        $related = $article->presentation_type === 'event_guide'
            ? $this->eventGuideRelated($article, $limit)
            : Article::query()
                ->published()
                ->whereKeyNot($article->id)
                ->where('category_id', $article->category_id)
                ->with(['author', 'category', 'featuredImage', 'sponsoredContentDetail'])
                ->latest('published_at')
                ->limit($limit)
                ->get();

        if ($related->count() < $limit) {
            $fallback = Article::query()
                ->published()
                ->whereKeyNot($article->id)
                ->whereNotIn('id', $related->pluck('id'))
                ->with(['author', 'category', 'featuredImage', 'sponsoredContentDetail'])
                ->latest('published_at')
                ->limit($limit - $related->count())
                ->get();

            $related = $related->concat($fallback)->values();
        }

        return ArticleCardResource::collection($related);
    }

    /**
     * @return Collection<int, Article>
     */
    private function eventGuideRelated(Article $article, int $limit): Collection
    {
        $related = new Collection;
        $tagIds = $article->tags->pluck('id')->all();

        $stages = [
            fn () => Article::query()->where('presentation_type', 'event_guide'),
            fn () => Article::query()->where('category_id', $article->category_id),
            fn () => Article::query()->whereIn('type', ['event', 'guide']),
            fn () => Article::query()->whereHas('tags', fn ($query) => $query->whereIn('tags.id', $tagIds)),
            fn () => Article::query()->where('author_id', $article->author_id),
        ];

        foreach ($stages as $stage) {
            if ($related->count() >= $limit) {
                break;
            }

            $next = $stage()
                ->published()
                ->whereKeyNot($article->id)
                ->whereNotIn('id', $related->pluck('id'))
                ->with(['author', 'category', 'featuredImage', 'sponsoredContentDetail'])
                ->latest('published_at')
                ->limit($limit - $related->count())
                ->get();

            $related = $related->concat($next)->values();
        }

        return $related;
    }
}
