<?php

namespace App\Services\Wordpress;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

final class WordpressSrcsetAttributeSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['srcset', 'imagesrcset'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $candidates = preg_split('/\s*,\s*/', trim($value));

        if (! is_array($candidates) || $candidates === []) {
            return null;
        }

        $sanitized = [];

        foreach ($candidates as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate), 2, PREG_SPLIT_NO_EMPTY);

            if (! is_array($parts) || $parts === [] || ! $this->isSafeMediaUrl($parts[0])) {
                return null;
            }

            if (isset($parts[1]) && ! preg_match('/^(?:\d+w|(?:\d+(?:\.\d+)?|\.\d+)x)$/', $parts[1])) {
                return null;
            }

            $sanitized[] = implode(' ', $parts);
        }

        return implode(', ', $sanitized);
    }

    private function isSafeMediaUrl(string $url): bool
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($url === '' || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7F]/u', $url)) {
            return false;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $decoded = rawurldecode($url);

            if ($decoded === $url) {
                break;
            }

            $url = $decoded;
        }

        if (preg_match('/[\x00-\x20\x7F]/u', $url)) {
            return false;
        }

        if (! preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches)) {
            return true;
        }

        return in_array(strtolower($matches[1]), ['http', 'https'], true);
    }
}
