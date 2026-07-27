<?php

namespace App\Support;

final class UrlPath
{
    public static function normalize(?string $url): string
    {
        if (! is_string($url) || trim($url) === '') {
            return '';
        }

        $url = trim($url);
        $parseableUrl = preg_replace_callback(
            '/[^\x20-\x7E]+/u',
            static fn (array $match): string => rawurlencode($match[0]),
            $url,
        ) ?? $url;
        $parts = parse_url($parseableUrl);
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;

        if (! is_string($path) || $path === '') {
            $path = is_array($parts) && (isset($parts['scheme']) || isset($parts['host']))
                ? '/'
                : (preg_split('/[?#]/', $parseableUrl, 2)[0] ?? $parseableUrl);
        }

        $path = '/'.ltrim(rawurldecode($path), '/');

        return rtrim($path, '/') ?: '/';
    }
}
