<?php

namespace App\Services\Wordpress;

use League\Uri\UriString;
use Symfony\Component\HtmlSanitizer\TextSanitizer\UrlSanitizer;

final class WordpressIframeUrlPolicy
{
    private const string YOUTUBE_PLAYLIST = 'PLjOJ1FMZ1tOX1eRhERFENQQ2nGSqdkez1';

    private const string KRSTARICA_EMBED_PATH = '/zivot/ziveti-zivot/da-li-ste-culi-za-eustres-da-postoji-i-moze-vam-biti-od-koristi/embed/';

    public function normalize(?string $source): ?string
    {
        $source = html_entity_decode(trim((string) $source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $source = UrlSanitizer::sanitize($source, ['http', 'https'], forceHttps: true);

        if ($source === null) {
            return null;
        }

        $parts = UriString::parse($source);

        if (($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || ($parts['user'] ?? null) !== null
            || ($parts['pass'] ?? null) !== null
            || ($parts['port'] ?? null) !== null) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $query = $this->queryParameters($parts['query'] ?? null);

        if ($query === null || ! $this->isAllowedProviderUrl($host, $path, $query)) {
            return null;
        }

        $parts['scheme'] = 'https';
        $parts['host'] = $host;
        $parts['fragment'] = null;

        return UriString::build($parts);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function isAllowedProviderUrl(string $host, string $path, array $query): bool
    {
        return match ($host) {
            'assets.pinterest.com' => $this->isPinterestUrl($path, $query),
            'www.youtube.com' => $this->isYoutubeUrl($path, $query),
            'www.facebook.com' => $this->isFacebookUrl($path, $query),
            'w.soundcloud.com' => $this->isSoundCloudUrl($path, $query),
            'embed.traxsource.com' => preg_match('#^/player/title/\d+$#', $path) === 1 && $query === [],
            'www.krstarica.com' => $path === self::KRSTARICA_EMBED_PATH && $query === [],
            default => false,
        };
    }

    /**
     * @param  array<string, string>  $query
     */
    private function isPinterestUrl(string $path, array $query): bool
    {
        if ($path !== '/ext/embed.html' || array_keys($query) !== ['id']) {
            return false;
        }

        return preg_match('/^(?:\d{11}|\d{14,19}|[A-Za-z0-9_-]{55}|[A-Za-z0-9_-]{87})$/', $query['id']) === 1;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function isYoutubeUrl(string $path, array $query): bool
    {
        if (preg_match('#^/embed/[A-Za-z0-9_-]{11}$#', $path) !== 1) {
            return false;
        }

        if (isset($query['list'])) {
            return $query === ['list' => self::YOUTUBE_PLAYLIST];
        }

        if (($query['feature'] ?? null) !== 'oembed'
            || array_diff_key($query, array_flip(['feature', 'start'])) !== []) {
            return false;
        }

        return ! isset($query['start']) || preg_match('/^[1-9]\d*$/', $query['start']) === 1;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function isFacebookUrl(string $path, array $query): bool
    {
        if (! in_array($path, ['/plugins/post.php', '/plugins/video.php'], true)
            || ! isset($query['href'])
            || ! $this->isFacebookNestedUrl($query['href'])) {
            return false;
        }

        $allowedKeys = $path === '/plugins/video.php'
            ? ['href', 'show_text', 'width', 'height', 't']
            : ['href', 'show_text', 'width', 'preview'];

        if (array_diff_key($query, array_flip($allowedKeys)) !== []) {
            return false;
        }

        foreach (['show_text'] as $booleanKey) {
            if (isset($query[$booleanKey]) && ! in_array($query[$booleanKey], ['true', 'false', '1', '0'], true)) {
                return false;
            }
        }

        foreach (['width', 'height', 't'] as $integerKey) {
            if (isset($query[$integerKey]) && preg_match('/^\d+$/', $query[$integerKey]) !== 1) {
                return false;
            }
        }

        return ! isset($query['preview']) || $query['preview'] === 'comet_preview';
    }

    private function isFacebookNestedUrl(string $url): bool
    {
        $url = UrlSanitizer::sanitize($url, ['https']);

        if ($url === null) {
            return false;
        }

        $parts = UriString::parse($url);

        if (($parts['scheme'] ?? null) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'www.facebook.com'
            || ($parts['user'] ?? null) !== null
            || ($parts['pass'] ?? null) !== null
            || ($parts['port'] ?? null) !== null
            || ($parts['fragment'] ?? null) !== null) {
            return false;
        }

        $path = $parts['path'] ?? '';
        $query = $this->queryParameters($parts['query'] ?? null);

        if ($query === null) {
            return false;
        }

        if (preg_match('#^/[A-Za-z0-9._-]+/(?:posts|videos)/\d+/?$#', $path) === 1) {
            return $query === [];
        }

        if ($path === '/photo.php') {
            return isset($query['fbid'])
                && preg_match('/^\d+$/', $query['fbid']) === 1
                && array_diff_key($query, array_flip(['fbid', 'set', 'type'])) === []
                && (! isset($query['set']) || preg_match('/^[A-Za-z0-9._-]+$/', $query['set']) === 1)
                && (! isset($query['type']) || preg_match('/^\d+$/', $query['type']) === 1);
        }

        return $path === '/permalink.php'
            && array_keys($query) === ['story_fbid', 'id']
            && preg_match('/^\d+$/', $query['story_fbid']) === 1
            && preg_match('/^\d+$/', $query['id']) === 1;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function isSoundCloudUrl(string $path, array $query): bool
    {
        if ($path !== '/player/' || ! isset($query['url'])) {
            return false;
        }

        $nested = UrlSanitizer::sanitize($query['url'], ['https']);

        if ($nested === null) {
            return false;
        }

        $nestedParts = UriString::parse($nested);

        if (($nestedParts['scheme'] ?? null) !== 'https'
            || strtolower((string) ($nestedParts['host'] ?? '')) !== 'api.soundcloud.com'
            || preg_match('#^/playlists/\d+$#', $nestedParts['path'] ?? '') !== 1
            || ($nestedParts['query'] ?? null) !== null
            || ($nestedParts['fragment'] ?? null) !== null
            || ($nestedParts['user'] ?? null) !== null
            || ($nestedParts['pass'] ?? null) !== null
            || ($nestedParts['port'] ?? null) !== null) {
            return false;
        }

        $booleanKeys = [
            'auto_play',
            'buying',
            'download',
            'hide_related',
            'sharing',
            'show_artwork',
            'show_comments',
            'show_playcount',
            'show_reposts',
            'show_teaser',
            'show_user',
            'visual',
        ];

        if (array_diff_key($query, array_flip(['url', 'color', ...$booleanKeys])) !== []) {
            return false;
        }

        if (isset($query['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $query['color']) !== 1) {
            return false;
        }

        foreach ($booleanKeys as $key) {
            if (isset($query[$key]) && ! in_array($query[$key], ['true', 'false', '1', '0'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>|null
     */
    private function queryParameters(?string $query): ?array
    {
        if ($query === null || $query === '') {
            return [];
        }

        $parameters = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                return null;
            }

            [$encodedKey, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = rawurldecode(str_replace('+', ' ', $encodedKey));
            $value = rawurldecode(str_replace('+', ' ', $encodedValue));

            if ($key === '' || isset($parameters[$key]) || str_contains($key, '[') || str_contains($key, ']')) {
                return null;
            }

            $parameters[$key] = $value;
        }

        return $parameters;
    }
}
