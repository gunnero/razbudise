<?php

namespace Tests\Support;

final class WordpressLegacyEmbedFixtures
{
    public static function allTrustedNormalizations(): string
    {
        $krstaricaLink = 'https://www.krstarica.com/zivot/ziveti-zivot/da-li-ste-culi-za-eustres-da-postoji-i-moze-vam-biti-od-koristi/';

        return self::instagramGeneratedFallback()
            .'<blockquote class="twitter-tweet" data-dnt="true" data-width="500"><p>Tweet</p><a href="https://twitter.com/example/status/123456789">June 1, 2026</a></blockquote><script async charset="utf-8" src="https://platform.twitter.com/widgets.js"></script>'
            .'<blockquote class="tiktok-embed" cite="https://www.tiktok.com/@example/video/7376100030559587627" data-video-id="7376100030559587627" data-embed-from="oembed" style="max-width:605px"><section><a target="_blank" title="@example" href="https://www.tiktok.com/@example?refer=embed" rel="noopener">@example</a></section></blockquote><script async src="https://www.tiktok.com/embed.js"></script>'
            .self::wordpressLightboxGallery()
            .'<blockquote class="wp-embedded-content" data-secret="AbCdEf1234"><a href="'.$krstaricaLink.'">Fallback</a></blockquote><iframe class="wp-embedded-content" data-secret="AbCdEf1234" src="'.$krstaricaLink.'embed/#?secret=AbCdEf1234" width="500" height="282"></iframe>';
    }

    private static function instagramGeneratedFallback(): string
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

    private static function wordpressLightboxGallery(): string
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
}
