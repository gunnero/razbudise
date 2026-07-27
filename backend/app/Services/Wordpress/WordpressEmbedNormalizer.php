<?php

namespace App\Services\Wordpress;

use DOMDocument;
use DOMElement;

final class WordpressEmbedNormalizer
{
    private const string IFRAME_SANDBOX = 'allow-scripts allow-same-origin allow-presentation';

    private const string WORDPRESS_LIGHTBOX_ICON_PATH = 'M2 0a2 2 0 0 0-2 2v2h1.5V2a.5.5 0 0 1 .5-.5h2V0H2Zm2 10.5H2a.5.5 0 0 1-.5-.5V8H0v2a2 2 0 0 0 2 2h2v-1.5ZM8 12v-1.5h2a.5.5 0 0 0 .5-.5V8H12v2a2 2 0 0 1-2 2H8Zm2-12a2 2 0 0 1 2 2v2h-1.5V2a.5.5 0 0 0-.5-.5H8V0h2Z';

    public function __construct(private readonly WordpressIframeUrlPolicy $iframeUrlPolicy) {}

    /**
     * @return array{html: string, warnings: array<int, string>, normalization_counts: array<string, int>}
     */
    public function normalize(string $html): array
    {
        if (preg_match('/<(?:iframe|script)\b|\bdata-(?:instgrm-permalink|media-max-width|dnt|width|video-id|embed-from|wp-interactive)\s*=/i', $html) !== 1) {
            return ['html' => $html, 'warnings' => [], 'normalization_counts' => []];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div id="razbudise-embed-normalizer-root">'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );

            if (! $loaded) {
                return ['html' => $html, 'warnings' => [], 'normalization_counts' => []];
            }

            $root = $this->rootElement($document);

            if (! $root) {
                return ['html' => $html, 'warnings' => [], 'normalization_counts' => []];
            }

            if ($this->hasEscapedContent($document, $root)) {
                return [
                    'html' => $html,
                    'warnings' => ['unsafe_html_removed'],
                    'normalization_counts' => [],
                ];
            }

            $warnings = [];
            $changed = false;
            $fallbacks = $this->trustedFallbackCounts($root);
            $normalizationCounts = [
                'trusted_embed_script_removed' => 0,
                'trusted_iframe_hardened' => 0,
                'trusted_instagram_fallback_normalized' => 0,
                'trusted_twitter_fallback_normalized' => 0,
                'trusted_tiktok_fallback_normalized' => 0,
                'trusted_wordpress_interactive_normalized' => 0,
                'trusted_wordpress_oembed_normalized' => 0,
            ];

            foreach ($this->elements($root, 'script') as $script) {
                $provider = $this->trustedScriptProvider($script);

                if ($provider === null || ($fallbacks[$provider] ?? 0) < 1) {
                    continue;
                }

                $fallbacks[$provider]--;
                $script->parentNode?->removeChild($script);
                $warnings[] = 'trusted_embed_script_removed';
                $normalizationCounts['trusted_embed_script_removed']++;
                $changed = true;
            }

            foreach ($this->normalizeTrustedFallbacks($document, $root) as $provider => $count) {
                if ($count < 1) {
                    continue;
                }

                $key = 'trusted_'.$provider.'_fallback_normalized';
                $warnings[] = $key;
                $normalizationCounts[$key] += $count;
                $changed = true;
            }

            $wordpressInteractiveCount = $this->normalizeWordpressInteractiveMarkup($root);

            if ($wordpressInteractiveCount > 0) {
                $warnings[] = 'trusted_wordpress_interactive_normalized';
                $normalizationCounts['trusted_wordpress_interactive_normalized'] += $wordpressInteractiveCount;
                $changed = true;
            }

            $wordpressOembedCount = $this->normalizeWordpressOembedFallbacks($root);

            if ($wordpressOembedCount > 0) {
                $warnings[] = 'trusted_wordpress_oembed_normalized';
                $normalizationCounts['trusted_wordpress_oembed_normalized'] += $wordpressOembedCount;
                $changed = true;
            }

            foreach ($this->elements($root, 'iframe') as $iframe) {
                $source = $this->iframeUrlPolicy->normalize($iframe->getAttribute('src'));

                if ($source === null) {
                    if ($this->hasExecutableIframeAttribute($iframe)) {
                        $warnings[] = 'unsafe_html_removed';
                    }

                    $iframe->parentNode?->removeChild($iframe);
                    $warnings[] = 'untrusted_iframe_removed';
                    $changed = true;

                    continue;
                }

                if ($this->hasMeaningfulIframeContent($iframe)) {
                    $iframe->parentNode?->removeChild($iframe);
                    $warnings[] = 'unsafe_html_removed';
                    $warnings[] = 'untrusted_iframe_removed';
                    $changed = true;

                    continue;
                }

                $hadIgnorableChildren = $iframe->hasChildNodes();

                while ($iframe->firstChild !== null) {
                    $iframe->removeChild($iframe->firstChild);
                }

                $desiredAttributes = $this->desiredIframeAttributes($iframe, $source);
                $currentAttributes = $this->attributes($iframe);

                if ($currentAttributes === $desiredAttributes && ! $hadIgnorableChildren) {
                    continue;
                }

                if ($this->hasExecutableIframeAttribute($iframe)) {
                    $warnings[] = 'unsafe_html_removed';
                }

                foreach (array_keys($currentAttributes) as $attribute) {
                    $iframe->removeAttribute($attribute);
                }

                foreach ($desiredAttributes as $attribute => $value) {
                    $iframe->setAttribute($attribute, $value);
                }

                $warnings[] = 'trusted_iframe_hardened';
                $normalizationCounts['trusted_iframe_hardened']++;
                $changed = true;
            }

            if (! $changed) {
                return ['html' => $html, 'warnings' => [], 'normalization_counts' => []];
            }

            return [
                'html' => $this->innerHtml($document, $root) ?? $html,
                'warnings' => array_values(array_unique($warnings)),
                'normalization_counts' => array_filter($normalizationCounts),
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
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

    private function hasMeaningfulIframeContent(DOMElement $iframe): bool
    {
        foreach ($iframe->childNodes as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                continue;
            }

            if ($child->nodeType === XML_TEXT_NODE && trim($child->nodeValue ?? '') === '') {
                continue;
            }

            return true;
        }

        return false;
    }

    private function rootElement(DOMDocument $document): ?DOMElement
    {
        foreach ($document->getElementsByTagName('div') as $element) {
            if ($element instanceof DOMElement && $element->getAttribute('id') === 'razbudise-embed-normalizer-root') {
                return $element;
            }
        }

        return null;
    }

    /**
     * @return array<int, DOMElement>
     */
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

    /**
     * @return array{instagram: int, twitter: int, tiktok: int}
     */
    private function trustedFallbackCounts(DOMElement $root): array
    {
        $counts = ['instagram' => 0, 'twitter' => 0, 'tiktok' => 0];

        foreach ($this->elements($root, 'blockquote') as $blockquote) {
            $provider = $this->trustedFallbackProvider($blockquote);

            if ($provider !== null) {
                $counts[$provider]++;
            }
        }

        return $counts;
    }

    /** @return array{instagram: int, twitter: int, tiktok: int} */
    private function normalizeTrustedFallbacks(DOMDocument $document, DOMElement $root): array
    {
        $counts = ['instagram' => 0, 'twitter' => 0, 'tiktok' => 0];

        foreach ($this->elements($root, 'blockquote') as $blockquote) {
            $provider = $this->trustedFallbackProvider($blockquote);

            if ($provider === 'instagram' && $this->normalizeInstagramFallback($document, $blockquote)) {
                $counts['instagram']++;
            }

            if ($provider === 'twitter' && $this->normalizeTwitterFallbackAttributes($blockquote)) {
                $counts['twitter']++;
            }

            if ($provider === 'tiktok' && $this->normalizeTiktokFallbackAttributes($blockquote)) {
                $counts['tiktok']++;
            }
        }

        return $counts;
    }

    private function normalizeInstagramFallback(DOMDocument $document, DOMElement $blockquote): bool
    {
        $attributes = $this->attributes($blockquote);
        $allowedRootAttributes = [
            'class',
            'data-instgrm-captioned',
            'data-instgrm-permalink',
            'data-instgrm-version',
            'style',
        ];

        if ($this->classTokens($blockquote) !== ['instagram-media']
            || array_diff(array_keys($attributes), $allowedRootAttributes) !== []
            || ! isset($attributes['data-instgrm-permalink'], $attributes['data-instgrm-version'], $attributes['style'])
            || $attributes['data-instgrm-version'] !== '14'
            || (isset($attributes['data-instgrm-captioned']) && $attributes['data-instgrm-captioned'] !== '')) {
            return false;
        }

        $permalink = $this->canonicalInstagramPermalink($attributes['data-instgrm-permalink']);

        if ($permalink === null || ! $this->isStrictInstagramGeneratedFallback($blockquote, $permalink)) {
            return false;
        }

        $fallback = $document->createElement('blockquote');
        $link = $document->createElement('a');
        $link->setAttribute('href', $permalink);
        $link->appendChild($document->createTextNode('Погледнете ја објавата на Instagram'));
        $fallback->appendChild($link);
        $blockquote->parentNode?->replaceChild($fallback, $blockquote);

        return true;
    }

    private function isStrictInstagramGeneratedFallback(DOMElement $blockquote, string $permalink): bool
    {
        $allowedAttributes = [
            'div' => ['style'],
            'a' => ['href', 'rel', 'style', 'target'],
            'p' => ['style'],
            'svg' => ['height', 'version', 'viewbox', 'width', 'xmlns', 'xmlns:xlink'],
            'g' => ['fill', 'fill-rule', 'stroke', 'stroke-width', 'transform'],
            'path' => ['d'],
        ];
        $counts = array_fill_keys(array_keys($allowedAttributes), 0);

        if (! $this->hasOnlyElementAndTextDescendants($blockquote)) {
            return false;
        }

        foreach ($blockquote->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                return false;
            }

            $tagName = strtolower($element->tagName);

            if (! isset($allowedAttributes[$tagName])
                || array_diff(array_keys($this->attributes($element)), $allowedAttributes[$tagName]) !== []) {
                return false;
            }

            $counts[$tagName]++;

            if ($tagName !== 'a') {
                continue;
            }

            if ($this->canonicalInstagramPermalink($element->getAttribute('href')) !== $permalink
                || $element->getAttribute('target') !== '_blank'
                || ! $element->hasAttribute('style')) {
                return false;
            }

            if ($element->hasAttribute('rel')) {
                $rel = preg_split('/\s+/', strtolower(trim($element->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY);
                $rel = is_array($rel) ? array_values(array_unique($rel)) : [];

                if ($rel === [] || array_diff($rel, ['noopener', 'noreferrer']) !== []) {
                    return false;
                }
            }
        }

        return $counts['a'] === 2
            && $counts['p'] === 1
            && $counts['svg'] === 1
            && $counts['g'] === 3
            && $counts['path'] === 1;
    }

    private function canonicalInstagramPermalink(string $url): ?string
    {
        $parts = parse_url(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! in_array(strtolower((string) ($parts['host'] ?? '')), ['instagram.com', 'www.instagram.com'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])
            || preg_match('#^/(p|reel|tv)/([A-Za-z0-9_-]+)/?$#', (string) ($parts['path'] ?? ''), $matches) !== 1) {
            return null;
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);

            if (array_keys($query) !== ['utm_source', 'utm_campaign']
                || ($query['utm_source'] ?? null) !== 'ig_embed'
                || ($query['utm_campaign'] ?? null) !== 'loading') {
                return null;
            }
        }

        return sprintf('https://www.instagram.com/%s/%s/', $matches[1], $matches[2]);
    }

    private function normalizeTwitterFallbackAttributes(DOMElement $blockquote): bool
    {
        $changed = false;

        foreach (['data-media-max-width', 'data-width'] as $attribute) {
            if (! $blockquote->hasAttribute($attribute)) {
                continue;
            }

            $value = $blockquote->getAttribute($attribute);

            if (preg_match('/^\d{3,4}$/', $value) === 1 && (int) $value >= 250 && (int) $value <= 1000) {
                $blockquote->removeAttribute($attribute);
                $changed = true;
            }
        }

        if ($blockquote->hasAttribute('data-dnt') && $blockquote->getAttribute('data-dnt') === 'true') {
            $blockquote->removeAttribute('data-dnt');
            $changed = true;
        }

        return $changed;
    }

    private function normalizeTiktokFallbackAttributes(DOMElement $blockquote): bool
    {
        $videoId = $this->tiktokVideoId($blockquote->getAttribute('cite'));

        if ($videoId === null
            || ! $blockquote->hasAttribute('data-video-id')
            || ! hash_equals($videoId, $blockquote->getAttribute('data-video-id'))) {
            return false;
        }

        if ($blockquote->hasAttribute('data-embed-from')
            && $blockquote->getAttribute('data-embed-from') !== 'oembed') {
            return false;
        }

        $blockquote->removeAttribute('data-video-id');

        if ($blockquote->hasAttribute('data-embed-from')) {
            $blockquote->removeAttribute('data-embed-from');
        }

        return true;
    }

    private function tiktokVideoId(string $url): ?string
    {
        $parts = parse_url(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'www.tiktok.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('~^/@[^/?#]+/video/(\d+)/?$~', (string) ($parts['path'] ?? ''), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function normalizeWordpressInteractiveMarkup(DOMElement $root): int
    {
        $count = 0;

        foreach ($this->elements($root, 'figure') as $figure) {
            if ($figure->getAttribute('data-wp-interactive') === 'core/image'
                && $this->normalizeWordpressImageLightbox($figure)) {
                $count++;
            }
        }

        foreach ($this->elements($root, 'figure') as $figure) {
            if ($figure->getAttribute('data-wp-interactive') === 'core/gallery'
                && $this->normalizeWordpressInteractiveGallery($figure)) {
                $count++;
            }
        }

        return $count;
    }

    private function normalizeWordpressImageLightbox(DOMElement $figure): bool
    {
        if (! $this->hasExactAttributeNames($figure, ['class', 'data-wp-context', 'data-wp-interactive', 'data-wp-key'])
            || ! $this->hasExpectedWordpressImageClasses($figure)) {
            return false;
        }

        $key = $figure->getAttribute('data-wp-key');
        $context = json_decode($figure->getAttribute('data-wp-context'), true);

        if (preg_match('/^[a-f0-9]{10,32}$/', $key) !== 1
            || ! is_array($context)
            || $context !== ['imageId' => $key]) {
            return false;
        }

        $children = $this->elementChildren($figure);

        if ($children === null || count($children) !== 2) {
            return false;
        }

        $image = collect($children)->first(fn (DOMElement $element): bool => strtolower($element->tagName) === 'img');
        $button = collect($children)->first(fn (DOMElement $element): bool => strtolower($element->tagName) === 'button');

        if (! $image instanceof DOMElement
            || ! $button instanceof DOMElement
            || ! $this->isStrictWordpressLightboxImage($image)
            || ! $this->isStrictWordpressLightboxButton($button)) {
            return false;
        }

        foreach (array_keys($this->wordpressImageInteractiveAttributes()) as $attribute) {
            $image->removeAttribute($attribute);
        }

        $button->parentNode?->removeChild($button);
        $figure->removeAttribute('data-wp-context');
        $figure->removeAttribute('data-wp-interactive');
        $figure->removeAttribute('data-wp-key');

        return true;
    }

    private function hasExpectedWordpressImageClasses(DOMElement $figure): bool
    {
        $classes = $this->classTokens($figure);

        if (! in_array('wp-block-image', $classes, true) || ! in_array('wp-lightbox-container', $classes, true)) {
            return false;
        }

        foreach ($classes as $class) {
            if (in_array($class, ['wp-block-image', 'wp-lightbox-container'], true)
                || preg_match('/^size-(?:thumbnail|medium|large|full)$/', $class) === 1) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function isStrictWordpressLightboxImage(DOMElement $image): bool
    {
        $interactive = $this->wordpressImageInteractiveAttributes();
        $allowed = array_merge([
            'alt',
            'class',
            'data-id',
            'decoding',
            'fetchpriority',
            'height',
            'loading',
            'sizes',
            'src',
            'srcset',
            'width',
        ], array_keys($interactive));

        if (array_diff(array_keys($this->attributes($image)), $allowed) !== []) {
            return false;
        }

        foreach ($interactive as $attribute => $expected) {
            if ($image->getAttribute($attribute) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function wordpressImageInteractiveAttributes(): array
    {
        return [
            'data-wp-class--hide' => 'state.isContentHidden',
            'data-wp-class--show' => 'state.isContentVisible',
            'data-wp-init' => 'callbacks.setButtonStyles',
            'data-wp-on--click' => 'actions.showLightbox',
            'data-wp-on--load' => 'callbacks.setButtonStyles',
            'data-wp-on--pointerdown' => 'actions.preloadImage',
            'data-wp-on--pointerenter' => 'actions.preloadImageWithDelay',
            'data-wp-on--pointerleave' => 'actions.cancelPreload',
            'data-wp-on-window--resize' => 'callbacks.setButtonStyles',
        ];
    }

    private function isStrictWordpressLightboxButton(DOMElement $button): bool
    {
        $expected = [
            'aria-haspopup' => 'dialog',
            'class' => 'lightbox-trigger',
            'data-wp-bind--aria-label' => 'state.thisImage.triggerButtonAriaLabel',
            'data-wp-init' => 'callbacks.initTriggerButton',
            'data-wp-on--click' => 'actions.showLightbox',
            'data-wp-style--right' => 'state.thisImage.buttonRight',
            'data-wp-style--top' => 'state.thisImage.buttonTop',
            'type' => 'button',
        ];

        if (! $this->attributesMatch($button, $expected)) {
            return false;
        }

        $children = $this->elementChildren($button);

        if ($children === null || count($children) !== 1 || strtolower($children[0]->tagName) !== 'svg') {
            return false;
        }

        $svg = $children[0];

        if (! $this->attributesMatch($svg, [
            'fill' => 'none',
            'height' => '12',
            'viewbox' => '0 0 12 12',
            'width' => '12',
            'xmlns' => 'http://www.w3.org/2000/svg',
        ])) {
            return false;
        }

        $svgChildren = $this->elementChildren($svg);

        return $svgChildren !== null
            && count($svgChildren) === 1
            && strtolower($svgChildren[0]->tagName) === 'path'
            && $this->attributesMatch($svgChildren[0], [
                'd' => self::WORDPRESS_LIGHTBOX_ICON_PATH,
                'fill' => '#fff',
            ]);
    }

    private function normalizeWordpressInteractiveGallery(DOMElement $figure): bool
    {
        if (! $this->hasExactAttributeNames($figure, ['class', 'data-wp-context', 'data-wp-interactive'])
            || ! $this->hasExpectedWordpressGalleryClasses($figure)) {
            return false;
        }

        $context = json_decode($figure->getAttribute('data-wp-context'), true);
        $children = $this->elementChildren($figure);

        if (! is_array($context)
            || count($context) !== 1
            || preg_match('/^[a-f0-9]{10,32}$/', (string) ($context['galleryId'] ?? '')) !== 1
            || $children === null
            || $children === []
            || collect($children)->contains(fn (DOMElement $child): bool => strtolower($child->tagName) !== 'figure')) {
            return false;
        }

        foreach ($figure->getElementsByTagName('*') as $descendant) {
            if ($descendant instanceof DOMElement && $descendant->hasAttribute('data-wp-interactive')) {
                return false;
            }
        }

        if ($figure->getElementsByTagName('button')->length > 0 || $figure->getElementsByTagName('svg')->length > 0) {
            return false;
        }

        $figure->removeAttribute('data-wp-context');
        $figure->removeAttribute('data-wp-interactive');

        return true;
    }

    private function hasExpectedWordpressGalleryClasses(DOMElement $figure): bool
    {
        $classes = $this->classTokens($figure);

        if (! in_array('wp-block-gallery', $classes, true) || ! in_array('has-nested-images', $classes, true)) {
            return false;
        }

        foreach ($classes as $class) {
            if (in_array($class, [
                'wp-block-gallery',
                'has-nested-images',
                'is-cropped',
                'is-layout-flex',
                'wp-block-gallery-is-layout-flex',
            ], true)
                || preg_match('/^(?:columns-(?:default|\d+)|wp-block-gallery-\d+)$/', $class) === 1) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function normalizeWordpressOembedFallbacks(DOMElement $root): int
    {
        $count = 0;

        foreach ($this->elements($root, 'blockquote') as $blockquote) {
            if ($this->classTokens($blockquote) !== ['wp-embedded-content']
                || ! $this->hasExactAttributeNames($blockquote, ['class', 'data-secret'])) {
                continue;
            }

            $secret = $blockquote->getAttribute('data-secret');
            $children = $this->elementChildren($blockquote);

            if (preg_match('/^[A-Za-z0-9]{10}$/', $secret) !== 1
                || $children === null
                || count($children) !== 1
                || strtolower($children[0]->tagName) !== 'a'
                || ! $this->hasExactAttributeNames($children[0], ['href'])) {
                continue;
            }

            $link = $children[0]->getAttribute('href');
            $hasMatchingIframe = collect($this->elements($root, 'iframe'))->contains(
                fn (DOMElement $iframe): bool => in_array('wp-embedded-content', $this->classTokens($iframe), true)
                    && hash_equals($secret, $iframe->getAttribute('data-secret'))
                    && $this->wordpressEmbedSourceMatchesLink($iframe->getAttribute('src'), $link),
            );

            if (! $hasMatchingIframe) {
                continue;
            }

            $blockquote->removeAttribute('data-secret');
            $count++;
        }

        return $count;
    }

    private function wordpressEmbedSourceMatchesLink(string $source, string $link): bool
    {
        $source = $this->iframeUrlPolicy->normalize($source);
        $sourceParts = $source === null ? null : parse_url($source);
        $linkParts = parse_url(html_entity_decode(trim($link), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (! is_array($sourceParts)
            || ! is_array($linkParts)
            || strtolower((string) ($linkParts['scheme'] ?? '')) !== 'https'
            || isset($linkParts['user'])
            || isset($linkParts['pass'])
            || isset($linkParts['port'])
            || isset($linkParts['query'])
            || isset($linkParts['fragment'])) {
            return false;
        }

        return strtolower((string) ($sourceParts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($sourceParts['host'] ?? '')) === strtolower((string) ($linkParts['host'] ?? ''))
            && (string) ($sourceParts['path'] ?? '') === rtrim((string) ($linkParts['path'] ?? ''), '/').'/embed/'
            && (string) ($sourceParts['query'] ?? '') === '';
    }

    /** @return array<int, DOMElement>|null */
    private function elementChildren(DOMElement $element): ?array
    {
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;

                continue;
            }

            if ($child->nodeType !== XML_TEXT_NODE || trim($child->nodeValue ?? '') !== '') {
                return null;
            }
        }

        return $children;
    }

    private function hasOnlyElementAndTextDescendants(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if (! $this->hasOnlyElementAndTextDescendants($child)) {
                    return false;
                }

                continue;
            }

            if ($child->nodeType !== XML_TEXT_NODE) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, string> $expected */
    private function hasExactAttributeNames(DOMElement $element, array $expected): bool
    {
        $actual = array_keys($this->attributes($element));
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    /** @param array<string, string> $expected */
    private function attributesMatch(DOMElement $element, array $expected): bool
    {
        $actual = $this->attributes($element);
        ksort($actual);
        ksort($expected);

        return $actual === $expected;
    }

    /** @return array<int, string> */
    private function classTokens(DOMElement $element): array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($element->getAttribute('class'))), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = is_array($tokens) ? array_values(array_unique($tokens)) : [];
        sort($tokens);

        return $tokens;
    }

    private function trustedFallbackProvider(DOMElement $blockquote): ?string
    {
        $classes = preg_split('/\s+/', strtolower(trim($blockquote->getAttribute('class'))), -1, PREG_SPLIT_NO_EMPTY);
        $classes = is_array($classes) ? $classes : [];

        $expected = match (true) {
            in_array('instagram-media', $classes, true) => 'instagram',
            in_array('twitter-tweet', $classes, true) => 'twitter',
            in_array('tiktok-embed', $classes, true) => 'tiktok',
            default => null,
        };

        if ($expected === null) {
            return null;
        }

        $urls = [];

        if ($blockquote->hasAttribute('cite')) {
            $urls[] = $blockquote->getAttribute('cite');
        }

        foreach ($blockquote->getElementsByTagName('a') as $anchor) {
            if ($anchor instanceof DOMElement && $anchor->hasAttribute('href')) {
                $urls[] = $anchor->getAttribute('href');
            }
        }

        foreach ($urls as $url) {
            if ($this->isProviderFallbackUrl($expected, $url)) {
                return $expected;
            }
        }

        return null;
    }

    private function isProviderFallbackUrl(string $provider, string $url): bool
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = $parts['path'] ?? '';

        return match ($provider) {
            'instagram' => in_array($host, ['instagram.com', 'www.instagram.com'], true)
                && preg_match('#^/(?:p|reel|tv)/[A-Za-z0-9_-]+/?$#', $path) === 1,
            'twitter' => in_array($host, ['twitter.com', 'www.twitter.com', 'x.com', 'www.x.com'], true)
                && preg_match('#^/[A-Za-z0-9_]+/status/\d+/?$#', $path) === 1,
            'tiktok' => $host === 'www.tiktok.com'
                && preg_match('#^/@[^/]+/video/\d+/?$#', $path) === 1,
            default => false,
        };
    }

    private function trustedScriptProvider(DOMElement $script): ?string
    {
        if (trim((string) $script->textContent) !== '' || ! $script->hasAttribute('src')) {
            return null;
        }

        $attributes = $this->attributes($script);

        if (array_diff(array_keys($attributes), ['src', 'async', 'charset']) !== []) {
            return null;
        }

        if (isset($attributes['charset']) && strtolower($attributes['charset']) !== 'utf-8') {
            return null;
        }

        return match (trim($script->getAttribute('src'))) {
            '//www.instagram.com/embed.js', 'https://www.instagram.com/embed.js' => 'instagram',
            'https://platform.twitter.com/widgets.js' => 'twitter',
            'https://www.tiktok.com/embed.js' => 'tiktok',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function desiredIframeAttributes(DOMElement $iframe, string $source): array
    {
        $attributes = ['src' => $source];
        $title = trim($iframe->getAttribute('title'));

        if ($title !== '') {
            $attributes['title'] = $title;
        }

        foreach (['width', 'height'] as $dimension) {
            $value = trim($iframe->getAttribute($dimension));

            if (preg_match('/^(?:\d{1,4}|100%)$/', $value) === 1) {
                $attributes[$dimension] = $value;
            }
        }

        $loading = strtolower(trim($iframe->getAttribute('loading')));

        if (in_array($loading, ['eager', 'lazy'], true)) {
            $attributes['loading'] = $loading;
        }

        $referrerPolicy = strtolower(trim($iframe->getAttribute('referrerpolicy')));

        if (in_array($referrerPolicy, [
            'no-referrer',
            'no-referrer-when-downgrade',
            'origin',
            'origin-when-cross-origin',
            'same-origin',
            'strict-origin',
            'strict-origin-when-cross-origin',
        ], true)) {
            $attributes['referrerpolicy'] = $referrerPolicy;
        }

        if ($iframe->hasAttribute('allowfullscreen')) {
            $attributes['allowfullscreen'] = '';
        }

        $attributes['sandbox'] = self::IFRAME_SANDBOX;

        return $attributes;
    }

    private function hasExecutableIframeAttribute(DOMElement $iframe): bool
    {
        foreach (array_keys($this->attributes($iframe)) as $attribute) {
            if ($attribute === 'srcdoc' || str_starts_with($attribute, 'on')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function attributes(DOMElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[strtolower($attribute->name)] = $attribute->value;
        }

        return $attributes;
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
