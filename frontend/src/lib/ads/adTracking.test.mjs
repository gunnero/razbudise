import assert from "node:assert/strict";
import test from "node:test";

import {
  adClickHref,
  adCreativeDocument,
  adCreativeDimensions,
  adCreativeSandbox,
  adCreativeStyleVars,
  adDemoPresentation,
  adSlotState,
  impressionRequest,
  safelyLoadAdPlacement,
  trackingUrl,
} from "./adTracking.mjs";

test("ad creative sandbox allows scripts only for script creatives", () => {
  assert.equal(adCreativeSandbox("script"), "allow-scripts");
  assert.equal(adCreativeSandbox("html"), "");
  assert.equal(adCreativeSandbox("native"), "");
  assert.equal(adCreativeSandbox("image"), "");
  assert.equal(adCreativeSandbox("script").includes("allow-same-origin"), false);
});

test("ad creative document keeps advertiser markup inside a self-contained frame document", () => {
  const document = adCreativeDocument('<div class="ad-house-creative">Локална реклама</div>');

  assert.match(document, /^<!doctype html>/);
  assert.match(document, /<meta name="viewport"/);
  assert.match(document, /\.ad-house-creative/);
  assert.match(document, /Локална реклама/);
});

test("ad placement failures collapse to an empty slot instead of rejecting the page", async () => {
  const placement = { id: 42 };

  assert.equal(await safelyLoadAdPlacement(async () => placement), placement);
  assert.equal(
    await safelyLoadAdPlacement(async () => {
      throw new Error("ad service unavailable");
    }),
    null,
  );
});

test("adClickHref prefers backend click tracking URL over direct advertiser URL", () => {
  const placement = {
    click_url: "https://advertiser.example.test/direct",
    click_tracking_url: "https://api.example.test/api/ads/click/12",
  };

  assert.equal(adClickHref(placement), "https://api.example.test/api/ads/click/12");
});

test("trackingUrl resolves relative tracking paths against the public API base URL", () => {
  assert.equal(
    trackingUrl("https://api.example.test/api", "/ads/click/12"),
    "https://api.example.test/api/ads/click/12",
  );
});

test("impressionRequest uses a no-store JSON POST payload", () => {
  const [url, init] = impressionRequest("https://api.example.test/api", {
    creativeId: 12,
    deliveryToken: "signed-delivery-token",
    zoneKey: "author_page_top",
    device: "desktop",
    impressionTrackingUrl: "https://tracking.example.test/api/ads/impression",
  });

  assert.equal(url, "https://tracking.example.test/api/ads/impression");
  assert.equal(init.method, "POST");
  assert.equal(init.cache, "no-store");
  assert.deepEqual(init.headers, {
    Accept: "application/json",
    "Content-Type": "application/json",
  });
  assert.equal(
    init.body,
    JSON.stringify({
      delivery_token: "signed-delivery-token",
      creative_id: 12,
      zone_key: "author_page_top",
      device: "desktop",
    }),
  );
});

test("adSlotState hides empty zones and labels fallback creatives", () => {
  assert.deepEqual(adSlotState(null), {
    kind: "hidden",
    label: null,
  });

  assert.deepEqual(adSlotState({ is_fallback: true, name: "Fallback banner" }), {
    kind: "fallback",
    label: "Реклама",
  });

  assert.deepEqual(adSlotState({ is_fallback: false, name: "Paid campaign" }), {
    kind: "campaign",
    label: "Реклама",
  });
});

test("adDemoPresentation describes demo slots with dimensions and Macedonian denar pricing", () => {
  const billboard = {
    height: 250,
    name: "Local demo billboard",
    width: 970,
  };
  const sidebar = {
    height: 600,
    name: "Sidebar demo",
    width: 300,
  };

  assert.equal(adCreativeDimensions(billboard), "970x250");
  assert.equal(adCreativeDimensions({ height: null, width: 300 }), null);

  assert.deepEqual(adDemoPresentation(billboard, "home_top_billboard"), {
    cta: "Резервирај простор",
    dimensions: "970x250",
    rateLabel: "од 18.000 ден. / недела",
    zoneName: "Почетна горен билборд",
  });

  assert.equal(adDemoPresentation(sidebar, "article_sidebar_sticky").rateLabel, "од 8.500 ден. / недела");
  assert.match(adDemoPresentation(sidebar, "article_sidebar_sticky").rateLabel, /ден\./);
  assert.equal(adDemoPresentation(sidebar, "home_in_feed_3").zoneName, "Почетна во фид 3");
  assert.equal(adDemoPresentation(sidebar, "home_in_feed_1_slot_4").zoneName, "Почетна во фид 1 - слот 4");
  assert.equal(adDemoPresentation(sidebar, "category_in_feed_slot_4").rateLabel, "од 6.000 ден. / недела");
});

test("adCreativeStyleVars fixes the visual slot to the declared creative dimensions", () => {
  assert.deepEqual(adCreativeStyleVars({ height: 250, width: 970 }), {
    "--ad-aspect-ratio": "970 / 250",
    "--ad-height": "250px",
    "--ad-width": "970px",
  });

  assert.deepEqual(adCreativeStyleVars({ height: null, width: 300 }), {});
  assert.deepEqual(adCreativeStyleVars(null), {});
});
