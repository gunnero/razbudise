<?php

namespace App\Services\Wordpress;

use App\Models\MediaAsset;
use App\Services\Media\MediaVariantGenerator;
use DOMDocument;
use DOMElement;
use DOMNode;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LengthException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Throwable;

class WordpressContentSanitizer
{
    private const int MAX_DOWNLOAD_BYTES = 8_000_000;

    /** @var array<string, string> */
    private const array RASTER_IMAGE_EXTENSIONS = [
        'image/avif' => 'avif',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private bool $mediaDownloadsEnabled = true;

    private ?string $lastDownloadError = null;

    private int $downloadTimeoutSeconds = 30;

    private ?HtmlSanitizer $htmlSanitizer = null;

    /** @var array<int, string> */
    private array $scopedMediaHosts = [];

    public function __construct(
        private readonly MediaVariantGenerator $variantGenerator,
        private readonly WordpressEmbedNormalizer $embedNormalizer,
        private readonly WordpressLegacyMarkupNormalizer $legacyMarkupNormalizer,
        private readonly WordpressIframeUrlPolicy $iframeUrlPolicy,
    ) {}

    public function setMediaDownloadsEnabled(bool $enabled): void
    {
        $this->mediaDownloadsEnabled = $enabled;
    }

    public function lastDownloadError(): ?string
    {
        return $this->lastDownloadError;
    }

    public function setDownloadTimeoutSeconds(int $seconds): void
    {
        $this->downloadTimeoutSeconds = max(1, $seconds);
    }

    public function downloadTimeoutSeconds(): int
    {
        return $this->downloadTimeoutSeconds;
    }

    public function setMediaSourceBase(?string $baseUrl): void
    {
        $this->scopedMediaHosts = [];

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            return;
        }

        try {
            $uri = new Uri(trim($baseUrl));
        } catch (Throwable) {
            return;
        }

        if (in_array(strtolower($uri->getScheme()), ['http', 'https'], true)
            && $uri->getHost() !== ''
            && $uri->getUserInfo() === ''
            && $uri->getPort() === null) {
            $this->scopedMediaHosts[] = strtolower($uri->getHost());
        }
    }

    /**
     * @return array{html: string|null, warnings: array<int, string>, normalization_counts: array<string, int>}
     */
    public function clean(
        ?string $html,
        bool $removePersistedBlobImages = false,
        array $trustedUnavailableInlineMedia = [],
    ): array {
        return $this->cleanContent(
            $html,
            rewriteInlineMedia: true,
            removePersistedBlobImages: $removePersistedBlobImages,
            trustedUnavailableInlineMedia: $trustedUnavailableInlineMedia,
        );
    }

    /**
     * Inspect raw source content without network requests or persistence.
     *
     * @return array{html: string|null, warnings: array<int, string>, normalization_counts: array<string, int>}
     */
    public function preview(
        ?string $html,
        bool $removePersistedBlobImages = false,
        array $trustedUnavailableInlineMedia = [],
    ): array {
        return $this->cleanContent(
            $html,
            rewriteInlineMedia: false,
            removePersistedBlobImages: $removePersistedBlobImages,
            trustedUnavailableInlineMedia: $trustedUnavailableInlineMedia,
        );
    }

    /**
     * @return array{html: string|null, warnings: array<int, string>, normalization_counts: array<string, int>}
     */
    private function cleanContent(
        ?string $html,
        bool $rewriteInlineMedia,
        bool $removePersistedBlobImages,
        array $trustedUnavailableInlineMedia,
    ): array {
        if ($html === null) {
            return ['html' => null, 'warnings' => [], 'normalization_counts' => []];
        }

        $warnings = [];
        $html = preg_replace('/\[post_tags\]/i', '', $html) ?? $html;

        if (preg_match('/\[(gallery|caption|embed|video|audio|playlist)[^\]]*\]/i', $html)) {
            $warnings[] = 'unsupported_shortcode';
            $html = preg_replace('/\[(gallery|caption|embed|video|audio|playlist)[^\]]*\](?:.*?\[\/\1\])?/is', '', $html) ?? $html;
        }

        if (preg_match('/<iframe\b(?=[^>]*\bsrc=(["\'])\s*\1)[^>]*><\/iframe>/i', $html)) {
            $warnings[] = 'broken_embed';
            $html = preg_replace('/<iframe\b(?=[^>]*\bsrc=(["\'])\s*\1)[^>]*><\/iframe>/i', '', $html) ?? $html;
        }

        $normalizedEmbeds = $this->embedNormalizer->normalize($html);
        $html = $normalizedEmbeds['html'];
        $warnings = array_merge($warnings, $normalizedEmbeds['warnings']);

        $html = preg_replace('/\s(?:class|style)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/<p>(?:\s|&nbsp;)*<\/p>/i', '', $html) ?? $html;
        $normalizedLegacyMarkup = $this->legacyMarkupNormalizer->normalize(
            $html,
            $removePersistedBlobImages,
        );
        $html = $normalizedLegacyMarkup['html'];
        $warnings = array_merge($warnings, $normalizedLegacyMarkup['warnings']);
        $unavailableInlineMedia = $this->removeTrustedUnavailableInlineImages(
            $html,
            $trustedUnavailableInlineMedia,
        );
        $html = $unavailableInlineMedia['html'];
        $warnings = array_merge($warnings, $unavailableInlineMedia['warnings']);
        $html = $this->sanitizeHtml($html, $warnings);
        $html = $this->dropIframesWithoutSource($html, $warnings);
        if ($rewriteInlineMedia) {
            $html = $this->rewriteInlineImages($html, $warnings);
        }

        $warnings = array_values(array_unique($warnings));

        if ($this->requiresManualReview($warnings)) {
            $warnings[] = 'manual_review';
            $warnings = array_values(array_unique($warnings));
        }

        return [
            'html' => trim($html),
            'warnings' => $warnings,
            'normalization_counts' => $this->mergeNormalizationCounts(
                $normalizedEmbeds['normalization_counts'],
                $normalizedLegacyMarkup['normalization_counts'],
                $unavailableInlineMedia['normalization_counts'],
            ),
        ];
    }

    /**
     * Remove only image elements whose exact source URL and occurrence count
     * were authorized by manifest-bound reconciliation evidence. Temporary
     * markers let us prune wrappers emptied by the deletion while preserving
     * figure captions and surrounding editorial content.
     *
     * @param  array<int, array<string, mixed>>  $evidence
     * @return array{html: string, warnings: array<int, string>, normalization_counts: array<string, int>}
     */
    private function removeTrustedUnavailableInlineImages(string $html, array $evidence): array
    {
        if ($evidence === [] || stripos($html, '<img') === false) {
            return ['html' => $html, 'warnings' => [], 'normalization_counts' => []];
        }

        $expectedCounts = [];
        foreach ($evidence as $entry) {
            if (! is_array($entry)
                || array_is_list($entry)
                || count($entry) !== 4
                || ! array_key_exists('source_url', $entry)
                || ! array_key_exists('occurrences', $entry)
                || ! array_key_exists('role', $entry)
                || ! array_key_exists('treatment', $entry)
                || ! is_string($entry['source_url'])
                || trim($entry['source_url']) === ''
                || trim($entry['source_url']) !== $entry['source_url']
                || ! is_int($entry['occurrences'])
                || $entry['occurrences'] < 1
                || $entry['role'] !== 'inline'
                || $entry['treatment'] !== 'remove_element') {
                return ['html' => $html, 'warnings' => [], 'normalization_counts' => []];
            }

            $sourceUrl = html_entity_decode($entry['source_url'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (array_key_exists($sourceUrl, $expectedCounts)) {
                return ['html' => $html, 'warnings' => [], 'normalization_counts' => []];
            }

            $expectedCounts[$sourceUrl] = $entry['occurrences'];
        }

        if ($expectedCounts === []) {
            return ['html' => $html, 'warnings' => [], 'normalization_counts' => []];
        }

        $actualCounts = array_fill_keys(array_keys($expectedCounts), 0);
        preg_replace_callback(
            '/<img\b[^>]*\bsrc=(["\'])([^"\']+)\1[^>]*>/i',
            function (array $matches) use (&$actualCounts): string {
                $sourceUrl = html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (array_key_exists($sourceUrl, $actualCounts)) {
                    $actualCounts[$sourceUrl]++;
                }

                return $matches[0];
            },
            $html,
        );

        foreach ($expectedCounts as $sourceUrl => $expectedCount) {
            if (($actualCounts[$sourceUrl] ?? 0) !== $expectedCount) {
                return ['html' => $html, 'warnings' => [], 'normalization_counts' => []];
            }
        }

        $authorizedSources = array_fill_keys(array_keys($expectedCounts), true);
        $markerSuffix = 0;
        do {
            $removalMarker = '<!--razbudise-trusted-unavailable-media-'.$markerSuffix.'-->';
            $markerSuffix++;
        } while (str_contains($html, $removalMarker));

        $removed = 0;
        $html = preg_replace_callback(
            '/<img\b[^>]*\bsrc=(["\'])([^"\']+)\1[^>]*>/i',
            function (array $matches) use ($authorizedSources, $removalMarker, &$removed): string {
                $sourceUrl = html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (! isset($authorizedSources[$sourceUrl])) {
                    return $matches[0];
                }

                $removed++;

                return $removalMarker;
            },
            $html,
        ) ?? $html;

        if ($removed > 0) {
            $quotedMarker = preg_quote($removalMarker, '/');
            $markerOnlyContent = '(?:\s|&nbsp;|'.$quotedMarker.')*';
            $startsWithMarker = '(?=(?:\s|&nbsp;)*'.$quotedMarker.')';
            $html = preg_replace(
                '/<a\b[^>]*>'.$startsWithMarker.$markerOnlyContent.'<\/a>/i',
                $removalMarker,
                $html,
            ) ?? $html;
            $html = preg_replace(
                '/<(p|figure)\b[^>]*>'.$startsWithMarker.$markerOnlyContent.'<\/\1>/i',
                '',
                $html,
            ) ?? $html;
            $html = str_replace($removalMarker, '', $html);
        }

        return $removed > 0
            ? [
                'html' => $html,
                'warnings' => ['trusted_unavailable_inline_media'],
                'normalization_counts' => ['trusted_unavailable_inline_media' => $removed],
            ]
            : ['html' => $html, 'warnings' => [], 'normalization_counts' => []];
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function sanitizeHtml(string $html, array &$warnings): string
    {
        $sanitized = $this->htmlSanitizer()->sanitize($html);

        if ($sanitized === $html) {
            return $html;
        }

        $before = $this->canonicalHtml($html);
        $after = $this->canonicalHtml($sanitized);

        if ($before !== null && $before === $after) {
            return $this->hasBenignWordpressAttributes($html) ? $sanitized : $html;
        }

        if (in_array('trusted_iframe_hardened', $warnings, true)) {
            $beforeWithoutIframes = $this->canonicalHtml($html, 'iframe');
            $afterWithoutIframes = $this->canonicalHtml($sanitized, 'iframe');

            if ($beforeWithoutIframes !== null && $beforeWithoutIframes === $afterWithoutIframes) {
                return $sanitized;
            }
        }

        $warnings[] = 'unsafe_html_removed';

        return $sanitized;
    }

    private function hasBenignWordpressAttributes(string $html): bool
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div>'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );

            if (! $loaded) {
                return false;
            }

            foreach ($document->getElementsByTagName('*') as $element) {
                if (! $element instanceof DOMElement) {
                    continue;
                }

                foreach ($element->attributes as $attribute) {
                    if ($this->isBenignWordpressAttribute(
                        $element,
                        strtolower($attribute->nodeName),
                        $attribute->nodeValue ?? '',
                    )) {
                        return true;
                    }
                }
            }

            return false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    private function htmlSanitizer(): HtmlSanitizer
    {
        if ($this->htmlSanitizer) {
            return $this->htmlSanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->dropAttribute('class', '*')
            ->dropAttribute('style', '*')
            ->dropAttribute('data-id', 'img')
            ->dropAttribute('fetchpriority', 'img')
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowElement('iframe', ['src', 'title', 'width', 'height', 'loading', 'referrerpolicy', 'allowfullscreen'])
            ->forceAttribute('iframe', 'sandbox', 'allow-scripts allow-same-origin allow-presentation')
            ->withAttributeSanitizer(new WordpressIframeSrcAttributeSanitizer($this->iframeUrlPolicy))
            ->withAttributeSanitizer(new WordpressSrcsetAttributeSanitizer)
            ->withMaxInputLength(-1);

        return $this->htmlSanitizer = new HtmlSanitizer($config);
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function dropIframesWithoutSource(string $html, array &$warnings): string
    {
        return preg_replace_callback('/<iframe\b[^>]*>.*?<\/iframe>/is', function (array $matches) use (&$warnings): string {
            if (preg_match('/\ssrc=(?:"[^"]+"|\'[^\']+\')/i', $matches[0]) === 1) {
                return $matches[0];
            }

            $warnings[] = 'untrusted_iframe_removed';

            return '';
        }, $html) ?? $html;
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function requiresManualReview(array $warnings): bool
    {
        return array_intersect($warnings, [
            'broken_embed',
            'inline_image_download_failed',
            'missing_featured_image',
            'unsafe_html_removed',
            'unsupported_shortcode',
            'untrusted_iframe_removed',
        ]) !== [];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function canonicalHtml(string $html, ?string $ignoredTag = null): ?array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div id="razbudise-sanitizer-root">'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );

            if (! $loaded) {
                return null;
            }

            $canonical = [];

            // Malformed fragment markup can close the sentinel div early. Walk the
            // whole parsed document so siblings that escape the wrapper remain part
            // of the comparison and cannot conceal sanitizer removals.
            foreach ($document->childNodes as $child) {
                $node = $this->canonicalNode($child, $ignoredTag);

                if ($node !== null) {
                    $canonical[] = $node;
                }
            }

            return $canonical;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function canonicalNode(DOMNode $node, ?string $ignoredTag): ?array
    {
        if ($node instanceof DOMElement) {
            $name = strtolower($node->tagName);

            if ($ignoredTag !== null && $name === strtolower($ignoredTag)) {
                return null;
            }

            $attributes = [];

            foreach ($node->attributes as $attribute) {
                $attributeName = strtolower($attribute->nodeName);

                if ($this->isBenignWordpressAttribute(
                    $node,
                    $attributeName,
                    $attribute->nodeValue ?? '',
                )) {
                    continue;
                }

                $attributes[$attributeName] = $attribute->nodeValue ?? '';
            }

            ksort($attributes);
            $children = [];

            foreach ($node->childNodes as $child) {
                $canonicalChild = $this->canonicalNode($child, $ignoredTag);

                if ($canonicalChild !== null) {
                    $children[] = $canonicalChild;
                }
            }

            return [
                'type' => 'element',
                'name' => $name,
                'attributes' => $attributes,
                'children' => $children,
            ];
        }

        if ($node->nodeType === XML_TEXT_NODE) {
            return [
                'type' => 'text',
                'value' => $this->normalizeLineEndings($node->nodeValue ?? ''),
            ];
        }

        if ($node->nodeType === XML_COMMENT_NODE) {
            return [
                'type' => 'comment',
                'value' => $this->normalizeLineEndings($node->nodeValue ?? ''),
            ];
        }

        $children = [];

        foreach ($node->childNodes as $child) {
            $canonicalChild = $this->canonicalNode($child, $ignoredTag);

            if ($canonicalChild !== null) {
                $children[] = $canonicalChild;
            }
        }

        return [
            'type' => 'node',
            'name' => $node->nodeName,
            'value' => $node->nodeValue ?? '',
            'children' => $children,
        ];
    }

    private function isBenignWordpressAttribute(DOMElement $element, string $attributeName, string $value): bool
    {
        $name = strtolower($element->tagName);

        if ($name === 'img' && in_array($attributeName, ['data-id', 'fetchpriority'], true)) {
            return true;
        }

        if ($name === 'img'
            && $attributeName === 'data-object-fit'
            && in_array(strtolower(trim($value)), ['contain', 'cover', 'fill', 'none', 'scale-down'], true)) {
            return true;
        }

        if ($name === 'a' && $attributeName === 'data-type' && strtoupper(trim($value)) === 'URL') {
            return true;
        }

        if ($name === 'a'
            && $attributeName === 'data-id'
            && trim($value) !== ''
            && trim($value) === trim($element->getAttribute('href'))) {
            return true;
        }

        if (in_array($name, ['em', 'strong'], true)
            && $attributeName === 'data-rich-text-format-boundary'
            && strtolower(trim($value)) === 'true') {
            return true;
        }

        return in_array($name, ['div', 'span'], true)
            && $attributeName === 'aria-hidden'
            && strtolower(trim($value)) === 'true';
    }

    private function normalizeLineEndings(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    /**
     * @param  array<string, int>  ...$groups
     * @return array<string, int>
     */
    private function mergeNormalizationCounts(array ...$groups): array
    {
        $merged = [];

        foreach ($groups as $group) {
            foreach ($group as $key => $count) {
                $merged[$key] = (int) ($merged[$key] ?? 0) + max(0, (int) $count);
            }
        }

        return array_filter($merged);
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function rewriteInlineImages(string $html, array &$warnings): string
    {
        return preg_replace_callback('/<img\b[^>]*\bsrc=(["\'])([^"\']+)\1[^>]*>/i', function (array $matches) use (&$warnings): string {
            $sourceUrl = html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (! str_contains($sourceUrl, '/wp-content/uploads/')) {
                return $matches[0];
            }

            $asset = $this->downloadMedia($sourceUrl, [
                'alt_text' => $this->attributeValue($matches[0], 'alt'),
                'source' => 'wordpress_inline_image',
            ]);

            if (! $asset) {
                $warnings[] = 'inline_image_download_failed';

                return $matches[0];
            }

            return str_replace($matches[2], (string) $asset->url, $matches[0]);
        }, $html) ?? $html;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function downloadMedia(string $sourceUrl, array $attributes = []): ?MediaAsset
    {
        $this->lastDownloadError = null;

        $existing = MediaAsset::query()
            ->where('metadata->source_url', $sourceUrl)
            ->where('disk', 'public')
            ->first();

        if ($existing && $this->cachedMediaExists($existing)) {
            return $existing;
        }

        $existing ??= MediaAsset::query()
            ->where('metadata->source_url', $sourceUrl)
            ->where('disk', 'external')
            ->first();

        if (! $this->mediaDownloadsEnabled) {
            $this->lastDownloadError = 'Media downloads are disabled.';

            return null;
        }

        if ((int) ($attributes['size_bytes'] ?? 0) > self::MAX_DOWNLOAD_BYTES) {
            $this->lastDownloadError = $this->tooLargeMessage((int) $attributes['size_bytes']);

            return null;
        }

        $resolvedSource = $this->resolveAllowedMediaSource($sourceUrl);

        if ($resolvedSource === null) {
            $this->lastDownloadError = 'Source media URL is not on an allowed public WordPress media host.';

            return null;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'razbudise-wp-media-');

        if (! is_string($tempPath)) {
            $this->lastDownloadError = 'Temporary download file could not be created.';

            return null;
        }

        try {
            try {
                $response = Http::withHeaders(['Accept-Encoding' => 'identity'])
                    ->withOptions($this->mediaRequestOptions($resolvedSource))
                    ->timeout($this->downloadTimeoutSeconds)
                    ->sink($tempPath)
                    ->get($sourceUrl);
            } catch (LengthException $exception) {
                $this->lastDownloadError = $exception->getMessage();

                return null;
            } catch (Throwable $exception) {
                if ($this->throwableContainsMessage($exception, 'Compressed source media responses are not allowed.')) {
                    $this->lastDownloadError = 'Compressed source media responses are not allowed.';

                    return null;
                }

                $this->lastDownloadError = trim($exception->getMessage()) !== ''
                    ? 'Media request failed: '.$exception->getMessage()
                    : 'Media request failed.';

                return null;
            }

            $contentLength = $this->contentLength($response->header('Content-Length'));

            if ($this->hasUnsupportedContentEncoding($response->header('Content-Encoding'))) {
                $this->lastDownloadError = 'Compressed source media responses are not allowed.';

                return null;
            }

            if (! $response->successful()) {
                if ($response->redirect()) {
                    $this->lastDownloadError = 'Source media redirects are not allowed.';

                    return null;
                }

                $this->lastDownloadError = "HTTP {$response->status()} from source media URL.";

                return null;
            }

            if ($contentLength !== null && $contentLength > self::MAX_DOWNLOAD_BYTES) {
                $this->lastDownloadError = $this->tooLargeMessage($contentLength);

                return null;
            }

            $downloadedBytes = $this->downloadedFileSize($tempPath);

            if ($downloadedBytes === 0) {
                $body = $response->body();
                $downloadedBytes = strlen($body);

                if ($downloadedBytes === 0 || $downloadedBytes > self::MAX_DOWNLOAD_BYTES) {
                    $this->lastDownloadError = $downloadedBytes === 0
                        ? 'Source media response body was empty.'
                        : $this->tooLargeMessage($downloadedBytes);

                    return null;
                }

                if (file_put_contents($tempPath, $body) === false) {
                    $this->lastDownloadError = 'Temporary download file could not be written.';

                    return null;
                }
            }

            if ($downloadedBytes > self::MAX_DOWNLOAD_BYTES) {
                $this->lastDownloadError = $this->tooLargeMessage($downloadedBytes);

                return null;
            }

            $detectedImage = $this->detectedRasterImage($tempPath);

            if ($detectedImage === null) {
                $this->lastDownloadError = 'Source media is not a supported raster image.';

                return null;
            }

            if ($this->exceedsImageDimensionLimits($detectedImage['width'], $detectedImage['height'])) {
                $this->lastDownloadError = sprintf(
                    'Source media dimensions exceed limits (%dx%d; maximum side %d and maximum pixels %d).',
                    $detectedImage['width'],
                    $detectedImage['height'],
                    MediaVariantGenerator::MAX_IMAGE_SIDE,
                    MediaVariantGenerator::MAX_IMAGE_PIXELS,
                );

                return null;
            }

            $path = $this->storagePath($sourceUrl, $detectedImage['extension'], $attributes);
            $disk = Storage::disk('public');
            $stream = fopen($tempPath, 'rb');

            if ($stream === false) {
                $this->lastDownloadError = 'Temporary download file could not be opened.';

                return null;
            }

            try {
                if ($disk->put($path, $stream) === false) {
                    $this->lastDownloadError = 'Downloaded media could not be written to storage.';

                    return null;
                }
            } finally {
                fclose($stream);
            }

            $asset = $existing ?? new MediaAsset;
            $asset->forceFill([
                'disk' => 'public',
                'path' => $path,
                'url' => $disk->url($path),
                'mime_type' => $detectedImage['mime_type'],
                'alt_text' => $attributes['alt_text'] ?? null,
                'caption' => $attributes['caption'] ?? null,
                'width' => $detectedImage['width'],
                'height' => $detectedImage['height'],
                'size_bytes' => $disk->size($path) ?: $attributes['size_bytes'] ?? null,
                'metadata' => [
                    'wordpress_id' => $attributes['wordpress_id'] ?? null,
                    'source' => $attributes['source'] ?? 'wordpress_rest',
                    'source_url' => $sourceUrl,
                ],
            ])->save();

            $variants = $this->variantGenerator->generate($asset);

            if ($variants !== []) {
                $asset->forceFill([
                    'metadata' => array_filter(
                        ($asset->metadata ?? []) + ['responsive_variants' => $variants],
                        fn ($value) => $value !== null && $value !== '',
                    ),
                ])->save();
            }

            return $asset->refresh();
        } finally {
            @unlink($tempPath);
        }
    }

    private function contentLength(?string $header): ?int
    {
        if ($header === null || ! ctype_digit($header)) {
            return null;
        }

        return (int) $header;
    }

    /**
     * @return array{host: string, port: int, address: string}|null
     */
    private function resolveAllowedMediaSource(string $sourceUrl): ?array
    {
        try {
            $uri = new Uri($sourceUrl);
        } catch (Throwable) {
            return null;
        }

        $scheme = strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || $uri->getUserInfo() !== ''
            || $uri->getPort() !== null
            || ! in_array($host, $this->allowedMediaHosts(), true)) {
            return null;
        }

        $addresses = $this->publicAddressesForHost($host);

        if ($addresses === null) {
            return null;
        }

        return [
            'host' => $host,
            'port' => $scheme === 'https' ? 443 : 80,
            'address' => $addresses[0],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allowedMediaHosts(): array
    {
        $hosts = [];
        $baseUri = $this->configuredMediaHostUri((string) config('services.wordpress.base_url'));

        if ($baseUri !== null) {
            $hosts[] = strtolower($baseUri->getHost());
        }

        foreach ((array) config('services.wordpress.media_hosts', []) as $configuredHost) {
            $uri = $this->configuredMediaHostUri((string) $configuredHost);

            if ($uri !== null) {
                $hosts[] = strtolower($uri->getHost());
            }
        }

        return array_values(array_unique(array_merge($hosts, $this->scopedMediaHosts)));
    }

    private function configuredMediaHostUri(string $configuredHost): ?Uri
    {
        $configuredHost = trim($configuredHost);

        if ($configuredHost === '') {
            return null;
        }

        try {
            $uri = new Uri(str_contains($configuredHost, '://') ? $configuredHost : 'https://'.$configuredHost);
        } catch (Throwable) {
            return null;
        }

        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true)
            || $uri->getHost() === ''
            || $uri->getUserInfo() !== ''
            || $uri->getPort() !== null
            || ($uri->getPath() !== '' && $uri->getPath() !== '/')
            || $uri->getQuery() !== ''
            || $uri->getFragment() !== '') {
            return null;
        }

        return $uri;
    }

    /**
     * @return array<int, string>|null
     */
    private function publicAddressesForHost(string $host): ?array
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host) ? [$host] : null;
        }

        if (app()->environment('testing') && str_ends_with($host, '.test')) {
            return ['8.8.8.8'];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records) || $records === []) {
            return null;
        }

        $addresses = [];

        foreach ($records as $record) {
            if (is_string($record['ip'] ?? null)) {
                $addresses[] = $record['ip'];
            }

            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        $addresses = array_values(array_unique($addresses));

        return $addresses !== [] && collect($addresses)->every(fn (string $address): bool => $this->isPublicIp($address))
            ? $addresses
            : null;
    }

    /**
     * @param  array{host: string, port: int, address: string}  $resolvedSource
     * @return array<string, mixed>
     */
    private function mediaRequestOptions(array $resolvedSource): array
    {
        if (! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Secure source media DNS pinning is unavailable.');
        }

        $address = str_contains($resolvedSource['address'], ':')
            ? '['.$resolvedSource['address'].']'
            : $resolvedSource['address'];
        $curlOptions = [
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $resolvedSource['host'], $resolvedSource['port'], $address)],
        ];

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        return [
            'allow_redirects' => false,
            'decode_content' => false,
            'curl' => $curlOptions,
            'on_headers' => function (ResponseInterface $response): void {
                if ($this->hasUnsupportedContentEncoding($response->getHeaderLine('Content-Encoding'))) {
                    throw new RuntimeException('Compressed source media responses are not allowed.');
                }

                $contentLength = $this->contentLength($response->getHeaderLine('Content-Length'));

                if ($contentLength !== null && $contentLength > self::MAX_DOWNLOAD_BYTES) {
                    throw new LengthException($this->tooLargeMessage($contentLength));
                }
            },
            'progress' => function (
                int $downloadTotal,
                int $downloadedBytes,
                int $uploadTotal,
                int $uploadedBytes,
            ): void {
                if ($downloadedBytes > self::MAX_DOWNLOAD_BYTES) {
                    throw new LengthException($this->tooLargeMessage($downloadedBytes));
                }
            },
        ];
    }

    private function hasUnsupportedContentEncoding(?string $encoding): bool
    {
        return is_string($encoding)
            && trim($encoding) !== ''
            && strtolower(trim($encoding)) !== 'identity';
    }

    private function throwableContainsMessage(Throwable $exception, string $message): bool
    {
        do {
            if (str_contains($exception->getMessage(), $message)) {
                return true;
            }

            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return false;
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function downloadedFileSize(string $path): int
    {
        clearstatcache(true, $path);

        $size = is_file($path) ? filesize($path) : false;

        return $size === false ? 0 : (int) $size;
    }

    private function cachedMediaExists(MediaAsset $asset): bool
    {
        try {
            return $asset->disk !== null
                && $asset->path !== null
                && Storage::disk($asset->disk)->exists($asset->path);
        } catch (Throwable) {
            return false;
        }
    }

    private function exceedsImageDimensionLimits(int $width, int $height): bool
    {
        return ! $this->variantGenerator->dimensionsWithinLimits($width, $height);
    }

    private function tooLargeMessage(int $bytes): string
    {
        return sprintf('Source media is too large (%s bytes; limit %s bytes).', $bytes, self::MAX_DOWNLOAD_BYTES);
    }

    /**
     * @return array{mime_type: string, extension: string, width: int, height: int}|null
     */
    private function detectedRasterImage(string $path): ?array
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($path);
        $dimensions = @getimagesize($path);

        if (! is_string($detectedMime)
            || ! isset(self::RASTER_IMAGE_EXTENSIONS[$detectedMime])
            || ! is_array($dimensions)
            || ! is_string($dimensions['mime'] ?? null)
            || $dimensions['mime'] !== $detectedMime
            || (int) ($dimensions[0] ?? 0) < 1
            || (int) ($dimensions[1] ?? 0) < 1) {
            return null;
        }

        return [
            'mime_type' => $detectedMime,
            'extension' => self::RASTER_IMAGE_EXTENSIONS[$detectedMime],
            'width' => (int) $dimensions[0],
            'height' => (int) $dimensions[1],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storagePath(string $sourceUrl, string $extension, array $attributes): string
    {
        $pathInfo = pathinfo(parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl);
        $baseName = Str::slug($pathInfo['filename'] ?? 'wordpress-media') ?: 'wordpress-media';
        $prefix = (int) ($attributes['wordpress_id'] ?? 0) > 0
            ? (string) $attributes['wordpress_id']
            : md5($sourceUrl);

        return sprintf('wordpress/%s-%s.%s', $prefix, $baseName, $extension);
    }

    private function attributeValue(string $tag, string $attribute): ?string
    {
        if (! preg_match('/\s'.preg_quote($attribute, '/').'=(["\'])(.*?)\1/i', $tag, $matches)) {
            return null;
        }

        return trim(html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
    }
}
