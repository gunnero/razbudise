<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleDetailResource;
use App\Models\Article;
use Illuminate\Http\JsonResponse;

class ArticlePreviewController extends Controller
{
    public function __invoke(Article $article): JsonResponse
    {
        abort_if($article->status === 'archived', 404);

        $article->load([
            'author',
            'category',
            'featuredImage',
            'blocks',
            'tags',
            'seoMetadata.ogImage',
            'sponsoredContentDetail.sponsorLogo',
        ]);

        return ArticleDetailResource::make($article)
            ->response()
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
