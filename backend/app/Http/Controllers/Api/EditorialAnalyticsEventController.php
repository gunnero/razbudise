<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\Analytics\EditorialEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EditorialAnalyticsEventController extends Controller
{
    public function store(Request $request, EditorialEventRecorder $recorder): JsonResponse
    {
        $validated = $request->validate([
            'event_type' => ['required', 'string', Rule::in(EditorialEventRecorder::EVENT_TYPES)],
            'article_slug' => ['nullable', 'string', 'max:255'],
            'visitor_token' => ['required', 'string', 'min:16', 'max:100'],
            'session_token' => ['nullable', 'string', 'min:16', 'max:100'],
            'consent_version' => ['required', 'integer', 'min:1', 'max:10'],
            'analytics_consent' => ['required', 'accepted'],
            'context_page' => ['nullable', 'string', Rule::in(EditorialEventRecorder::CONTEXTS)],
            'referrer' => ['nullable', 'url', 'max:2048'],
            'target_url' => ['nullable', 'url', 'max:2048'],
            'engaged_ms' => ['nullable', 'required_if:event_type,engaged_session', 'integer', 'min:1000', 'max:1800000'],
            'scroll_depth' => ['nullable', 'required_if:event_type,scroll_depth', 'integer', Rule::in([25, 50, 75, 100])],
            'utm_source' => ['nullable', 'string', 'max:190'],
            'utm_medium' => ['nullable', 'string', 'max:190'],
            'utm_campaign' => ['nullable', 'string', 'max:190'],
            'content_key' => ['nullable', 'string', 'max:190'],
        ]);

        $article = null;
        if (filled($validated['article_slug'] ?? null)) {
            $article = Article::query()->published()->where('slug', $validated['article_slug'])->firstOrFail();
        }

        if ($this->requiresArticle((string) $validated['event_type']) && ! $article) {
            abort(422, 'This analytics event requires a published article.');
        }

        $event = $recorder->record($request, $validated, $article);

        return response()->json(['recorded' => $event !== null], 202);
    }

    private function requiresArticle(string $eventType): bool
    {
        return in_array($eventType, [
            'article_open', 'engaged_session', 'scroll_depth', 'completion',
            'homepage_article_click', 'category_article_click', 'author_article_click',
            'search_article_click', 'related_article_click',
        ], true);
    }
}
