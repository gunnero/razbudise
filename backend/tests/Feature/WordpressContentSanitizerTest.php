<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Services\Wordpress\WordpressContentSanitizer;
use App\Services\Wordpress\WordpressEmbedNormalizer;
use App\Services\Wordpress\WordpressIframeUrlPolicy;
use App\Services\Wordpress\WordpressLegacyMarkupNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WordpressContentSanitizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_executable_markup_and_dangerous_urls(): void
    {
        $html = <<<'HTML'
<section><script>window.compromised = true</script><iframe src="https://attacker.example/embed"></iframe><object data="https://attacker.example/payload"></object><svg><a href="javascript:alert(1)">SVG link</a></svg><p onclick="alert(1)">Безбеден текст.</p><a href="java&#x0A;script:alert(1)">Unsafe link</a><a href="j%61vascript:alert(2)">Encoded link</a><img src="data:image/svg+xml,&lt;svg onload=alert(1)&gt;" onerror="alert(1)"><img srcset="/storage/safe.jpg 1x, data:image/svg+xml,bad 2x"><img srcset="java%0Ascript:alert(3) 1x"><a href="https://example.test/story">Safe link</a><img src="/storage/safe.jpg" alt="Safe image" loading="lazy"><custom-widget><strong>Зачуван текст.</strong></custom-widget></section>
HTML;

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertIsString($result['html']);
        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('window.compromised', $result['html']);
        $this->assertStringNotContainsString('<iframe', $result['html']);
        $this->assertStringNotContainsString('<object', $result['html']);
        $this->assertStringNotContainsString('<svg', $result['html']);
        $this->assertStringNotContainsString('onclick=', $result['html']);
        $this->assertStringNotContainsString('onerror=', $result['html']);
        $this->assertStringNotContainsString('javascript:', $result['html']);
        $this->assertStringNotContainsString('j%61vascript:', $result['html']);
        $this->assertStringNotContainsString('java%0Ascript:', $result['html']);
        $this->assertStringNotContainsString('data:image', $result['html']);
        $this->assertStringNotContainsString('srcset=', $result['html']);
        $this->assertStringNotContainsString('<custom-widget', $result['html']);
        $this->assertStringContainsString('<p>Безбеден текст.</p>', $result['html']);
        $this->assertStringContainsString('<a href="https://example.test/story">Safe link</a>', $result['html']);
        $this->assertStringContainsString('src="/storage/safe.jpg"', $result['html']);
        $this->assertStringContainsString('alt="Safe image"', $result['html']);
        $this->assertStringContainsString('loading="lazy"', $result['html']);
        $this->assertContains('unsafe_html_removed', $result['warnings']);
        $this->assertContains('manual_review', $result['warnings']);
    }

    public function test_it_preserves_normal_editorial_html(): void
    {
        $html = '<article><h2>Наслов</h2><p lang="mk">Текст со <strong>нагласување</strong> и <a href="https://example.test/story">врска</a>.</p><figure><img src="/storage/story.jpg" srcset="/storage/story-480.jpg 480w, https://cdn.example.test/story.jpg 1200w" alt="Опис" width="1200" height="800"><figcaption>Фото опис</figcaption></figure><blockquote cite="https://example.test/source">Цитат</blockquote></article>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertSame($html, $result['html']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_it_drops_only_benign_wordpress_image_data_id_and_fetchpriority_without_manual_review(): void
    {
        $html = '<figure><img src="/storage/story.jpg" data-id="5938" fetchpriority="high" alt="Опис" width="1200" height="800"><figcaption>Фото опис</figcaption></figure>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertSame(
            '<figure><img src="/storage/story.jpg" alt="Опис" width="1200" height="800" /><figcaption>Фото опис</figcaption></figure>',
            $result['html'],
        );
        $this->assertSame([], $result['warnings']);
    }

    public function test_structural_comparison_treats_attribute_order_and_empty_attribute_serialization_as_benign(): void
    {
        $html = '<figure><img width="1200" alt="" fetchpriority="high" src="/storage/story.jpg" data-id="5938" height="800"></figure>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertStringContainsString('<img', $result['html']);
        $this->assertStringContainsString('src="/storage/story.jpg"', $result['html']);
        $this->assertStringContainsString('alt', $result['html']);
        $this->assertStringNotContainsString('data-id=', $result['html']);
        $this->assertStringNotContainsString('fetchpriority=', $result['html']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_canonical_dom_signature_is_independent_of_attribute_order_and_empty_attribute_syntax(): void
    {
        $method = new \ReflectionMethod(WordpressContentSanitizer::class, 'canonicalHtml');
        $sanitizer = app(WordpressContentSanitizer::class);

        $this->assertSame(
            $method->invoke($sanitizer, '<img src="/storage/story.jpg" alt="" loading="lazy">'),
            $method->invoke($sanitizer, '<img loading="lazy" alt src="/storage/story.jpg">'),
        );
    }

    public function test_benign_image_attributes_do_not_mask_other_data_attributes_scripts_svg_or_unsafe_links(): void
    {
        $payloads = [
            '<img src="/storage/story.jpg" data-id="5938" fetchpriority="high" data-owner="editorial">',
            '<img src="/storage/story.jpg" data-id="5938" fetchpriority="high"><script>alert(1)</script>',
            '<img src="/storage/story.jpg" data-id="5938" fetchpriority="high"><svg data-id="vector-id"><path d="M0 0h10v10z"></path></svg>',
            '<img src="/storage/story.jpg" data-id="5938" fetchpriority="high"><a href="javascript:alert(1)">Unsafe</a>',
        ];

        foreach ($payloads as $payload) {
            $result = app(WordpressContentSanitizer::class)->clean($payload);

            $this->assertStringNotContainsString('data-id=', $result['html']);
            $this->assertStringNotContainsString('fetchpriority=', $result['html']);
            $this->assertContains('unsafe_html_removed', $result['warnings']);
            $this->assertContains('manual_review', $result['warnings']);
        }
    }

    public function test_it_recovers_known_targets_and_unlinks_only_provably_invalid_http_authorities(): void
    {
        $html = <<<'HTML'
<p><a href="http://спектакл">спектакл</a></p><p><a href="http://&lt;a href=&quot;https://www.vecteezy.com/free-vector/news&quot;&gt;News vector&lt;/a&gt;">Автор</a></p><p><a href="http://chrome-extension://abcdefghijklmnop/https://libertania.org/wp-content/uploads/PLL.pdf">Документ</a></p><p><a href="http%3A%2F%2F%3Cblockquote%20data-instgrm-permalink%3D%22https%3A%2F%2Fwww.instagram.com%2Freel%2FAbCdEf123%2F%3Futm_source%3Dig_embed%22%3E">Instagram</a></p><p><a href="https://safe.example.test/story">Безбедна врска</a></p>
HTML;

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertStringContainsString('<a>спектакл</a>', $result['html']);
        $this->assertStringContainsString('href="https://www.vecteezy.com/free-vector/news"', $result['html']);
        $this->assertStringContainsString('href="https://libertania.org/wp-content/uploads/PLL.pdf"', $result['html']);
        $this->assertStringContainsString('href="https://www.instagram.com/reel/AbCdEf123/"', $result['html']);
        $this->assertStringContainsString('href="https://safe.example.test/story"', $result['html']);
        $this->assertSame(['trusted_malformed_link_normalized'], $result['warnings']);
        $this->assertSame(
            ['trusted_malformed_link_normalized' => 4],
            $result['normalization_counts'],
        );
    }

    public function test_it_preserves_unicode_hostname_links_without_reclassifying_them_as_corruption(): void
    {
        $html = '<p><a href="https://пример.мк/патека?x=1#дел">Македонски домен</a></p>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertSame($html, $result['html']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame([], $result['normalization_counts']);
    }

    public function test_it_preserves_valid_ascii_idn_and_punycode_hosts_and_ignores_pseudo_anchor_text(): void
    {
        $html = '<p><a href="https://valid.example.test/path">ASCII</a> <a href="https://пример.мк/патека">IDN</a> <a href="https://xn--e1afmkfd.xn--d1alf/path">Punycode</a></p><!-- <a href="http://not a host">comment</a> --><p>&lt;a href="http://not a host"&gt;text&lt;/a&gt;</p>';

        $result = app(WordpressLegacyMarkupNormalizer::class)->normalize($html);

        $this->assertSame($html, $result['html']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame([], $result['normalization_counts']);
    }

    public function test_it_removes_only_persisted_blob_images_and_preserves_surrounding_editorial_content(): void
    {
        $html = '<figure><img src="blob:https://razbudise.mk/first" onerror="alert(1)"><figcaption>Зачуван <strong>опис</strong>.</figcaption></figure><p>Зачуван текст <img src="BLOB:https://razbudise.mk/second"><a href="https://example.test/source">извор</a>.</p>';

        $result = app(WordpressContentSanitizer::class)->clean($html, removePersistedBlobImages: true);

        $this->assertStringNotContainsString('<img', $result['html']);
        $this->assertStringNotContainsString('blob:', strtolower((string) $result['html']));
        $this->assertStringNotContainsString('onerror', (string) $result['html']);
        $this->assertStringNotContainsString('alert(1)', (string) $result['html']);
        $this->assertStringContainsString('<figcaption>Зачуван <strong>опис</strong>.</figcaption>', $result['html']);
        $this->assertStringContainsString('Зачуван текст', $result['html']);
        $this->assertStringContainsString('href="https://example.test/source"', $result['html']);
        $this->assertSame(['trusted_blob_image_removed'], $result['warnings']);
        $this->assertSame(['trusted_blob_image_removed' => 2], $result['normalization_counts']);

        $secondPass = app(WordpressContentSanitizer::class)->clean($result['html']);

        $this->assertSame($result['html'], $secondPass['html']);
        $this->assertSame([], $secondPass['warnings']);
        $this->assertSame([], $secondPass['normalization_counts']);
    }

    public function test_it_does_not_trust_future_blob_images_without_an_explicit_decision(): void
    {
        $html = '<p>Text <img src="blob:https://example.test/random"> remains.</p>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertNotContains('trusted_blob_image_removed', $result['warnings']);
        $this->assertContains('unsafe_html_removed', $result['warnings']);
        $this->assertContains('manual_review', $result['warnings']);
        $this->assertSame([], $result['normalization_counts']);
        $this->assertStringContainsString('Text', (string) $result['html']);
        $this->assertStringContainsString('remains.', (string) $result['html']);
        $this->assertStringNotContainsString('blob:', strtolower((string) $result['html']));
    }

    public function test_it_removes_only_the_known_empty_legacy_icon_shape(): void
    {
        $result = app(WordpressContentSanitizer::class)->clean(
            '<p>Следете ја <img decoding="async" width="16" height="16" src="" alt="IMG_257"><a href="https://example.test/page">страницата</a>.</p>',
        );

        $this->assertStringNotContainsString('<img', $result['html']);
        $this->assertStringContainsString('страницата', $result['html']);
        $this->assertSame(['trusted_empty_icon_removed'], $result['warnings']);
        $this->assertSame(['trusted_empty_icon_removed' => 1], $result['normalization_counts']);

        $missingEditorialImage = app(WordpressContentSanitizer::class)->clean(
            '<figure><img width="1200" height="800" src="" alt="Главна фотографија"></figure>',
        );

        $this->assertContains('unsafe_html_removed', $missingEditorialImage['warnings']);
        $this->assertContains('manual_review', $missingEditorialImage['warnings']);
        $this->assertNotContains('trusted_empty_icon_removed', $missingEditorialImage['warnings']);
    }

    public function test_it_flattens_only_nested_links_with_the_same_target(): void
    {
        $url = 'https://example.test/source';
        $result = app(WordpressContentSanitizer::class)->clean(
            '<p><a href="'.$url.'"><em>Целосниот текст е <a href="'.$url.'">овде</a></em></a>.</p>',
        );

        $this->assertSame(1, substr_count((string) $result['html'], '<a '));
        $this->assertStringContainsString('Целосниот текст е', $result['html']);
        $this->assertStringContainsString('овде', $result['html']);
        $this->assertSame(['trusted_nested_link_normalized'], $result['warnings']);
        $this->assertSame(['trusted_nested_link_normalized' => 1], $result['normalization_counts']);
    }

    public function test_known_inert_wordpress_editor_attributes_do_not_trigger_manual_review(): void
    {
        $url = 'https://example.test/story';
        $html = '<p><a href="'.$url.'" data-type="URL" data-id="'.$url.'">Врска</a> <em data-rich-text-format-boundary="true">текст</em> <strong data-rich-text-format-boundary="true">текст</strong> <span aria-hidden="true">•</span></p><div aria-hidden="true"></div><img src="/storage/story.jpg" data-object-fit="cover">';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertStringNotContainsString('data-type=', $result['html']);
        $this->assertStringNotContainsString('data-id=', $result['html']);
        $this->assertStringNotContainsString('data-rich-text-format-boundary=', $result['html']);
        $this->assertStringNotContainsString('data-object-fit=', $result['html']);
        $this->assertStringNotContainsString('aria-hidden=', $result['html']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_it_converts_supported_divi_wrappers_and_images_to_static_html(): void
    {
        $html = '[et_pb_section][et_pb_row][et_pb_column type=”4_4″][et_pb_image src=”https://old.example.test/wp-content/uploads/story.jpg” title_text=”Опис″][/et_pb_image][et_pb_text]<p>Зачуван текст.</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';

        $result = app(WordpressContentSanitizer::class)->preview($html);

        $this->assertStringNotContainsString('[et_pb_', $result['html']);
        $this->assertStringContainsString('src="https://old.example.test/wp-content/uploads/story.jpg"', $result['html']);
        $this->assertStringContainsString('alt="Опис"', $result['html']);
        $this->assertStringContainsString('<p>Зачуван текст.</p>', $result['html']);
        $this->assertSame(['trusted_divi_shortcode_normalized'], $result['warnings']);
        $this->assertSame(9, $result['normalization_counts']['trusted_divi_shortcode_normalized']);
    }

    public function test_unknown_divi_modules_still_require_manual_review(): void
    {
        $result = app(WordpressContentSanitizer::class)->clean(
            '[et_pb_code]<script>alert(1)</script>[/et_pb_code]',
        );

        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertContains('unsupported_shortcode', $result['warnings']);
        $this->assertContains('unsafe_html_removed', $result['warnings']);
        $this->assertContains('manual_review', $result['warnings']);
        $this->assertNotContains('trusted_divi_shortcode_normalized', $result['warnings']);
    }

    public function test_crlf_serialization_does_not_turn_benign_wordpress_attributes_into_a_review_blocker(): void
    {
        $result = app(WordpressContentSanitizer::class)->clean(
            "<p>Прв ред\r\n\r\nВтор ред</p><img src=\"/storage/story.jpg\" data-id=\"5938\">",
        );

        $this->assertSame([], $result['warnings']);
    }

    public function test_benign_image_attribute_does_not_hide_script_escaping_the_canonical_wrapper(): void
    {
        $result = app(WordpressContentSanitizer::class)->clean(
            '<img src="/storage/a.jpg" data-id="1"></div><script>alert(1)</script>',
        );

        $this->assertStringNotContainsString('data-id=', $result['html']);
        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('alert(1)', $result['html']);
        $this->assertContains('unsafe_html_removed', $result['warnings']);
        $this->assertContains('manual_review', $result['warnings']);
    }

    public function test_nested_extra_closing_tag_does_not_hide_script_escaping_the_canonical_wrapper(): void
    {
        $result = app(WordpressContentSanitizer::class)->clean(
            '<section><div><img src="/storage/a.jpg" data-id="1"></div></div><script>alert(2)</script></section>',
        );

        $this->assertStringNotContainsString('data-id=', $result['html']);
        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('alert(2)', $result['html']);
        $this->assertContains('unsafe_html_removed', $result['warnings']);
        $this->assertContains('manual_review', $result['warnings']);
    }

    public function test_trusted_iframe_normalization_does_not_discard_a_wrapper_breakout_script(): void
    {
        $html = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe></div><script>window.iframeBreakout = true</script>';

        $normalized = app(WordpressEmbedNormalizer::class)->normalize($html);

        $this->assertSame($html, $normalized['html']);
        $this->assertSame(['unsafe_html_removed'], $normalized['warnings']);
        $this->assertSame([], $normalized['normalization_counts']);

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('iframeBreakout', $result['html']);
        $this->assertContains('unsafe_html_removed', $result['warnings']);
        $this->assertContains('manual_review', $result['warnings']);
        $this->assertNotContains('trusted_iframe_hardened', $result['warnings']);
        $this->assertSame([], $result['normalization_counts']);
    }

    public function test_trusted_provider_loader_normalization_does_not_discard_a_wrapper_breakout_script(): void
    {
        $html = '<blockquote class="instagram-media"><a href="https://www.instagram.com/p/AbCdEf123/">Fallback</a></blockquote><script async src="//www.instagram.com/embed.js"></script></div><script>window.loaderBreakout = true</script>';

        $normalized = app(WordpressEmbedNormalizer::class)->normalize($html);

        $this->assertSame($html, $normalized['html']);
        $this->assertSame(['unsafe_html_removed'], $normalized['warnings']);
        $this->assertSame([], $normalized['normalization_counts']);

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertStringContainsString('Fallback', $result['html']);
        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('loaderBreakout', $result['html']);
        $this->assertContains('unsafe_html_removed', $result['warnings']);
        $this->assertContains('manual_review', $result['warnings']);
        $this->assertNotContains('trusted_embed_script_removed', $result['warnings']);
        $this->assertSame([], $result['normalization_counts']);
    }

    public function test_it_preserves_and_hardens_an_allowlisted_iframe_without_manual_review(): void
    {
        $html = '<figure><iframe class="wp-embed" frameborder="0" src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed" title="Видео" width="560" height="315" allowfullscreen></iframe></figure>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertStringContainsString('<iframe', $result['html']);
        $this->assertStringContainsString(
            'src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed"',
            html_entity_decode($result['html'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
        $this->assertStringContainsString('sandbox="allow-scripts allow-same-origin allow-presentation"', $result['html']);
        $this->assertStringNotContainsString('frameborder=', $result['html']);
        $this->assertSame(['trusted_iframe_hardened'], $result['warnings']);
    }

    public function test_trusted_iframe_with_meaningful_child_markup_is_quarantined_for_review(): void
    {
        $html = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed"><script>alert(1)</script><p>Fallback</p></iframe>';

        $normalized = app(WordpressEmbedNormalizer::class)->normalize($html);

        $this->assertSame('', $normalized['html']);
        $this->assertSame(['unsafe_html_removed', 'untrusted_iframe_removed'], $normalized['warnings']);
        $this->assertSame([], $normalized['normalization_counts']);

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertSame('', $result['html']);
        $this->assertSame([
            'unsafe_html_removed',
            'untrusted_iframe_removed',
            'manual_review',
        ], $result['warnings']);
        $this->assertSame([], $result['normalization_counts']);
    }

    public function test_trusted_iframe_with_only_whitespace_and_comments_remains_trusted(): void
    {
        $html = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed"> <!-- legacy spacer --> </iframe>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertStringContainsString('<iframe', $result['html']);
        $this->assertStringNotContainsString('legacy spacer', $result['html']);
        $this->assertSame(['trusted_iframe_hardened'], $result['warnings']);
        $this->assertSame(['trusted_iframe_hardened' => 1], $result['normalization_counts']);
    }

    public function test_it_removes_unknown_iframes_and_reviews_executable_iframe_attributes(): void
    {
        $html = '<iframe src="https://www.youtube.com.evil.test/embed/dQw4w9WgXcQ?feature=oembed"></iframe><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed" srcdoc="<script>alert(1)</script>" onload="alert(2)"></iframe>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertSame(1, substr_count((string) $result['html'], '<iframe'));
        $this->assertStringNotContainsString('srcdoc=', $result['html']);
        $this->assertStringNotContainsString('onload=', $result['html']);
        $this->assertContains('untrusted_iframe_removed', $result['warnings']);
        $this->assertContains('trusted_iframe_hardened', $result['warnings']);
        $this->assertContains('unsafe_html_removed', $result['warnings']);
        $this->assertContains('manual_review', $result['warnings']);
    }

    public function test_it_removes_exact_trusted_provider_loader_when_a_valid_fallback_exists(): void
    {
        $html = '<blockquote class="instagram-media"><a href="https://www.instagram.com/p/AbCdEf123/">Instagram fallback</a></blockquote><script async src="//www.instagram.com/embed.js"></script>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringContainsString('Instagram fallback', $result['html']);
        $this->assertSame(['trusted_embed_script_removed'], $result['warnings']);
        $this->assertSame(['trusted_embed_script_removed' => 1], $result['normalization_counts']);
    }

    public function test_it_counts_multiple_trusted_loader_elements_in_one_article(): void
    {
        $html = '<blockquote class="instagram-media"><a href="https://www.instagram.com/p/First123/">First</a></blockquote><script async src="//www.instagram.com/embed.js"></script><blockquote class="instagram-media"><a href="https://www.instagram.com/p/Second456/">Second</a></blockquote><script async src="//www.instagram.com/embed.js"></script>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertSame(['trusted_embed_script_removed'], $result['warnings']);
        $this->assertSame(['trusted_embed_script_removed' => 2], $result['normalization_counts']);
    }

    public function test_it_replaces_the_strict_instagram_generated_shell_with_a_canonical_link_fallback(): void
    {
        $result = app(WordpressContentSanitizer::class)->clean($this->instagramGeneratedFallback());

        $this->assertStringContainsString('href="https://www.instagram.com/reel/AbCdEf123/"', $result['html']);
        $this->assertStringContainsString('Погледнете ја објавата на Instagram', $result['html']);
        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringNotContainsString('<svg', $result['html']);
        $this->assertStringNotContainsString('data-instgrm-', $result['html']);
        $this->assertSame([
            'trusted_embed_script_removed',
            'trusted_instagram_fallback_normalized',
        ], $result['warnings']);
        $this->assertSame([
            'trusted_embed_script_removed' => 1,
            'trusted_instagram_fallback_normalized' => 1,
        ], $result['normalization_counts']);
    }

    public function test_instagram_near_matches_with_mismatched_ids_or_extra_executable_markup_still_require_review(): void
    {
        $payloads = [
            str_replace(
                'data-instgrm-permalink="https://www.instagram.com/reel/AbCdEf123/?utm_source=ig_embed&amp;utm_campaign=loading"',
                'data-instgrm-permalink="https://www.instagram.com/reel/Different456/?utm_source=ig_embed&amp;utm_campaign=loading"',
                $this->instagramGeneratedFallback(),
            ),
            str_replace('<svg ', '<svg onload="alert(1)" ', $this->instagramGeneratedFallback()),
            str_replace('data-instgrm-version="14"', 'data-instgrm-version="14" data-owner="editorial"', $this->instagramGeneratedFallback()),
        ];

        foreach ($payloads as $payload) {
            $result = app(WordpressContentSanitizer::class)->clean($payload);

            $this->assertStringNotContainsString('<script', $result['html']);
            $this->assertStringNotContainsString('onload=', $result['html']);
            $this->assertContains('unsafe_html_removed', $result['warnings']);
            $this->assertContains('manual_review', $result['warnings']);
            $this->assertNotContains('trusted_instagram_fallback_normalized', $result['warnings']);
            $this->assertArrayNotHasKey('trusted_instagram_fallback_normalized', $result['normalization_counts']);
        }
    }

    public function test_it_removes_only_validated_twitter_and_tiktok_legacy_provider_attributes(): void
    {
        $twitter = '<blockquote class="twitter-tweet" data-dnt="true" data-width="500"><p>Tweet</p><a href="https://twitter.com/example/status/123456789">June 1, 2026</a></blockquote><script async charset="utf-8" src="https://platform.twitter.com/widgets.js"></script>';
        $tiktok = '<blockquote class="tiktok-embed" cite="https://www.tiktok.com/@example/video/7376100030559587627" data-video-id="7376100030559587627" data-embed-from="oembed" style="max-width:605px"><section><a target="_blank" title="@example" href="https://www.tiktok.com/@example?refer=embed" rel="noopener">@example</a></section></blockquote><script async src="https://www.tiktok.com/embed.js"></script>';

        $twitterResult = app(WordpressContentSanitizer::class)->clean($twitter);
        $tiktokResult = app(WordpressContentSanitizer::class)->clean($tiktok);

        $this->assertStringNotContainsString('data-dnt=', $twitterResult['html']);
        $this->assertStringNotContainsString('data-width=', $twitterResult['html']);
        $this->assertSame([
            'trusted_embed_script_removed',
            'trusted_twitter_fallback_normalized',
        ], $twitterResult['warnings']);
        $this->assertSame([
            'trusted_embed_script_removed' => 1,
            'trusted_twitter_fallback_normalized' => 1,
        ], $twitterResult['normalization_counts']);

        $this->assertStringNotContainsString('data-video-id=', $tiktokResult['html']);
        $this->assertStringNotContainsString('data-embed-from=', $tiktokResult['html']);
        $this->assertSame([
            'trusted_embed_script_removed',
            'trusted_tiktok_fallback_normalized',
        ], $tiktokResult['warnings']);
        $this->assertSame([
            'trusted_embed_script_removed' => 1,
            'trusted_tiktok_fallback_normalized' => 1,
        ], $tiktokResult['normalization_counts']);
    }

    public function test_provider_attribute_near_matches_do_not_mask_unknown_data_or_event_attributes(): void
    {
        $payloads = [
            '<blockquote class="twitter-tweet" data-width="500px"><a href="https://twitter.com/example/status/123456789">Tweet</a></blockquote><script async charset="utf-8" src="https://platform.twitter.com/widgets.js"></script>',
            '<blockquote class="twitter-tweet" data-width="500" data-owner="editorial"><a href="https://twitter.com/example/status/123456789">Tweet</a></blockquote><script async charset="utf-8" src="https://platform.twitter.com/widgets.js"></script>',
            '<blockquote class="tiktok-embed" cite="https://www.tiktok.com/@example/video/123" data-video-id="456"><a href="https://www.tiktok.com/@example/video/123">TikTok</a></blockquote><script async src="https://www.tiktok.com/embed.js"></script>',
            '<blockquote class="tiktok-embed" cite="https://www.tiktok.com/@example/video/123" data-video-id="123" onclick="alert(1)"><a href="https://www.tiktok.com/@example/video/123">TikTok</a></blockquote><script async src="https://www.tiktok.com/embed.js"></script>',
        ];

        foreach ($payloads as $payload) {
            $result = app(WordpressContentSanitizer::class)->clean($payload);

            $this->assertStringNotContainsString('onclick=', $result['html']);
            $this->assertContains('unsafe_html_removed', $result['warnings']);
            $this->assertContains('manual_review', $result['warnings']);
        }
    }

    public function test_it_converts_an_exact_wordpress_lightbox_gallery_to_static_images(): void
    {
        $result = app(WordpressContentSanitizer::class)->clean($this->wordpressLightboxGallery());

        $this->assertStringContainsString('src="/storage/gallery.jpg"', $result['html']);
        $this->assertStringNotContainsString('<button', $result['html']);
        $this->assertStringNotContainsString('<svg', $result['html']);
        $this->assertStringNotContainsString('data-wp-', $result['html']);
        $this->assertSame(['trusted_wordpress_interactive_normalized'], $result['warnings']);
        $this->assertSame(
            ['trusted_wordpress_interactive_normalized' => 2],
            $result['normalization_counts'],
        );
    }

    public function test_wordpress_lightbox_near_match_with_an_event_attribute_remains_review_blocking(): void
    {
        $html = str_replace('src="/storage/gallery.jpg"', 'src="/storage/gallery.jpg" onerror="alert(1)"', $this->wordpressLightboxGallery());

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertStringNotContainsString('onerror=', $result['html']);
        $this->assertContains('unsafe_html_removed', $result['warnings']);
        $this->assertContains('manual_review', $result['warnings']);
        $this->assertNotContains('trusted_wordpress_interactive_normalized', $result['warnings']);
        $this->assertArrayNotHasKey('trusted_wordpress_interactive_normalized', $result['normalization_counts']);
    }

    public function test_it_removes_a_wordpress_oembed_secret_only_when_the_fallback_and_iframe_match(): void
    {
        $link = 'https://www.krstarica.com/zivot/ziveti-zivot/da-li-ste-culi-za-eustres-da-postoji-i-moze-vam-biti-od-koristi/';
        $html = '<blockquote class="wp-embedded-content" data-secret="AbCdEf1234"><a href="'.$link.'">Fallback</a></blockquote><iframe class="wp-embedded-content" data-secret="AbCdEf1234" src="'.$link.'embed/#?secret=AbCdEf1234" width="500" height="282"></iframe>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertStringContainsString('Fallback', $result['html']);
        $this->assertStringNotContainsString('data-secret=', $result['html']);
        $this->assertContains('trusted_wordpress_oembed_normalized', $result['warnings']);
        $this->assertContains('trusted_iframe_hardened', $result['warnings']);
        $this->assertNotContains('unsafe_html_removed', $result['warnings']);
        $this->assertNotContains('manual_review', $result['warnings']);
        $this->assertSame(1, $result['normalization_counts']['trusted_wordpress_oembed_normalized']);
        $this->assertSame(1, $result['normalization_counts']['trusted_iframe_hardened']);
    }

    public function test_wordpress_oembed_secret_mismatch_remains_review_blocking(): void
    {
        $link = 'https://www.krstarica.com/zivot/ziveti-zivot/da-li-ste-culi-za-eustres-da-postoji-i-moze-vam-biti-od-koristi/';
        $html = '<blockquote class="wp-embedded-content" data-secret="AbCdEf1234"><a href="'.$link.'">Fallback</a></blockquote><iframe class="wp-embedded-content" data-secret="Different1" src="'.$link.'embed/#?secret=Different1" width="500" height="282"></iframe>';

        $result = app(WordpressContentSanitizer::class)->clean($html);

        $this->assertContains('unsafe_html_removed', $result['warnings']);
        $this->assertContains('manual_review', $result['warnings']);
        $this->assertNotContains('trusted_wordpress_oembed_normalized', $result['warnings']);
        $this->assertArrayNotHasKey('trusted_wordpress_oembed_normalized', $result['normalization_counts']);
    }

    public function test_provider_loader_spoofs_missing_fallback_inline_content_and_malformed_tags_require_review(): void
    {
        $payloads = [
            '<blockquote class="instagram-media"><a href="https://www.instagram.com/p/AbCdEf123/">Fallback</a></blockquote><script async src="https://attacker.example/embed.js"></script>',
            '<script async src="//www.instagram.com/embed.js"></script>',
            '<blockquote class="twitter-tweet"><a href="https://twitter.com/user/status/123">Fallback</a></blockquote><script async src="https://platform.twitter.com/widgets.js">alert(1)</script>',
            '<blockquote class="tiktok-embed" cite="https://www.tiktok.com/@user/video/123"><a href="https://www.tiktok.com/@user/video/123">Fallback</a></blockquote><script async src="https://www.tiktok.com/embed.js"><script src="/broken-instagram.js"></script>',
        ];

        foreach ($payloads as $payload) {
            $result = app(WordpressContentSanitizer::class)->clean($payload);

            $this->assertStringNotContainsString('<script', $result['html']);
            $this->assertContains('unsafe_html_removed', $result['warnings']);
            $this->assertContains('manual_review', $result['warnings']);
            $this->assertNotContains('trusted_embed_script_removed', $result['warnings']);
        }
    }

    public function test_iframe_provider_policy_validates_exact_hosts_routes_queries_and_nested_urls(): void
    {
        $policy = app(WordpressIframeUrlPolicy::class);

        $allowed = [
            'https://assets.pinterest.com/ext/embed.html?id=12345678901',
            'https://assets.pinterest.com/ext/embed.html?id=AcpWNwPXkK4hXTZriq2XMfYROehU1Tt300CeU82H3bmethht7N91C-8',
            'https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed&start=10',
            'https://www.youtube.com/embed/dQw4w9WgXcQ?list=PLjOJ1FMZ1tOX1eRhERFENQQ2nGSqdkez1',
            'https://www.facebook.com/plugins/post.php?href=https%3A%2F%2Fwww.facebook.com%2Fuser%2Fposts%2F123&show_text=true&width=500',
            'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Fplaylists%2F123&auto_play=false&color=%23ff5500',
            'https://embed.traxsource.com/player/title/123',
            'https://www.krstarica.com/zivot/ziveti-zivot/da-li-ste-culi-za-eustres-da-postoji-i-moze-vam-biti-od-koristi/embed/#legacy-fragment',
        ];

        foreach ($allowed as $url) {
            $this->assertNotNull($policy->normalize($url), $url);
        }

        $this->assertStringNotContainsString('#', (string) $policy->normalize($allowed[6]));

        $rejected = [
            'https://www.youtube.com.evil.test/embed/dQw4w9WgXcQ?feature=oembed',
            'https://attacker@www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed',
            'https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed&autoplay=1',
            'https://www.facebook.com/plugins/post.php?href=https%3A%2F%2Fevil.test%2Fuser%2Fposts%2F123',
            'https://w.soundcloud.com/player/?url=https%3A%2F%2Fevil.test%2Fplaylists%2F123',
            'https://www.krstarica.com/other/embed/',
            'data:text/html,<script>alert(1)</script>',
        ];

        foreach ($rejected as $url) {
            $this->assertNull($policy->normalize($url), $url);
        }
    }

    public function test_media_download_rejects_executable_payload_with_spoofed_image_header(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://old.example.test/wp-content/uploads/payload.php' => Http::response('<?php echo "owned"; ?>', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $sanitizer = app(WordpressContentSanitizer::class);
        $asset = $sanitizer->downloadMedia('https://old.example.test/wp-content/uploads/payload.php');

        $this->assertNull($asset);
        $this->assertSame('Source media is not a supported raster image.', $sanitizer->lastDownloadError());
        $this->assertSame(0, MediaAsset::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_media_download_uses_detected_raster_mime_and_extension_only(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://old.example.test/wp-content/uploads/photo.php' => Http::response($this->transparentPng(), 200, [
                'Content-Type' => 'application/octet-stream',
            ]),
        ]);

        $asset = app(WordpressContentSanitizer::class)
            ->downloadMedia('https://old.example.test/wp-content/uploads/photo.php');

        $this->assertNotNull($asset);
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertStringEndsWith('.png', $asset->path);
        $this->assertStringNotContainsString('.php', $asset->path);
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_media_download_rejects_private_and_link_local_sources_before_request(): void
    {
        config(['services.wordpress.media_hosts' => ['127.0.0.1', '169.254.169.254']]);
        Http::fake();

        $sanitizer = app(WordpressContentSanitizer::class);

        $this->assertNull($sanitizer->downloadMedia('http://127.0.0.1/private.jpg'));
        $this->assertNull($sanitizer->downloadMedia('http://169.254.169.254/latest/meta-data.jpg'));
        Http::assertNothingSent();
    }

    public function test_media_download_rejects_redirects_and_chunked_oversized_bodies(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://old.example.test/wp-content/uploads/redirect.jpg' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data',
            ]),
            'https://old.example.test/wp-content/uploads/chunked.jpg' => Http::response(str_repeat('A', 8_000_001), 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $sanitizer = app(WordpressContentSanitizer::class);

        $this->assertNull($sanitizer->downloadMedia('https://old.example.test/wp-content/uploads/redirect.jpg'));
        $this->assertSame('Source media redirects are not allowed.', $sanitizer->lastDownloadError());
        $this->assertNull($sanitizer->downloadMedia('https://old.example.test/wp-content/uploads/chunked.jpg'));
        $this->assertStringContainsString('Source media is too large', (string) $sanitizer->lastDownloadError());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_media_request_pins_validated_dns_and_disables_response_decoding(): void
    {
        $method = new \ReflectionMethod(WordpressContentSanitizer::class, 'mediaRequestOptions');
        $options = $method->invoke(app(WordpressContentSanitizer::class), [
            'host' => 'old.example.test',
            'port' => 443,
            'address' => '8.8.8.8',
        ]);

        $this->assertFalse($options['allow_redirects']);
        $this->assertFalse($options['decode_content']);
        $this->assertSame(['old.example.test:443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertIsCallable($options['on_headers']);
        $this->assertIsCallable($options['progress']);
    }

    public function test_media_download_requests_identity_encoding_and_rejects_compressed_responses(): void
    {
        Http::fake([
            'https://old.example.test/wp-content/uploads/compressed.png' => Http::response($this->transparentPng(), 200, [
                'Content-Type' => 'image/png',
                'Content-Encoding' => 'gzip',
            ]),
        ]);

        $sanitizer = app(WordpressContentSanitizer::class);

        $this->assertNull($sanitizer->downloadMedia('https://old.example.test/wp-content/uploads/compressed.png'));
        $this->assertSame('Compressed source media responses are not allowed.', $sanitizer->lastDownloadError());
        Http::assertSent(fn ($request): bool => $request->hasHeader('Accept-Encoding', 'identity'));
    }

    public function test_media_download_reuses_cached_asset_before_host_or_network_validation(): void
    {
        config([
            'services.wordpress.base_url' => null,
            'services.wordpress.media_hosts' => [],
        ]);
        Storage::fake('public');
        Http::fake();
        $sourceUrl = 'https://retired-unapproved-host.example/wp-content/uploads/photo.jpg';
        $existing = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'wordpress/cached-photo.jpg',
            'url' => '/storage/wordpress/cached-photo.jpg',
            'metadata' => ['source_url' => $sourceUrl],
        ]);
        Storage::disk('public')->put($existing->path, 'cached');

        $sanitizer = app(WordpressContentSanitizer::class);
        $sanitizer->setMediaDownloadsEnabled(false);
        $asset = $sanitizer->downloadMedia($sourceUrl);
        $cleaned = $sanitizer->clean('<p><img src="'.$sourceUrl.'"></p>');

        $this->assertTrue($existing->is($asset));
        $this->assertSame([], $cleaned['warnings']);
        $this->assertStringContainsString('/storage/wordpress/cached-photo.jpg', $cleaned['html']);
        Http::assertNothingSent();
    }

    public function test_missing_cached_file_is_securely_redownloaded_into_the_existing_asset(): void
    {
        Storage::fake('public');
        $sourceUrl = 'https://old.example.test/wp-content/uploads/missing-cached.png';
        $existing = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'wordpress/missing-cached.png',
            'metadata' => ['source_url' => $sourceUrl],
        ]);
        Http::fake([
            $sourceUrl => Http::response($this->transparentPng(), 200, ['Content-Type' => 'image/png']),
        ]);

        $asset = app(WordpressContentSanitizer::class)->downloadMedia($sourceUrl);

        $this->assertNotNull($asset);
        $this->assertSame($existing->id, $asset->id);
        $this->assertSame('image/png', $asset->mime_type);
        Storage::disk('public')->assertExists($asset->path);
        Http::assertSentCount(1);
    }

    public function test_content_preview_preserves_inline_wordpress_media_without_false_review_warning(): void
    {
        Http::fake();
        $html = '<p><img src="https://old.example.test/wp-content/uploads/photo.jpg"></p><blockquote class="instagram-media"><a href="https://www.instagram.com/p/AbCdEf123/">Fallback</a></blockquote><script async src="//www.instagram.com/embed.js"></script>';

        $preview = app(WordpressContentSanitizer::class)->preview($html);

        $this->assertStringContainsString('https://old.example.test/wp-content/uploads/photo.jpg', $preview['html']);
        $this->assertSame(['trusted_embed_script_removed'], $preview['warnings']);
        $this->assertSame(['trusted_embed_script_removed' => 1], $preview['normalization_counts']);
        Http::assertNothingSent();
    }

    public function test_trusted_unavailable_inline_media_is_removed_identically_in_preview_and_clean(): void
    {
        Http::fake();
        $sourceUrl = 'https://old.example.test/wp-content/uploads/2023/04/missing.jpg';
        $html = '<figure><a href="'.$sourceUrl.'"><img src="'.$sourceUrl.'" alt="Missing" onerror="alert(1)"></a><figcaption>Зачуван <strong>опис</strong>.</figcaption></figure>'
            .'<p><img src="'.$sourceUrl.'"></p>'
            .'<p>Пред сликата <img loading="lazy" src="'.$sourceUrl.'"> по сликата.</p>';
        $evidence = [[
            'source_url' => $sourceUrl,
            'occurrences' => 3,
            'role' => 'inline',
            'treatment' => 'remove_element',
        ]];
        $sanitizer = app(WordpressContentSanitizer::class);

        $preview = $sanitizer->preview($html, trustedUnavailableInlineMedia: $evidence);
        $clean = $sanitizer->clean($html, trustedUnavailableInlineMedia: $evidence);

        $this->assertSame($preview, $clean);
        $this->assertStringNotContainsString('<img', (string) $clean['html']);
        $this->assertStringNotContainsString($sourceUrl, (string) $clean['html']);
        $this->assertStringNotContainsString('<p></p>', (string) $clean['html']);
        $this->assertStringContainsString(
            '<figcaption>Зачуван <strong>опис</strong>.</figcaption>',
            (string) $clean['html'],
        );
        $this->assertStringContainsString('Пред сликата', (string) $clean['html']);
        $this->assertStringContainsString('по сликата.', (string) $clean['html']);
        $this->assertSame(['trusted_unavailable_inline_media'], $clean['warnings']);
        $this->assertSame(
            ['trusted_unavailable_inline_media' => 3],
            $clean['normalization_counts'],
        );
        $this->assertNotContains('manual_review', $clean['warnings']);
        Http::assertNothingSent();
    }

    public function test_one_unavailable_inline_media_mismatch_rejects_all_listed_treatments(): void
    {
        $sourceUrl = 'https://old.example.test/wp-content/uploads/2023/04/still-missing.jpg';
        $exactUrl = 'https://old.example.test/wp-content/uploads/2023/04/exact-but-untrusted.jpg';
        Http::fake([
            $sourceUrl => Http::response('', 404),
            $exactUrl => Http::response('', 404),
        ]);
        $sanitizer = app(WordpressContentSanitizer::class);
        $sanitizer->setMediaSourceBase('https://old.example.test');

        $result = $sanitizer->clean(
            '<figure><img src="'.$sourceUrl.'"><figcaption>Опис.</figcaption></figure>'
                .'<p><img src="'.$exactUrl.'">Зачуван текст.</p>',
            trustedUnavailableInlineMedia: [[
                'source_url' => $sourceUrl,
                'occurrences' => 2,
                'role' => 'inline',
                'treatment' => 'remove_element',
            ], [
                'source_url' => $exactUrl,
                'occurrences' => 1,
                'role' => 'inline',
                'treatment' => 'remove_element',
            ]],
        );

        $this->assertSame(2, substr_count((string) $result['html'], '<img'));
        $this->assertStringContainsString($sourceUrl, (string) $result['html']);
        $this->assertStringContainsString($exactUrl, (string) $result['html']);
        $this->assertStringContainsString('<figcaption>Опис.</figcaption>', (string) $result['html']);
        $this->assertStringContainsString('Зачуван текст.', (string) $result['html']);
        $this->assertContains('inline_image_download_failed', $result['warnings']);
        $this->assertContains('manual_review', $result['warnings']);
        $this->assertNotContains('trusted_unavailable_inline_media', $result['warnings']);
        $this->assertSame([], $result['normalization_counts']);
        Http::assertSentCount(2);
    }

    public function test_media_download_rejects_excessive_raster_dimensions_before_persistence(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://old.example.test/wp-content/uploads/wide.gif' => Http::response($this->gifHeader(12_001, 1), 200),
            'https://old.example.test/wp-content/uploads/pixels.gif' => Http::response($this->gifHeader(8_000, 6_000), 200),
        ]);
        $sanitizer = app(WordpressContentSanitizer::class);

        $this->assertNull($sanitizer->downloadMedia('https://old.example.test/wp-content/uploads/wide.gif'));
        $this->assertSame(
            'Source media dimensions exceed limits (12001x1; maximum side 12000 and maximum pixels 40000000).',
            $sanitizer->lastDownloadError(),
        );
        $this->assertNull($sanitizer->downloadMedia('https://old.example.test/wp-content/uploads/pixels.gif'));
        $this->assertSame(
            'Source media dimensions exceed limits (8000x6000; maximum side 12000 and maximum pixels 40000000).',
            $sanitizer->lastDownloadError(),
        );
        $this->assertSame(0, MediaAsset::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_preview_normalizes_wordpress_video_shortcode_block_wrapped_by_collection_paragraph(): void
    {
        $html = '<p><div class="wp-video" style="width: 568px;"><video class="wp-video-shortcode" id="video-1383-1" width="568" height="320" preload="metadata" controls="controls"><source type="video/mp4" src="https://old.example.test/wp-content/uploads/video.mp4?_=1" /><a href="https://old.example.test/wp-content/uploads/video.mp4">Video fallback</a></video></div></p>';

        $preview = app(WordpressContentSanitizer::class)->preview($html);

        $this->assertSame(['trusted_wordpress_media_wrapper_normalized'], $preview['warnings']);
        $this->assertSame(
            ['trusted_wordpress_media_wrapper_normalized' => 1],
            $preview['normalization_counts'],
        );
        $this->assertStringNotContainsString('<p><div', (string) $preview['html']);
        $this->assertStringContainsString('<video', (string) $preview['html']);
        $this->assertStringContainsString('Video fallback', (string) $preview['html']);
    }

    private function instagramGeneratedFallback(): string
    {
        return <<<'HTML'
<blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/reel/AbCdEf123/?utm_source=ig_embed&amp;utm_campaign=loading" data-instgrm-version="14" style="max-width:540px">
  <div style="padding:16px">
    <a href="https://www.instagram.com/reel/AbCdEf123/?utm_source=ig_embed&amp;utm_campaign=loading" rel="noopener" style="display:block" target="_blank">
      <div style="height:50px">
        <svg height="50px" version="1.1" viewBox="0 0 60 60" width="50px" xmlns="https://www.w3.org/2000/svg" xmlns:xlink="https://www.w3.org/1999/xlink">
          <g fill="#fff" fill-rule="evenodd" stroke="none" stroke-width="1"></g>
          <g fill="#000" transform="translate(0 0)"></g>
          <g><path d="M0 0h10v10z"></path></g>
        </svg>
      </div>
    </a>
    <p style="text-align:center"><a href="https://www.instagram.com/reel/AbCdEf123/?utm_source=ig_embed&amp;utm_campaign=loading" style="color:#000" target="_blank">A post shared on Instagram</a></p>
  </div>
</blockquote>
<script async src="//www.instagram.com/embed.js"></script>
HTML;
    }

    private function wordpressLightboxGallery(): string
    {
        return <<<'HTML'
<figure data-wp-context="{&quot;galleryId&quot;:&quot;6a57d0abcff06&quot;}" data-wp-interactive="core/gallery" class="wp-block-gallery has-nested-images columns-default is-cropped wp-block-gallery-2 is-layout-flex wp-block-gallery-is-layout-flex">
  <figure data-wp-context="{&quot;imageId&quot;:&quot;6a57d0abd013e&quot;}" data-wp-interactive="core/image" data-wp-key="6a57d0abd013e" class="wp-block-image size-large wp-lightbox-container">
    <img src="/storage/gallery.jpg" alt="" width="1024" height="683" decoding="async" loading="lazy" data-id="35256" data-wp-class--hide="state.isContentHidden" data-wp-class--show="state.isContentVisible" data-wp-init="callbacks.setButtonStyles" data-wp-on--click="actions.showLightbox" data-wp-on--load="callbacks.setButtonStyles" data-wp-on--pointerdown="actions.preloadImage" data-wp-on--pointerenter="actions.preloadImageWithDelay" data-wp-on--pointerleave="actions.cancelPreload" data-wp-on-window--resize="callbacks.setButtonStyles">
    <button aria-haspopup="dialog" class="lightbox-trigger" data-wp-bind--aria-label="state.thisImage.triggerButtonAriaLabel" data-wp-init="callbacks.initTriggerButton" data-wp-on--click="actions.showLightbox" data-wp-style--right="state.thisImage.buttonRight" data-wp-style--top="state.thisImage.buttonTop" type="button">
      <svg fill="none" height="12" viewBox="0 0 12 12" width="12" xmlns="http://www.w3.org/2000/svg">
        <path d="M2 0a2 2 0 0 0-2 2v2h1.5V2a.5.5 0 0 1 .5-.5h2V0H2Zm2 10.5H2a.5.5 0 0 1-.5-.5V8H0v2a2 2 0 0 0 2 2h2v-1.5ZM8 12v-1.5h2a.5.5 0 0 0 .5-.5V8H12v2a2 2 0 0 1-2 2H8Zm2-12a2 2 0 0 1 2 2v2h-1.5V2a.5.5 0 0 0-.5-.5H8V0h2Z" fill="#fff"></path>
      </svg>
    </button>
  </figure>
</figure>
HTML;
    }

    private function transparentPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAF/gL+Xjz1WQAAAABJRU5ErkJggg==', true) ?: '';
    }

    private function gifHeader(int $width, int $height): string
    {
        return 'GIF89a'.pack('v', $width).pack('v', $height)."\x80\x00\x00\x00\x00\x00\xff\xff\xff\x2c\x00\x00\x00\x00";
    }
}
