<?php

namespace App\Services\Analytics;

use App\Models\Article;
use App\Models\EditorialAnalyticsEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class EditorialEventRecorder
{
    public const EVENT_TYPES = [
        'article_open', 'engaged_session', 'scroll_depth', 'completion',
        'outbound_click', 'social_share', 'homepage_article_click',
        'category_article_click', 'author_article_click', 'search_article_click',
        'related_article_click', 'author_profile_click', 'source_link_click',
        'internal_cta_click', 'newsletter_signup_click', 'external_link_click',
    ];

    public const CONTEXTS = [
        'article', 'homepage', 'category_page', 'author_page', 'internal_search',
        'related_article', 'newsletter', 'external',
    ];

    public function record(Request $request, array $payload, ?Article $article = null): ?EditorialAnalyticsEvent
    {
        if (! $this->allows($request, $payload)) {
            return null;
        }

        $token = (string) $payload['visitor_token'];
        $visitorHash = hash_hmac('sha256', $token, (string) config('app.key'));
        $occurredAt = now();
        $eventType = (string) $payload['event_type'];
        $context = Arr::get($payload, 'context_page');
        $referrerHost = $this->host(Arr::get($payload, 'referrer'));
        $source = $this->sourceChannel($payload, $context, $referrerHost);
        $device = $this->device((string) $request->userAgent());
        $targetHost = $this->host(Arr::get($payload, 'target_url'));
        $sessionToken = trim((string) Arr::get($payload, 'session_token', ''));
        $sessionHash = $sessionToken !== ''
            ? hash_hmac('sha256', $sessionToken, (string) config('app.key'))
            : null;
        $bucketSeconds = in_array($eventType, ['outbound_click', 'social_share', 'homepage_article_click', 'category_article_click', 'author_article_click', 'search_article_click', 'related_article_click', 'author_profile_click', 'source_link_click', 'internal_cta_click', 'newsletter_signup_click', 'external_link_click'], true) ? 5 : 30;
        $bucket = intdiv($occurredAt->timestamp, $bucketSeconds);
        $dedupeKey = hash('sha256', implode('|', [
            $visitorHash,
            $eventType,
            $article?->id ?? 0,
            $context ?? '',
            $targetHost ?? '',
            hash('sha256', (string) Arr::get($payload, 'target_url', '')),
            $payload['scroll_depth'] ?? '',
            $payload['engaged_ms'] ?? '',
            $bucket,
        ]));
        $returning = EditorialAnalyticsEvent::query()
            ->where('visitor_hash', $visitorHash)
            ->where('event_date', '<', $occurredAt->toDateString())
            ->exists();

        return EditorialAnalyticsEvent::query()->firstOrCreate([
            'dedupe_key' => $dedupeKey,
        ], [
            'article_id' => $article?->id,
            'author_id' => $article?->author_id,
            'category_id' => $article?->category_id,
            'event_type' => $eventType,
            'visitor_hash' => $visitorHash,
            'session_hash' => $sessionHash,
            'event_date' => $occurredAt->toDateString(),
            'occurred_at' => $occurredAt,
            'context_page' => $context,
            'source_channel' => $source,
            'referrer_host' => $referrerHost,
            'device' => $device,
            'engaged_ms' => Arr::get($payload, 'engaged_ms'),
            'scroll_depth' => Arr::get($payload, 'scroll_depth'),
            'target_host' => $targetHost,
            'is_returning' => $returning,
            'utm_source' => $this->cleanCampaignValue(Arr::get($payload, 'utm_source')),
            'utm_medium' => $this->cleanCampaignValue(Arr::get($payload, 'utm_medium')),
            'utm_campaign' => $this->cleanCampaignValue(Arr::get($payload, 'utm_campaign')),
            'consent_version' => (int) $payload['consent_version'],
            'metadata' => array_filter([
                'content_key' => $this->cleanCampaignValue(Arr::get($payload, 'content_key')),
            ]),
        ]);
    }

    public function allows(Request $request, array $payload): bool
    {
        return ($payload['analytics_consent'] ?? false) === true
            && ! $this->isBot((string) $request->userAgent())
            && ! $this->isBackofficeTraffic($request, $payload);
    }

    public function sourceChannel(array $payload, ?string $context, ?string $referrerHost): string
    {
        return match ($context) {
            'homepage' => 'homepage',
            'category_page' => 'category_page',
            'author_page' => 'author_page',
            'internal_search' => 'internal_search',
            'related_article' => 'related_article',
            default => $this->externalSource($payload, $referrerHost),
        };
    }

    private function externalSource(array $payload, ?string $host): string
    {
        $medium = Str::lower((string) Arr::get($payload, 'utm_medium', ''));
        $source = Str::lower((string) Arr::get($payload, 'utm_source', ''));

        if (in_array($medium, ['cpc', 'ppc', 'paid', 'paid_social', 'display'], true)) {
            return 'paid_campaign';
        }

        if ($medium === 'email' || str_contains($source, 'newsletter')) {
            return 'newsletter';
        }

        if ($host === null) {
            return 'direct';
        }

        $normalized = Str::lower($host);

        return match (true) {
            str_contains($normalized, 'google.'), str_contains($normalized, 'bing.'), str_contains($normalized, 'duckduckgo.') => 'organic_search',
            str_contains($normalized, 'facebook.com'), str_contains($normalized, 'fb.com'), str_contains($normalized, 'fb.me') => 'facebook',
            str_contains($normalized, 'instagram.com') => 'instagram',
            $normalized === 'x.com', str_contains($normalized, 'twitter.com') => 'x',
            str_contains($normalized, 'linkedin.com') => 'linkedin',
            $this->isOwnHost($normalized) => 'unknown',
            default => 'external_referral',
        };
    }

    private function isBot(string $userAgent): bool
    {
        return $userAgent === '' || preg_match('/bot|crawler|spider|slurp|headless|preview|facebookexternalhit|whatsapp|telegram/i', $userAgent) === 1;
    }

    private function isBackofficeTraffic(Request $request, array $payload): bool
    {
        if ($request->user() instanceof User && $request->user()->roles()->exists()) {
            return true;
        }

        $referrer = Arr::get($payload, 'referrer');
        $path = is_string($referrer) ? parse_url($referrer, PHP_URL_PATH) : null;

        return is_string($path) && str_starts_with('/'.ltrim($path, '/'), '/backoffice');
    }

    private function device(string $userAgent): string
    {
        if (preg_match('/ipad|tablet/i', $userAgent)) {
            return 'tablet';
        }

        return preg_match('/mobile|iphone|android/i', $userAgent) ? 'mobile' : 'desktop';
    }

    private function host(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? Str::lower($host) : null;
    }

    private function isOwnHost(string $host): bool
    {
        $frontendHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);

        return is_string($frontendHost) && Str::lower($frontendHost) === $host;
    }

    private function cleanCampaignValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(strip_tags(trim($value)), 190, '');
    }
}
