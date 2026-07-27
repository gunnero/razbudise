<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleCardResource;
use App\Http\Resources\CategoryResource;
use App\Models\Article;
use App\Models\Category;
use App\Models\WordpressImportMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->with('seoMetadata.ogImage')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(request()->integer('per_page', 24));

        return CategoryResource::collection($categories);
    }

    public function show(string $slug): CategoryResource
    {
        return CategoryResource::make($this->findActiveCategory($slug)->load('seoMetadata.ogImage'));
    }

    public function articles(string $slug): JsonResponse
    {
        $category = $this->findActiveCategory($slug)->load('seoMetadata.ogImage');

        $articles = Article::query()
            ->inCategory($category)
            ->published()
            ->with(['author', 'category', 'featuredImage'])
            ->latest('published_at')
            ->paginate(request()->integer('per_page', 12));

        return response()->json([
            'data' => [
                'category' => CategoryResource::make($category),
                'articles' => ArticleCardResource::collection($articles)->response()->getData(true),
            ],
        ]);
    }

    private function findActiveCategory(string $slug): Category
    {
        $category = Category::query()
            ->where('is_active', true)
            ->where('slug', $slug)
            ->first();

        if ($category) {
            return $category;
        }

        $candidates = $this->categorySlugCandidates($slug);

        $category = Category::query()
            ->where('is_active', true)
            ->whereIn('slug', $candidates)
            ->first();

        if ($category) {
            return $category;
        }

        $mapping = WordpressImportMapping::query()
            ->where('source_type', 'category')
            ->where('target_type', Category::class)
            ->whereIn('source_slug', $candidates)
            ->with('target')
            ->first();

        $target = $mapping?->target;

        if ($target instanceof Category && $target->is_active) {
            return $target;
        }

        abort(404);
    }

    /**
     * @return array<int, string>
     */
    private function categorySlugCandidates(string $slug): array
    {
        $decoded = rawurldecode($slug);

        return collect([
            $slug,
            Str::lower($slug),
            str_replace('_', '-', $slug),
            $decoded,
            Str::lower($decoded),
            rawurlencode($decoded),
            Str::lower(rawurlencode($decoded)),
            Str::slug($decoded),
        ])
            ->map(fn (string $candidate): string => trim($candidate))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
