<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleView;
use App\Services\Analytics\EditorialEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ArticleViewController extends Controller
{
    public function store(Request $request, Article $article, EditorialEventRecorder $events): JsonResponse
    {
        abort_unless($article->published()->whereKey($article)->exists(), 404);

        $validated = $request->validate([
            'visitor_token' => ['required', 'string', 'min:16', 'max:100'],
            'session_token' => ['nullable', 'string', 'min:16', 'max:100'],
            'consent_version' => ['nullable', 'integer', 'min:1', 'max:10'],
            'analytics_consent' => ['required', 'accepted'],
            'context_page' => ['nullable', 'string', Rule::in(EditorialEventRecorder::CONTEXTS)],
            'referrer' => ['nullable', 'url', 'max:2048'],
            'utm_source' => ['nullable', 'string', 'max:190'],
            'utm_medium' => ['nullable', 'string', 'max:190'],
            'utm_campaign' => ['nullable', 'string', 'max:190'],
        ]);

        if (! $events->allows($request, $validated)) {
            return response()->json(['recorded' => false], 202);
        }

        $visitorHash = hash_hmac('sha256', $validated['visitor_token'], (string) config('app.key'));
        $returning = ArticleView::query()
            ->where('visitor_hash', $visitorHash)
            ->where('viewed_on', '<', today()->toDateString())
            ->exists();
        $referrerHost = $this->referrerHost($validated['referrer'] ?? null);
        $context = $validated['context_page'] ?? 'article';
        $sessionToken = trim((string) ($validated['session_token'] ?? ''));

        ArticleView::query()->firstOrCreate([
            'article_id' => $article->id,
            'visitor_hash' => $visitorHash,
            'viewed_on' => today()->toDateString(),
        ], [
            'session_hash' => $sessionToken !== ''
                ? hash_hmac('sha256', $sessionToken, (string) config('app.key'))
                : null,
            'occurred_at' => now(),
            'referrer_host' => $referrerHost,
            'source_channel' => $events->sourceChannel($validated, $context, $referrerHost),
            'context_page' => $context,
            'device' => $this->device((string) $request->userAgent()),
            'is_returning' => $returning,
        ]);

        $events->record($request, [
            ...$validated,
            'event_type' => 'article_open',
            'consent_version' => (int) ($validated['consent_version'] ?? 1),
            'context_page' => $context,
        ], $article);

        return response()->json(['recorded' => true], 202);
    }

    private function referrerHost(?string $referrer): ?string
    {
        return $referrer ? parse_url($referrer, PHP_URL_HOST) ?: null : null;
    }

    private function device(string $userAgent): string
    {
        if (preg_match('/ipad|tablet/i', $userAgent)) {
            return 'tablet';
        }

        return preg_match('/mobile|iphone|android/i', $userAgent) ? 'mobile' : 'desktop';
    }
}
