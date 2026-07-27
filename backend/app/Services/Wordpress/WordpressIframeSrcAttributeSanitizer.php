<?php

namespace App\Services\Wordpress;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

final class WordpressIframeSrcAttributeSanitizer implements AttributeSanitizerInterface
{
    public function __construct(private readonly WordpressIframeUrlPolicy $urlPolicy) {}

    public function getSupportedElements(): ?array
    {
        return ['iframe'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return $this->urlPolicy->normalize($value);
    }
}
