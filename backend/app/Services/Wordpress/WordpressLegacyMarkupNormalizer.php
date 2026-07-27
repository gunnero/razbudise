<?php

namespace App\Services\Wordpress;

use DOMDocument;
use DOMElement;

final class WordpressLegacyMarkupNormalizer
{
    private const string ROOT_ID = 'razbudise-legacy-markup-normalizer-root';

    /**
     * @return array{html: string, warnings: array<int, string>, normalization_counts: array<string, int>}
     */
    public function normalize(string $html, bool $removePersistedBlobImages = false): array
    {
        $divi = $this->normalizeDiviShortcodes($html);
        $html = $divi['html'];
        $warnings = $divi['warnings'];
        $counts = $divi['normalization_counts'] + [
            'trusted_wordpress_media_wrapper_normalized' => 0,
            'trusted_malformed_link_normalized' => 0,
            'trusted_blob_image_removed' => 0,
            'trusted_empty_icon_removed' => 0,
            'trusted_nested_link_normalized' => 0,
        ];

        $html = preg_replace(
            '#<p>\s*(<div\b[^>]*>\s*<video\b[^>]*>.*?</video>\s*</div>)\s*</p>#isu',
            '$1',
            $html,
            -1,
            $mediaWrapperCount,
        ) ?? $html;

        if ($mediaWrapperCount > 0) {
            $counts['trusted_wordpress_media_wrapper_normalized'] = $mediaWrapperCount;
            $warnings[] = 'trusted_wordpress_media_wrapper_normalized';
        }

        if (preg_match('/<(?:a|img)\b/i', $html) !== 1) {
            return [
                'html' => $html,
                'warnings' => $warnings,
                'normalization_counts' => array_filter($counts),
            ];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div id="'.self::ROOT_ID.'">'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );

            if (! $loaded) {
                return [
                    'html' => $html,
                    'warnings' => $warnings,
                    'normalization_counts' => array_filter($counts),
                ];
            }

            $root = $this->rootElement($document);

            if (! $root || $this->hasEscapedContent($document, $root)) {
                return [
                    'html' => $html,
                    'warnings' => $warnings,
                    'normalization_counts' => array_filter($counts),
                ];
            }

            foreach ($this->elements($root, 'a') as $link) {
                if (! $link->hasAttribute('href')) {
                    continue;
                }

                $href = html_entity_decode(trim($link->getAttribute('href')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $recovered = $this->recoverEmbeddedTarget($href);

                if ($recovered !== null) {
                    $link->setAttribute('href', $recovered);
                    $counts['trusted_malformed_link_normalized']++;

                    continue;
                }

                if ($this->hasProvablyInvalidHttpAuthority($href)) {
                    $link->removeAttribute('href');
                    $counts['trusted_malformed_link_normalized']++;
                }
            }

            foreach ($this->elements($root, 'img') as $image) {
                if ($removePersistedBlobImages && $this->isPersistedBlobImage($image)) {
                    $image->parentNode?->removeChild($image);
                    $counts['trusted_blob_image_removed']++;

                    continue;
                }

                if (! $this->isKnownEmptyLegacyIcon($image)) {
                    continue;
                }

                $image->parentNode?->removeChild($image);
                $counts['trusted_empty_icon_removed']++;
            }

            foreach ($this->elements($root, 'a') as $outerLink) {
                if (! $this->hasOnlyHrefAttribute($outerLink)) {
                    continue;
                }

                $outerHref = trim($outerLink->getAttribute('href'));

                foreach ($this->elements($outerLink, 'a') as $innerLink) {
                    if (! $this->hasOnlyHrefAttribute($innerLink)
                        || trim($innerLink->getAttribute('href')) !== $outerHref) {
                        continue;
                    }

                    while ($innerLink->firstChild !== null) {
                        $innerLink->parentNode?->insertBefore($innerLink->firstChild, $innerLink);
                    }

                    $innerLink->parentNode?->removeChild($innerLink);
                    $counts['trusted_nested_link_normalized']++;
                }
            }

            $counts = array_filter($counts);

            if ($counts === []) {
                return ['html' => $html, 'warnings' => $warnings, 'normalization_counts' => []];
            }

            return [
                'html' => $this->innerHtml($document, $root) ?? $html,
                'warnings' => array_values(array_unique([...$warnings, ...array_keys($counts)])),
                'normalization_counts' => $counts,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    /**
     * Convert the two legacy Divi constructs present in the source archive:
     * layout/text wrappers become inert structure, while image modules become
     * ordinary images whose bytes still pass through the normal media importer.
     *
     * @return array{html: string, warnings: array<int, string>, normalization_counts: array<string, int>}
     */
    private function normalizeDiviShortcodes(string $html): array
    {
        if (stripos($html, '[et_pb_') === false && stripos($html, '[/et_pb_') === false) {
            return ['html' => $html, 'warnings' => [], 'normalization_counts' => []];
        }

        $count = 0;
        $html = preg_replace_callback(
            '/\[et_pb_image\b([^\]]*)\]\s*\[\/et_pb_image\]/iu',
            function (array $matches) use (&$count): string {
                $attributes = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $source = $this->diviAttribute($attributes, 'src');

                if ($source === null
                    || ! $this->isSafeAbsoluteHttpTarget($source)
                    || strtolower((string) parse_url($source, PHP_URL_SCHEME)) !== 'https'
                    || ! str_contains((string) parse_url($source, PHP_URL_PATH), '/wp-content/uploads/')) {
                    return $matches[0];
                }

                $alt = $this->diviAttribute($attributes, 'title_text') ?? '';
                $count++;

                return sprintf(
                    '<figure><img src="%s" alt="%s"></figure>',
                    htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
                    htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
                );
            },
            $html,
        ) ?? $html;

        $wrapperCount = 0;
        $html = preg_replace(
            '/\[\/?et_pb_(?:section|row|column|row_inner|column_inner|text)\b[^\]]*\]/iu',
            '',
            $html,
            -1,
            $wrapperCount,
        ) ?? $html;
        $count += $wrapperCount;

        $commentCount = 0;
        $html = preg_replace('/<!--\s*\/?divi:[^>]*-->/iu', '', $html, -1, $commentCount) ?? $html;
        $count += $commentCount;

        $warnings = $count > 0 ? ['trusted_divi_shortcode_normalized'] : [];

        if (preg_match('/\[\/?et_pb_[^\]]*\]/iu', $html) === 1) {
            $warnings[] = 'unsupported_shortcode';
        }

        return [
            'html' => $html,
            'warnings' => $warnings,
            'normalization_counts' => $count > 0 ? ['trusted_divi_shortcode_normalized' => $count] : [],
        ];
    }

    private function diviAttribute(string $attributes, string $name): ?string
    {
        $name = preg_quote($name, '/');

        if (preg_match('/(?:^|\s)'.$name.'\s*=\s*["“”]([^"”″]+)["”″]/u', $attributes, $match) !== 1) {
            return null;
        }

        $value = trim($match[1]);

        return $value !== '' ? $value : null;
    }

    private function recoverEmbeddedTarget(string $href): ?string
    {
        if (preg_match(
            '#^http(?:://|%3A%2F%2F)(?:%3C|<)blockquote(?:%20|\s)#i',
            $href,
        ) === 1) {
            $href = rawurldecode($href);
        }

        if (preg_match(
            '#^http://<blockquote\b[^>]*\bdata-instgrm-permalink\s*=\s*["\']https://www\.instagram\.com/(p|reel|tv)/([A-Za-z0-9_-]+)/#is',
            $href,
            $instagram,
        ) === 1) {
            return 'https://www.instagram.com/'.strtolower($instagram[1]).'/'.$instagram[2].'/';
        }

        if (preg_match(
            '#^http://<?a\s+href\s*=\s*["\'](https?://[^"\']+)["\'][^>]*>.*</a>\s*$#is',
            $href,
            $nested,
        ) === 1
            && $this->isSafeAbsoluteHttpTarget($nested[1])) {
            return $nested[1];
        }

        if (preg_match(
            '#^http://chrome-extension://[A-Za-z0-9_-]+/(https?://[^\s]+)$#i',
            $href,
            $extension,
        ) === 1
            && $this->isSafeAbsoluteHttpTarget($extension[1])) {
            return $extension[1];
        }

        return null;
    }

    private function hasProvablyInvalidHttpAuthority(string $href): bool
    {
        if (preg_match('#^https?://#i', $href) !== 1) {
            return false;
        }

        if (preg_match('~^https?://([^/?#]*)~iu', $href, $authorityMatch) !== 1) {
            return true;
        }

        $authority = $authorityMatch[1];

        if (str_contains($authority, '@')) {
            $authority = (string) substr($authority, (int) strrpos($authority, '@') + 1);
        }

        if (str_starts_with($authority, '[')) {
            if (preg_match('/^\[([^\]]+)](?::([0-9]+))?$/', $authority, $ipMatch) !== 1) {
                return true;
            }

            $port = $ipMatch[2] ?? null;

            return filter_var($ipMatch[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
                || (is_string($port) && (int) $port > 65535);
        }

        if (substr_count($authority, ':') > 1
            || (str_contains($authority, ':')
                && preg_match('/^(.+):([0-9]+)$/u', $authority, $hostPort) !== 1)) {
            return true;
        }

        if (isset($hostPort)) {
            if ((int) $hostPort[2] > 65535) {
                return true;
            }

            $authority = $hostPort[1];
        }

        $host = rtrim($authority, '.');

        if ($host === '' || preg_match('/[%\s\x00-\x1F\x7F<>"\']/', $host) === 1) {
            return true;
        }

        $ipHost = trim($host, '[]');

        if (filter_var($ipHost, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        // A lone Unicode label is not a complete public hostname. Keep
        // dotted IDNs and ASCII development hosts, but unlink this exact
        // legacy corruption shape while preserving its visible text.
        if (! str_contains($host, '.') && preg_match('/[^\x00-\x7F]/u', $host) === 1) {
            return true;
        }

        $idnaInfo = [];
        $asciiHost = idn_to_ascii(
            $host,
            IDNA_DEFAULT,
            INTL_IDNA_VARIANT_UTS46,
            $idnaInfo,
        );

        return ! is_string($asciiHost)
            || $asciiHost === ''
            || (int) ($idnaInfo['errors'] ?? 0) !== 0
            || filter_var($asciiHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false;
    }

    private function isSafeAbsoluteHttpTarget(string $target): bool
    {
        $target = trim($target);
        $parts = parse_url($target);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && preg_match('/^[A-Za-z0-9.-]+$/D', $parts['host']) === 1;
    }

    private function isKnownEmptyLegacyIcon(DOMElement $image): bool
    {
        if (! $image->hasAttribute('src')
            || trim($image->getAttribute('src')) !== ''
            || ($image->hasAttribute('srcset') && trim($image->getAttribute('srcset')) !== '')
            || preg_match('/^IMG[_-]?\d+$/i', trim($image->getAttribute('alt'))) !== 1) {
            return false;
        }

        $width = filter_var($image->getAttribute('width'), FILTER_VALIDATE_INT);
        $height = filter_var($image->getAttribute('height'), FILTER_VALIDATE_INT);

        return is_int($width)
            && is_int($height)
            && $width > 0
            && $height > 0
            && $width <= 32
            && $height <= 32;
    }

    private function isPersistedBlobImage(DOMElement $image): bool
    {
        return $image->hasAttribute('src')
            && str_starts_with(strtolower(trim($image->getAttribute('src'))), 'blob:');
    }

    private function hasOnlyHrefAttribute(DOMElement $link): bool
    {
        return $link->hasAttribute('href')
            && $link->attributes->length === 1
            && trim($link->getAttribute('href')) !== '';
    }

    private function rootElement(DOMDocument $document): ?DOMElement
    {
        foreach ($document->getElementsByTagName('div') as $element) {
            if ($element instanceof DOMElement && $element->getAttribute('id') === self::ROOT_ID) {
                return $element;
            }
        }

        return null;
    }

    private function hasEscapedContent(DOMDocument $document, DOMElement $root): bool
    {
        foreach ($document->childNodes as $node) {
            if ($node->isSameNode($root)) {
                continue;
            }

            if ($node->nodeType === XML_PI_NODE && strtolower($node->nodeName) === 'xml') {
                continue;
            }

            if ($node->nodeType === XML_TEXT_NODE && trim($node->nodeValue ?? '') === '') {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @return array<int, DOMElement> */
    private function elements(DOMElement $root, string $tagName): array
    {
        $elements = [];

        foreach ($root->getElementsByTagName($tagName) as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    private function innerHtml(DOMDocument $document, DOMElement $root): ?string
    {
        $html = '';

        foreach ($root->childNodes as $child) {
            $serialized = $document->saveHTML($child);

            if ($serialized === false) {
                return null;
            }

            $html .= $serialized;
        }

        return $html;
    }
}
