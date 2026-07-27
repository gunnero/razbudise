<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleCardResource;
use App\Http\Resources\AuthorListResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\TagResource;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Tag;
use App\Services\Search\ArticleSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SearchController extends Controller
{
    public function __invoke(Request $request, ArticleSearchService $search): JsonResponse
    {
        $query = trim((string) $request->query('query', ''));
        $category = $this->stringFilter($request, 'category');
        $tag = $this->stringFilter($request, 'tag');
        $author = $this->stringFilter($request, 'author');
        $type = $this->stringFilter($request, 'type');
        $sponsored = $this->booleanFilter($request, 'sponsored');
        $publishedFrom = $this->stringFilter($request, 'published_from');
        $publishedTo = $this->stringFilter($request, 'published_to');
        $matchingArticleIds = $this->matchingArticleIds($query);

        $articles = $search->search($query, [
            'category' => $category,
            'tag' => $tag,
            'author' => $author,
            'type' => $type,
            'sponsored' => $sponsored,
            'published_from' => $publishedFrom,
            'published_to' => $publishedTo,
        ], $request->integer('per_page', 12));

        return response()->json([
            'data' => [
                'query' => $query,
                'filters' => [
                    'selected' => [
                        'category' => $category,
                        'tag' => $tag,
                        'author' => $author,
                        'type' => $type,
                        'sponsored' => $sponsored,
                        'published_from' => $publishedFrom,
                        'published_to' => $publishedTo,
                    ],
                    'categories' => CategoryResource::collection($this->filterCategories($matchingArticleIds, $category))->resolve($request),
                    'tags' => TagResource::collection($this->filterTags($matchingArticleIds, $tag))->resolve($request),
                    'authors' => AuthorListResource::collection($this->filterAuthors($matchingArticleIds, $author))->resolve($request),
                ],
                'articles' => ArticleCardResource::collection($articles)->response()->getData(true),
            ],
        ]);
    }

    private function stringFilter(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        return $value === '' ? null : $value;
    }

    private function booleanFilter(Request $request, string $key): ?bool
    {
        if (! $request->has($key)) {
            return null;
        }

        return filter_var($request->query($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @return Collection<int, int>
     */
    private function matchingArticleIds(string $query): Collection
    {
        return Article::query()
            ->published()
            ->when($query !== '', fn (Builder $builder) => $this->applySearchToRelation($builder, $query))
            ->pluck('id');
    }

    /**
     * @param  Collection<int, int>  $matchingArticleIds
     */
    private function filterCategories(Collection $matchingArticleIds, ?string $selected)
    {
        if ($matchingArticleIds->isEmpty()) {
            return collect();
        }

        return Category::query()
            ->where('is_active', true)
            ->whereHas('articles', fn (Builder $builder) => $builder->whereIn('articles.id', $matchingArticleIds))
            ->with('seoMetadata.ogImage')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Category $category): int => $category->slug === $selected ? 0 : 1)
            ->values();
    }

    /**
     * @param  Collection<int, int>  $matchingArticleIds
     */
    private function filterTags(Collection $matchingArticleIds, ?string $selected)
    {
        if ($matchingArticleIds->isEmpty()) {
            return collect();
        }

        return Tag::query()
            ->select('tags.*')
            ->join('article_tag', 'article_tag.tag_id', '=', 'tags.id')
            ->whereIn('article_tag.article_id', $matchingArticleIds)
            ->with('seoMetadata.ogImage')
            ->distinct()
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Tag $tag): int => $tag->slug === $selected ? 0 : 1)
            ->values();
    }

    /**
     * @param  Collection<int, int>  $matchingArticleIds
     */
    private function filterAuthors(Collection $matchingArticleIds, ?string $selected)
    {
        if ($matchingArticleIds->isEmpty()) {
            return collect();
        }

        return Author::query()
            ->active()
            ->whereHas('articles', fn (Builder $builder) => $builder->whereIn('articles.id', $matchingArticleIds))
            ->with('avatar')
            ->withCount([
                'articles as published_articles_count' => fn (Builder $builder) => $builder->whereIn('articles.id', $matchingArticleIds),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Author $author): int => $author->slug === $selected ? 0 : 1)
            ->values();
    }

    private function applySearchToRelation(Builder $builder, string $query): Builder
    {
        if ($query === '') {
            return $builder;
        }

        return $builder->where(function (Builder $inner) use ($query): void {
            $inner
                ->where('title', 'like', "%{$query}%")
                ->orWhere('excerpt', 'like', "%{$query}%")
                ->orWhere('body_html', 'like', "%{$query}%");
        });
    }
}
