<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\OneFiveFourArticleIngestionRequest;
use App\Services\Integrations\OneFiveFourArticleIngestionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OneFiveFourArticleController extends Controller
{
    public function store(
        OneFiveFourArticleIngestionRequest $request,
        OneFiveFourArticleIngestionService $ingestion,
    ): JsonResponse {
        $result = $ingestion->ingest($request->validated());
        $article = $result['article'];

        return response()->json([
            'data' => [
                'article_id' => $article->id,
                'slug' => $article->slug,
                'status' => $article->status,
                'preview_url' => $article->previewUrl(),
                'edit_url' => url("/backoffice/articles/{$article->id}/edit"),
            ],
        ], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
