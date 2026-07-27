import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const root = new URL("../../../", import.meta.url);
const read = (path) => readFileSync(new URL(path, root), "utf8");
const policy = read("src/app/politika-za-privatnost/page.tsx");
const analytics = read("src/components/GoogleAnalytics.tsx");
const preferences = read("src/components/privacy/PrivacyPreferences.tsx");
const newsletter = read("src/components/newsletter/NewsletterSignup.tsx");
const booking = read("src/components/ads/AdBookingForm.tsx");
const article = read("src/components/ArticleHtmlChunk.tsx");

test("privacy policy renders verified controller and DPO details without placeholders", () => {
  assert.ok(policy.includes("Здружение за културно информирање РАЗБУДИ СЕ ДЕНЕС Битола"));
  assert.ok(policy.includes("БОШКО НАЈДОВСКИ 1Б, 7000 Битола"));
  assert.ok(policy.includes("Александар Димовски"));
  assert.ok(policy.includes("info@razbudise.mk"));
  assert.ok(!/TODO|TBD|placeholder|да се внесе/i.test(policy));
});

test("policy publishes only confirmed hosting facts and avoids an unverified retention table", () => {
  assert.ok(policy.includes("Hetzner Cloud"));
  assert.ok(policy.includes("Германија"));
  assert.ok(!policy.includes("<table"));
  assert.ok(policy.includes("не објавуваме фиксен рок"));
});

test("forms link concise notices to the privacy policy", () => {
  for (const source of [newsletter, booking]) {
    assert.ok(source.includes("/politika-za-privatnost"));
    assert.ok(source.includes("form-privacy-notice"));
  }
});

test("non-essential analytics and external frames require stored consent", () => {
  assert.ok(analytics.includes("readPrivacyConsent"));
  assert.ok(analytics.includes("if (!enabled) return null"));
  assert.ok(analytics.includes("ga-disable-"));
  assert.ok(analytics.includes("Max-Age=0"));
  assert.ok(preferences.includes("Само неопходни"));
  assert.ok(preferences.includes("Поставки за приватност"));
  assert.ok(article.includes("data-privacy-src"));
  assert.ok(article.includes("externalMedia"));
});
