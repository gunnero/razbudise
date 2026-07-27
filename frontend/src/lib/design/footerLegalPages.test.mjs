import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import test from "node:test";

const frontendRoot = new URL("../../../", import.meta.url);

function read(path) {
  return readFileSync(new URL(path, frontendRoot), "utf8");
}

const footer = read("src/components/SiteFooter.tsx");
const legalPage = read("src/components/legal/LegalPage.tsx");
const navigation = read("src/lib/navigation/siteNavigation.mjs");
const productionNavigation = read("src/lib/navigation/siteNavigation.ts");
const nextConfig = read("next.config.ts");
const advertisingPriceListPage = read("src/app/cenovnik-za-reklamiranje/page.tsx");

test("footer exposes social links and copyright", () => {
  assert.ok(footer.includes("https://www.facebook.com/profile.php?id=100093113592171"), "footer should link to Facebook");
  assert.ok(footer.includes("https://www.instagram.com/razbudise2023/"), "footer should link to Instagram");
  assert.ok(footer.includes("https://www.youtube.com/@pomelotv.official"), "footer should link to the Pomelo TV YouTube channel");
  assert.ok(footer.includes("/brand/pomelo-tv.webp"), "footer should render the Pomelo TV channel image");
  assert.ok(footer.includes("footer-youtube-channel"), "footer should expose the YouTube channel image as a distinct block");
  assert.ok(existsSync(new URL("public/brand/pomelo-tv.webp", frontendRoot)), "Pomelo TV footer image should exist in public assets");
  assert.ok(footer.includes("© 2026 Разбуди се"), "footer should render compact copyright copy");
  assert.ok(footer.includes("footer-social-links"), "footer should group social links");
  assert.ok(footer.includes("<SocialIcon"), "footer should render vector social icons");
  assert.ok(!footer.includes(">Facebook<") && !footer.includes(">Instagram<") && !footer.includes(">YouTube<"), "social controls should be icon-only");
  assert.ok(footer.includes("footer-legal"), "footer should render a legal/copyright row");
});

test("footer keeps a compact three-zone hierarchy", () => {
  assert.ok(footer.includes("primaryFooterHrefs"), "footer should define a restrained primary navigation set");
  assert.match(footer, /primaryFooterHrefs = \[[^\]]*"\/"[^\]]*"\/za-nas"[^\]]*"\/kontakt"[^\]]*"\/tim"[^\]]*\]/s);
  assert.doesNotMatch(footer, /primaryFooterHrefs = \[[^\]]*"\/impresum"[^\]]*\]/s, "Impresum should appear only in the legal row");
  assert.ok(footer.includes("Рекламирајте се"), "advertising should remain available as a secondary business link");
  assert.ok(!footer.includes("footer-ad-strip"), "footer should not contain an additional oversized advertising zone");
  assert.ok(footer.includes("Услови за користење") && footer.includes("Политика за приватност"), "legal destinations should remain in the bottom row");
});

test("footer newsletter is email-only while retaining accessible status behavior", () => {
  const newsletter = read("src/components/newsletter/NewsletterSignup.tsx");
  assert.ok(newsletter.includes('htmlFor="footer-newsletter-email"'), "newsletter email should have a real label");
  assert.ok(!newsletter.includes('name="name"'), "optional subscriber name should not add weight to the footer form");
  assert.ok(newsletter.includes("newsletter-message"), "newsletter should preserve success and error announcements");
});

test("footer fallback navigation includes ready legal pages and advertising pricing", () => {
  for (const label of [
    "За нас",
    "Контакт",
    "Ценовник за рекламирање",
    "Импресум",
    "Услови за користење",
    "Политика за приватност",
  ]) {
    assert.ok(navigation.includes(label), `footer fallback should include ${label}`);
  }

  for (const href of [
    "/za-nas",
    "/kontakt",
    "/cenovnik-za-reklamiranje",
    "/impresum",
    "/uslovi-za-koristenje",
    "/politika-za-privatnost",
  ]) {
    assert.ok(navigation.includes(href), `footer fallback should include ${href}`);
  }

  assert.ok(navigation.includes('label: "Ценовник за рекламирање"'), "footer should expose advertising pricing");
  assert.ok(
    productionNavigation.includes('label: "Ценовник за рекламирање"') &&
      !productionNavigation.includes('"/category/vesti",\n  "/cenovnik-za-reklamiranje"'),
    "production TypeScript navigation should expose advertising pricing too",
  );
});

test("footer legal pages exist with SEO metadata and Macedonian page copy", () => {
  for (const [path, heading] of [
    ["src/app/za-nas/page.tsx", "За нас"],
    ["src/app/kontakt/page.tsx", "Контакт"],
    ["src/app/impresum/page.tsx", "Импресум"],
    ["src/app/uslovi-za-koristenje/page.tsx", "Услови за користење"],
    ["src/app/politika-za-privatnost/page.tsx", "Политика за приватност"],
  ]) {
    assert.ok(existsSync(new URL(path, frontendRoot)), `${path} should exist`);
    const source = read(path);
    assert.ok(source.includes("metadata"), `${heading} should export metadata`);
    assert.ok(source.includes(heading), `${heading} page should render its heading`);
    assert.ok(source.includes("LegalPage") || source.includes("PublicPageShell"), `${heading} page should use the shared secondary-page system`);
  }

  assert.ok(advertisingPriceListPage.includes("metadata"), "advertising media kit should export metadata");
  assert.ok(advertisingPriceListPage.includes("Рекламирање на Разбуди се"), "advertising media kit should render its branded heading");
  assert.ok(advertisingPriceListPage.includes("SiteHeader"), "advertising media kit should use the public site header");

  assert.ok(legalPage.includes("legal-page"), "shared legal page wrapper should render the legal-page selector");
});

test("legacy impresium spelling redirects to the Macedonian impresum page", () => {
  assert.ok(nextConfig.includes("async redirects()"), "Next config should define static redirects");
  assert.ok(nextConfig.includes('source: "/impresium"'), "legacy impresium route should be matched");
  assert.ok(nextConfig.includes('destination: "/impresum"'), "legacy impresium route should target /impresum");
  assert.ok(nextConfig.includes("permanent: true"), "legacy impresium route should be a permanent redirect");
});

test("legacy WordPress posts page redirects to the replacement homepage", () => {
  assert.match(
    nextConfig,
    /source:\s*"\/blog"[\s\S]*?destination:\s*"\/"[\s\S]*?statusCode:\s*301/,
    "the published WordPress /blog page should keep an exact 301 replacement URL",
  );
});

test("advertising price list is RazbudiSe-owned and visible on-page", () => {
  assert.ok(!advertisingPriceListPage.includes("notFound()"), "finished price list should render publicly");
  assert.ok(advertisingPriceListPage.includes("index: true"), "finished price list should be indexable");
  assert.ok(!advertisingPriceListPage.includes("setaliste.mk"), "price list should not link to another site's PDF");
  assert.ok(!advertisingPriceListPage.includes("priceListUrl"), "price list should not depend on an external PDF action");
  assert.ok(advertisingPriceListPage.includes("AdvertisingMediaKit"), "price list should render structured live pricing");
  assert.ok(advertisingPriceListPage.includes("publicAdProducts"), "price list should group active backend inventory for customers");
  assert.ok(advertisingPriceListPage.includes("formatDenars"), "price list should show Macedonian denar prices dynamically");
  assert.ok(advertisingPriceListPage.includes("Разбуди се"), "price list should be branded for RazbudiSe");
});
