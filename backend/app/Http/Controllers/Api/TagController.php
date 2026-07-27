<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleCardResource;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TagController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $tags = Tag::query()
            ->with('seoMetadata.ogImage')
            ->orderBy('name')
            ->paginate(request()->integer('per_page', 48));

        return TagResource::collection($tags);
    }

    public function show(string $slug): TagResource
    {
        return TagResource::make($this->findTag($slug)->load('seoMetadata.ogImage'));
    }

    public function articles(string $slug): JsonResponse
    {
        $tag = $this->findTag($slug)->load('seoMetadata.ogImage');

        $articles = $tag->articles()
            ->published()
            ->with(['author', 'category', 'featuredImage'])
            ->latest('published_at')
            ->paginate(request()->integer('per_page', 12));

        return response()->json([
            'data' => [
                'tag' => TagResource::make($tag),
                'articles' => ArticleCardResource::collection($articles)->response()->getData(true),
            ],
        ]);
    }

    private function findTag(string $slug): Tag
    {
        return Tag::query()
            ->where('slug', $slug)
            ->firstOrFail();
    }
}
