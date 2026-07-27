import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import test from "node:test";

const frontendRoot = new URL("../../../", import.meta.url);

function read(path) {
  return readFileSync(new URL(path, frontendRoot), "utf8");
}

function readBinary(path) {
  return readFileSync(new URL(path, frontendRoot));
}

function pngDimensions(path) {
  const file = readBinary(path);

  assert.equal(file.subarray(0, 8).toString("hex"), "89504e470d0a1a0a", `${path} should be a PNG image`);

  return {
    width: file.readUInt32BE(16),
    height: file.readUInt32BE(20),
  };
}

const css = read("src/app/globals.css");
const rootLayout = read("src/app/layout.tsx");
const siteHeader = read("src/components/SiteHeader.tsx");
const mobileAppChrome = read("src/components/MobileAppChrome.tsx");
const headerSearch = read("src/components/HeaderSearch.tsx");
const siteFooter = read("src/components/SiteFooter.tsx");
const themeToggle = read("src/components/ThemeToggle.tsx");
const articleCard = read("src/components/ArticleCard.tsx");
const adZone = read("src/components/AdZone.tsx");
const trackedAdPlacement = read("src/components/TrackedAdPlacement.tsx");
const apiClient = read("src/lib/api/client.ts");
const homepage = read("src/app/page.tsx");
const articlePage = read("src/app/[slug]/page.tsx");
const categoryPage = read("src/app/category/[slug]/page.tsx");
const tagPage = read("src/app/tag/[slug]/page.tsx");
const typeArchivePage = read("src/components/TypeArchivePage.tsx");
const eventsPage = read("src/app/nastani/page.tsx");
const authorPage = read("src/app/profil/[slug]/page.tsx");
const searchPage = read("src/app/search/page.tsx");
const errorPage = read("src/app/error.tsx");
const notFoundPage = read("src/app/not-found.tsx");

test("ad selection always asks the backend for a fresh eligibility decision", () => {
  const getAdPlacement = apiClient.slice(
    apiClient.indexOf("export async function getAdPlacement"),
    apiClient.indexOf("export async function getAdProducts"),
  );

  assert.ok(getAdPlacement.includes('cache: "no-store"'), "ad selection must bypass the Next fetch cache");
  assert.ok(getAdPlacement.includes("revalidate: 0"), "ad selection must not reuse a stale campaign decision");
});

test("RazbudiSe app icons are local assets from the original site", () => {
  assert.ok(rootLayout.includes("icons:"), "root layout metadata should expose local app icons");
  assert.ok(rootLayout.includes('/favicon.ico'), "root layout should expose the ICO favicon");
  assert.ok(rootLayout.includes('/icon.png'), "root layout should expose the PNG app icon");
  assert.ok(rootLayout.includes('/apple-icon.png'), "root layout should expose the Apple touch icon");
  assert.equal(readBinary("src/app/favicon.ico").subarray(0, 4).toString("hex"), "00000100");
  assert.deepEqual(pngDimensions("src/app/icon.png"), {
    width: 300,
    height: 300,
  });
  assert.deepEqual(pngDimensions("src/app/apple-icon.png"), {
    width: 300,
    height: 300,
  });
});

test("RazbudiSe Classic design tokens and masthead selectors exist", () => {
  for (const token of [
    "--font-serif:",
    "--font-sans:",
    "--paper:",
    "--ink:",
    "--accent:",
    "--line:",
  ]) {
    assert.ok(css.includes(token), `${token} should exist in globals.css`);
  }

  for (const selector of [
    ".site-header-inner",
    ".site-masthead",
    ".site-header-edition",
    ".section-nav",
    ".section-nav-scroll",
    ".ad-creative-body",
    ".ad-creative-body-fixed",
    ".lead-grid-editorial",
    ".lead-side-left",
    ".lead-side-right",
    ".home-ledger",
  ]) {
    assert.ok(css.includes(selector), `${selector} should exist in globals.css`);
  }

  assert.ok(siteHeader.includes("site-header-inner"), "SiteHeader should render the masthead wrapper");
  assert.ok(siteHeader.includes("site-masthead"), "SiteHeader should mark the logo link as the masthead");
  assert.ok(siteHeader.includes("site-header-edition"), "SiteHeader should render an editorial utility strip");
  assert.ok(siteHeader.includes("site-header-description"), "SiteHeader should render the editorial tagline");
  assert.ok(siteHeader.includes("HeaderDateTime"), "SiteHeader should include the live Macedonian date/time utility");
  assert.match(
    css,
    /\.site-header-edition\s*{[^}]*display: grid;[^}]*grid-template-columns: minmax\(0, 1fr\) minmax\(0, auto\) minmax\(0, 1fr\);/s,
    "desktop header utility row should center the tagline between date/time and search",
  );
  assert.match(
    css,
    /\.site-header-description\s*{[^}]*justify-self: center;[^}]*text-align: center;/s,
    "header tagline should be centered in its masthead column",
  );
  assert.ok(css.includes(".header-date-time"), "header date/time selector should exist");
  assert.ok(css.includes(".header-date-time-item"), "header date/time should style date and clock as compact items");
  assert.ok(siteHeader.includes("section-nav-scroll"), "SiteHeader should wrap mobile navigation in a scroll rail");
  assert.ok(siteHeader.includes("mobile-section-menu"), "SiteHeader should render an explicit mobile section menu");
  assert.ok(siteHeader.includes("mobile-section-menu-summary"), "mobile menu should have a visible tap target");
  assert.ok(siteHeader.includes("mobile-section-grid"), "mobile menu should expose sections as a readable grid");
  assert.match(
    css,
    /\.section-nav-submenu::before\s*{[^}]*content: "";[^}]*bottom: 100%;[^}]*height: 12px;/s,
    "desktop dropdowns should keep an invisible hover bridge between the menu label and submenu",
  );
  assert.ok(siteHeader.includes("HeaderSearch"), "SiteHeader should render the shared icon search control");
  assert.ok(headerSearch.includes('action="/search"'), "header search should submit to the canonical search page");
  assert.ok(css.includes(".header-search-panel"), "header search panel selector should exist");
  assert.ok(trackedAdPlacement.includes("adCreativeStyleVars"), "ad placements should compute fixed creative dimensions");
  assert.ok(trackedAdPlacement.includes("ad-creative-body-fixed"), "ad placements should mark fixed-size creative bodies");
  assert.ok(trackedAdPlacement.includes("style={creativeStyle}"), "ad placements should pass dimension variables to the creative body");
  assert.ok(trackedAdPlacement.includes("<iframe"), "HTML and script ad creatives should render in an iframe");
  assert.ok(
    trackedAdPlacement.includes("srcDoc={adCreativeDocument(placement.html)}"),
    "sandboxed ads should preserve creative markup in a self-contained document",
  );
  assert.ok(trackedAdPlacement.includes("adCreativeSandbox(placement.type)"), "ad sandbox permissions should depend on creative type");
  assert.equal(
    trackedAdPlacement.includes("dangerouslySetInnerHTML"),
    false,
    "advertiser markup must never be injected into the host document",
  );
  assert.equal(trackedAdPlacement.includes("allow-same-origin"), false, "ad iframes must retain an opaque origin");
  assert.ok(trackedAdPlacement.includes("impressionRequest"), "sandboxing must preserve impression tracking");
  assert.ok(trackedAdPlacement.includes("adClickHref"), "sandboxing must preserve tracked ad click URLs");
  assert.ok(
    adZone.includes("device={resolvedDevice}"),
    "the device used to select an ad should also be passed to impression tracking",
  );
  assert.ok(
    trackedAdPlacement.includes("device,"),
    "impression tracking should use the server-resolved selection device",
  );
  assert.equal(
    trackedAdPlacement.includes("matchMedia"),
    false,
    "impression tracking must not reclassify the selected device from viewport width",
  );
  assert.ok(css.includes(".ad-creative-sandbox"), "sandboxed creatives should fill their bounded ad body");
  assert.ok(adZone.includes("ad-zone-fixed"), "fixed-size ad placements should mark the full ad wrapper");
  assert.ok(adZone.includes("ad-zone-compact"), "short fixed-size ad placements should mark compact banner wrappers");
  assert.ok(adZone.includes("placement.height <= 120"), "compact ad treatment should be limited to short horizontal banners");
  assert.ok(adZone.includes("ad-zone-tile"), "medium rectangle fixed ads should mark tile wrappers");
  assert.ok(adZone.includes("placement.width <= 360"), "tile ad treatment should be limited to narrow medium rectangles");
  assert.ok(adZone.includes("style={zoneStyle}"), "fixed-size ad wrappers should receive the same dimension variables as the creative body");
  assert.match(
    css,
    /\.ad-zone-fixed\s*{[^}]*aspect-ratio: var\(--ad-aspect-ratio\);[^}]*box-sizing: border-box;[^}]*overflow: hidden;[^}]*padding: 0;[^}]*width: min\(100%, var\(--ad-width\)\);/s,
    "fixed-size ad wrappers should render at the declared booking dimensions",
  );
  assert.match(
    css,
    /\.ad-creative-body-fixed\s*{[^}]*aspect-ratio: var\(--ad-aspect-ratio\);[^}]*max-height: min\(var\(--ad-height\), 70vh\);[^}]*max-width: 100%;[^}]*width: min\(100%, var\(--ad-width\)\);/s,
    "ad creative body should render at its declared dimensions within the available viewport",
  );
  assert.match(
    css,
    /\.ad-zone-fixed \.ad-creative-body-fixed\s*{[^}]*height: 100%;[^}]*max-height: none;[^}]*width: 100%;/s,
    "fixed-size ad creative bodies should fill the fixed wrapper without making the full ad larger",
  );
  assert.match(
    css,
    /\.ad-zone-fixed \.ad-rate\s*{[^}]*background: var\(--foreground\);[^}]*color: var\(--surface\);/s,
    "fixed-size ad rate badges should keep readable light text on a dark background",
  );
  assert.match(
    css,
    /\.ad-zone-compact\s*{[^}]*align-items: center;[^}]*display: grid;[^}]*grid-template-columns: auto minmax\(0, 1fr\);/s,
    "compact fixed ad wrappers should lay out short banners as one readable row",
  );
  assert.match(
    css,
    /\.ad-zone-compact \.ad-creative-header\s*{[^}]*position: static;[^}]*width: auto;/s,
    "compact fixed ad headers should participate in the row instead of overlaying the body",
  );
  assert.match(
    css,
    /\.ad-zone-compact \.ad-creative-body-fixed\s*{[^}]*display: none;/s,
    "compact fixed ads should hide the duplicate placeholder body inside 90px banners",
  );
  assert.match(
    css,
    /\.ad-zone-tile\s*{[^}]*display: grid;[^}]*grid-template-rows: auto minmax\(0, 1fr\);[^}]*padding: 10px;/s,
    "medium rectangle ad tiles should stack metadata and creative content without overlap",
  );
  assert.match(
    css,
    /\.ad-zone-tile \.ad-zone-label\s*{[^}]*position: static;[^}]*width: max-content;/s,
    "medium rectangle ad labels should stay in normal flow instead of overlaying the tile",
  );
  assert.match(
    css,
    /\.ad-zone-tile \.ad-creative-header\s*{[^}]*display: grid;[^}]*position: static;[^}]*width: auto;/s,
    "medium rectangle ad headers should stack inside the tile instead of overlaying the creative body",
  );
  assert.match(
    css,
    /\.ad-zone-tile \.ad-creative-meta strong\s*{[^}]*display: none;/s,
    "medium rectangle ads should not duplicate the placement title in the metadata row",
  );
  assert.match(
    css,
    /\.article-ad-rail \.ad-zone\s*{[^}]*margin: 0;[^}]*padding: 9px;/s,
    "article sidebar ad wrappers should leave enough inner room for 300px creatives",
  );
  assert.match(
    css,
    /\.article-ad-rail \.ad-zone-fixed\s*{[^}]*padding: 0;/s,
    "fixed-size article sidebar ad wrappers should not add padding beyond the declared dimensions",
  );
  assert.match(
    css,
    /\.footer-primary\s*{[^}]*grid-template-columns: minmax\(0, \.9fr\) minmax\(440px, 1\.1fr\);/s,
    "desktop footer primary area should balance the brand and compact newsletter",
  );
  assert.ok(!siteFooter.includes("footer-ad-strip"), "SiteFooter should keep its compact three-zone hierarchy free of an oversized ad strip");
  assert.match(
    css,
    /\.mobile-sticky-ad \.ad-zone-fixed\s*{[^}]*margin: 0 auto;[^}]*padding: 0;/s,
    "fixed-size mobile sticky ads should stay centered without wrapper padding",
  );
});

test("shared mobile app chrome provides route-aware mobile navigation", () => {
  assert.ok(siteHeader.includes("MobileAppChrome"), "shared SiteHeader should render the mobile app chrome once");
  assert.ok(mobileAppChrome.includes("usePathname"), "mobile chrome should derive active state from the current route");
  assert.ok(mobileAppChrome.includes('pathname.startsWith("/reklami/zakazi")'), "booking should hide the global bottom navigation");
  assert.ok(mobileAppChrome.includes("mobile-bottom-nav"), "mobile chrome should include the bottom navigation");
  assert.ok(siteHeader.includes("site-header-actions"), "shared header should remain the single source for search and theme controls");
  assert.ok(siteHeader.includes("HeaderSearch"), "shared header should preserve search behavior");
  assert.ok(siteHeader.includes("ThemeToggle"), "shared header should preserve theme behavior");

  for (const label of ["Почетна", "Рубрики", "Пребарај", "Тим", "Мени"]) {
    assert.ok(mobileAppChrome.includes(label), `mobile bottom navigation should include ${label}`);
  }

  assert.match(css, /\.mobile-app-chrome\s*{[^}]*display:\s*none;/s, "mobile chrome should be hidden by default on desktop");
  assert.match(css, /@media \(max-width: 820px\)\s*{[\s\S]*\.mobile-app-chrome\s*{[^}]*display:\s*block;/s, "mobile chrome should appear only on mobile");
  assert.match(css, /\.mobile-bottom-nav\s*{[^}]*position:\s*fixed;[\s\S]*env\(safe-area-inset-bottom\)/s, "bottom nav should sit above the safe-area inset");
  assert.match(css, /\.mobile-bottom-nav a\s*{[^}]*min-height:\s*44px;/s, "mobile bottom nav targets should meet the 44px minimum");
  assert.match(css, /html:has\(\.ad-booking-form\) body\s*{[^}]*padding-bottom:\s*0;/s, "booking workflow should not keep global bottom-nav padding");
  assert.doesNotMatch(css, /\.site-header > \.site-header-inner\s*{[^}]*display:\s*none;/s, "mobile should keep the shared masthead and controls visible");
});

test("public frontend exposes persistent light and dark theme controls", () => {
  assert.ok(rootLayout.includes("razbudise-theme"), "root layout should initialize the persisted theme before paint");
  assert.ok(rootLayout.includes("data-theme"), "root layout should apply the theme to the document element");
  assert.ok(rootLayout.includes("suppressHydrationWarning"), "root layout should tolerate the client-set theme attribute");
  assert.ok(rootLayout.includes("prefers-color-scheme: dark"), "root layout should respect system preference before paint when no choice is saved");
  assert.ok(siteHeader.includes("ThemeToggle"), "SiteHeader should render the public theme toggle");
  assert.ok(siteHeader.includes("site-header-actions"), "SiteHeader should group search and theme actions");
  assert.ok(themeToggle.includes('"use client"'), "ThemeToggle should be a client component");
  assert.ok(themeToggle.includes("localStorage"), "ThemeToggle should persist the selected theme");
  assert.ok(themeToggle.includes("razbudise-theme"), "ThemeToggle should use the same storage key as the root script");
  assert.ok(themeToggle.includes("prefers-color-scheme: dark"), "theme control should respect system preference without a saved choice");
  assert.ok(themeToggle.includes("theme_changed"), "theme changes should use the existing analytics integration");
  assert.ok(themeToggle.includes('data-theme'), "ThemeToggle should update the document data-theme attribute");
  assert.ok(themeToggle.includes("Moon"), "light mode should expose only the Moon action icon");
  assert.ok(themeToggle.includes("Sun"), "dark mode should expose only the Sun action icon");
  assert.ok(themeToggle.includes("Префрли во темен режим"), "light mode should name the dark-mode action");
  assert.ok(themeToggle.includes("Префрли во светол режим"), "dark mode should name the light-mode action");
  assert.ok(headerSearch.includes("Search"), "search should use the Lucide Search icon");
  assert.ok(headerSearch.includes('aria-label="Пребарување"'), "search should retain its accessible name");
  assert.ok(css.includes('html[data-theme="dark"]'), "globals.css should define dark theme variables");
  assert.ok(css.includes(".header-icon-button"), "globals.css should style the shared icon control");
  assert.ok(css.includes("data-tooltip"), "header icons should expose their visual tooltip copy");
  assert.match(
    css,
    /html\[data-theme="dark"\]\s*{[^}]*--paper: #141110;[^}]*--accent: #ff6a45;/s,
    "dark theme should keep the RazbudiSe editorial palette with warm paper and orange accent",
  );
});

test("header has compact tablet and mobile masthead behavior", () => {
  const tabletStart = css.indexOf("@media (max-width: 820px)");
  const narrowStart = css.indexOf("@media (max-width: 560px)");

  assert.notEqual(tabletStart, -1, "globals.css should include a tablet header breakpoint");
  assert.notEqual(narrowStart, -1, "globals.css should include a narrow mobile breakpoint");

  const tabletCss = css.slice(tabletStart, narrowStart);
  const narrowCss = css.slice(narrowStart);

  assert.match(
    tabletCss,
    /\.site-header-edition\s*{[^}]*display: grid;[^}]*grid-template-columns: auto minmax\(0, 1fr\) auto;/s,
    "tablet header utility row should keep date, description, and search in one compact row",
  );
  assert.match(
    tabletCss,
    /\.header-search-open\s*{[^}]*grid-column: 1 \/ -1;[^}]*width: 100%;/s,
    "open mobile search should expand as a full-width row below the utility strip",
  );
  assert.match(
    tabletCss,
    /\.section-nav-scroll\s*{[^}]*display: none;/s,
    "tablet header should hide the scroll-only desktop nav",
  );
  assert.match(
    tabletCss,
    /\.mobile-section-menu\s*{[^}]*display: block;[^}]*width: 100%;/s,
    "tablet header should show an obvious mobile menu control",
  );
  assert.match(
    tabletCss,
    /\.mobile-section-menu-summary\s*{[^}]*display: flex;[^}]*min-height: 44px;/s,
    "mobile section menu summary should be a tap-friendly control",
  );
  assert.match(
    tabletCss,
    /\.mobile-section-grid\s*{[^}]*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/s,
    "opened mobile menu should show rubrics in a readable two-column grid",
  );
  assert.match(
    narrowCss,
    /\.site-header-description\s*{[^}]*display: none;/s,
    "narrow mobile header should hide the long description instead of wrapping the masthead",
  );
  assert.match(
    narrowCss,
    /\.header-date-time\s*{[^}]*justify-content: center;/s,
    "narrow mobile header should center the compact date/time row",
  );
  assert.match(narrowCss, /\.mobile-section-grid\s*{[^}]*grid-template-columns: 1fr;/s, "narrow menu should collapse to one column for long labels");
  assert.match(narrowCss, /\.brand\s*{[^}]*width: min\(240px, 72vw\);/s, "narrow mobile logo should be compact");
});

test("homepage uses the editorial lead layout contract", () => {
  assert.ok(homepage.includes("lead-grid lead-grid-editorial"), "homepage should use editorial lead grid class");
  assert.ok(homepage.includes("lead-side lead-side-left"), "homepage should render a left supporting column");
  assert.ok(homepage.includes("lead-story lead-story-primary"), "homepage should render a primary lead story");
  assert.ok(homepage.includes("lead-side lead-side-right"), "homepage should render a right supporting column");
  assert.ok(homepage.includes("home-ledger"), "homepage should render the editorial ledger after the lead package");
  assert.ok(homepage.includes("promo-card-strip"), "homepage should render the editable promo card strip below the hero");
  assert.ok(css.includes(".promo-card-grid"), "homepage promo cards should have a dedicated responsive grid");
  assert.ok(homepage.includes("Повеќе од редакцијата"), "homepage should use a compact more-featured heading");
  assert.ok(!homepage.includes("Уште од изборот на редакцијата"), "homepage should not use the awkward old more-featured heading");
  assert.ok(
    homepage.indexOf("lead-story lead-story-primary") < homepage.indexOf("lead-side lead-side-left"),
    "primary lead should come before supporting columns in source order",
  );
  assert.ok(
    homepage.includes('section.layout === "list" ? "list"'),
    "homepage list sections should render list ArticleCard variants",
  );
  assert.ok(
    homepage.includes('section.layout === "compact" ? "compact"'),
    "homepage compact sections should render compact ArticleCard variants",
  );
  assert.ok(homepage.includes("variant={sectionArticleVariant}"), "homepage should pass derived variants to section cards");

  const responsiveLeadStart = css.indexOf("@media (max-width: 980px)");
  const mobileStart = css.indexOf("@media (max-width: 820px)");
  assert.notEqual(responsiveLeadStart, -1, "homepage lead should define a 980px responsive breakpoint");
  assert.ok(mobileStart > responsiveLeadStart, "homepage lead responsive rules should come before the 820px mobile rules");

  const desktopLeadCss = css.slice(0, responsiveLeadStart);
  const leadGridRule = desktopLeadCss.match(/\.lead-grid-editorial\s*{[^}]*}/)?.[0] ?? "";
  const leadSideRule = desktopLeadCss.match(/\.lead-side-stories\s*{[^}]*}/)?.[0] ?? "";
  const primaryLeadRule = desktopLeadCss.match(/\.lead-story-primary\s*{[^}]*}/)?.[0] ?? "";
  assert.ok(leadGridRule.includes("align-items: stretch;"), "desktop lead grid should stretch side columns to the hero height");
  assert.ok(leadSideRule.includes("grid-template-rows: repeat(4, minmax(0, 1fr));"), "each desktop side rail should distribute four stories evenly");
  assert.ok(primaryLeadRule.includes("grid-column: 2;"), "primary lead should occupy the center desktop column");
  assert.ok(primaryLeadRule.includes("grid-row: 1;"), "primary lead should share the first desktop grid row");
  assert.match(css, /\.lead-image\s*{[^}]*aspect-ratio: 16 \/ 9;/s, "lead image should use a shorter editorial crop");
  assert.match(css, /\.lead-story h1\s*{[^}]*clamp\(/s, "lead headline should use bounded responsive sizing");
  assert.ok(
    desktopLeadCss.includes(".lead-side-left {\n  grid-column: 1;\n  grid-row: 1;\n}"),
    "left support column should occupy the left desktop column",
  );
  assert.ok(
    desktopLeadCss.includes(".lead-side-right {\n  grid-column: 3;\n  grid-row: 1;\n}"),
    "right support column should occupy the right desktop column",
  );

  const responsiveLeadCss = css.slice(responsiveLeadStart, mobileStart);
  assert.ok(responsiveLeadCss.includes("grid-template-columns: 1fr;"), "responsive lead grid should collapse to one column");
  assert.match(responsiveLeadCss, /\.lead-side-stories article:nth-of-type\(n \+ 4\)\s*{[^}]*display: none;/s, "stacked tablet rails should keep three stories per side");
  assert.match(responsiveLeadCss, /\.lead-story-primary\s*{[^}]*grid-column: 1;[^}]*grid-row: auto;[^}]*}/s, "primary lead should reset to the single mobile column");
  assert.match(responsiveLeadCss, /\.lead-side-left\s*{[^}]*grid-column: 1;[^}]*grid-row: auto;[^}]*}/s, "left support column should reset to the single mobile column");
  assert.match(responsiveLeadCss, /\.lead-side-right\s*{[^}]*grid-column: 1;[^}]*grid-row: auto;[^}]*}/s, "right support column should reset to the single mobile column");
});

test("article card exposes card, list, compact, and feature variants", () => {
  assert.ok(articleCard.includes('variant = "card"'), "ArticleCard should default to card variant");
  assert.ok(articleCard.includes("article-card-list"), "ArticleCard should support list variant class");
  assert.ok(articleCard.includes("article-card-compact"), "ArticleCard should support compact variant class");
  assert.ok(articleCard.includes("article-card-feature"), "ArticleCard should support feature variant class");
  assert.ok(articleCard.includes("imageFailed"), "ArticleCard should track only failed imported images");
  assert.ok(articleCard.includes("article-card-image-failed"), "ArticleCard should hide broken imported images behind a placeholder");
  assert.ok(!articleCard.includes("onLoad"), "ArticleCard should not hide valid images while waiting for client hydration");
  assert.ok(css.includes(".article-card-feature"), "feature card selector should exist");
  assert.ok(css.includes(".article-grid-count-4"), "homepage grids should have a balanced four-card selector");
  assert.ok(css.includes("grid-template-columns: repeat(4, minmax(0, 1fr));"), "four-card homepage sections should fill one desktop row");
  assert.ok(css.includes(".article-grid-count-2"), "homepage grids should have a balanced two-card selector");
  assert.ok(css.includes(".article-grid-single"), "homepage grids should have a single-card selector");
  assert.ok(css.includes(".article-card-image-failed"), "broken images should be visually hidden while fallback stays visible");
  assert.match(
    css,
    /\.article-card-placeholder-overlay\[hidden\]\s*{[^}]*display:\s*none\s*!important;/s,
    "fallback overlays must stay hidden while imported images are valid",
  );
  assert.match(css, /\.article-grid\s*{[^}]*align-items: stretch;/s, "article grids should stretch cards to matching row heights");
  assert.match(css, /\.article-card-content\s*{[^}]*display: flex;[^}]*flex-direction: column;/s, "article card content should push metadata to the bottom");
  assert.ok(css.includes("-webkit-line-clamp"), "article card titles and excerpts should clamp long imported text");
});

test("archives and search use editorial list cards", () => {
  for (const [label, source] of [
    ["category archive", categoryPage],
    ["tag archive", tagPage],
    ["type archive", typeArchivePage],
    ["events archive", eventsPage],
    ["author page", authorPage],
    ["search page", searchPage],
  ]) {
    assert.ok(source.includes('variant="list"'), `${label} should render ArticleCard with list variant`);
  }

  assert.ok(css.includes(".archive-list"), "archive list selector should exist");
  assert.ok(css.includes(".archive-feed"), "archive feed selector should exist");
  assert.ok(css.includes(".article-card-list"), "list card selector should exist");
  assert.ok(searchPage.includes("public-search-results"), "search results should use a dedicated results panel wrapper");
  assert.ok(css.includes(".public-search-results"), "search results panel selector should exist");
});

test("article reading surface keeps the approved classic selectors", () => {
  for (const selector of [
    ".article-detail",
    ".article-hero",
    ".article-excerpt",
    ".article-meta",
    ".article-body",
    ".article-reading-frame",
    ".article-main-column",
    ".article-content-layout",
    ".article-ad-rail",
    ".article-reading-summary",
    ".article-author-card",
    ".article-related-section",
    ".article-featured-media",
    ".tag-list",
  ]) {
    assert.ok(css.includes(selector), `${selector} should exist in globals.css`);
  }

  assert.ok(articlePage.includes("article-detail"), "article page should preserve article detail wrapper");
  assert.ok(articlePage.includes("article-hero"), "article page should preserve article hero wrapper");
  assert.ok(articlePage.includes("article-content-layout"), "article page should include a desktop content/ad rail layout");
  assert.ok(articlePage.includes("article-main-column"), "article page should keep the article header and body in one column");
  assert.ok(articlePage.includes("article-related-section"), "article page should let related articles span the full article frame");
  assert.ok(articlePage.includes("article_sidebar_top"), "article page should request the top sidebar ad zone");
  assert.ok(articlePage.includes("article_sidebar_sticky"), "article page should request the sticky sidebar ad zone");
  assert.ok(articlePage.includes("article-reading-frame"), "article page should wrap the reading flow for visual polish");
  assert.ok(articlePage.includes("ArticleReadingSummary"), "standard single articles should get a reusable reading summary");
  assert.ok(articlePage.includes("ArticleAuthorCard"), "all single articles should render the reusable author card");
  assert.ok(
    articlePage.indexOf("<ArticleAuthorCard article={article} />") > articlePage.indexOf("<ArticleBody article={article} />"),
    "article author card should render after the body on every single article",
  );
  assert.ok(articlePage.includes('variant="compact"'), "related articles should use compact cards on article pages");
  assert.match(css, /\.article-detail\s*{[^}]*max-width: 1300px;/s, "article detail should use a bounded reading and sidebar frame");
  assert.match(css, /\.article-main-column\s*{[^}]*max-width: 880px;/s, "article header and reading flow should share one balanced main column");
  assert.match(css, /\.article-hero\s*{[^}]*margin: 0;[^}]*text-align: left;/s, "article hero should align to the reading column left edge");
  assert.match(css, /\.article-content-layout\s*{[^}]*justify-content: start;/s, "article content grid should start from the same left edge as the hero");
  assert.match(css, /\.article-ad-rail\s*{[^}]*padding-top: 0;/s, "article sidebar ads should start beside the article hero, not below it");
  assert.match(css, /\.article-hero h1\s*{[^}]*font-size: 60px;/s, "article title should keep desktop scale without overpowering the sidebar");
  assert.match(css, /\.article-reading-frame > \.ad-zone\s*{[^}]*max-width: 880px;/s, "in-article ads should align to the featured image width");
  assert.match(css, /\.article-body\s*{[^}]*margin: 0;[^}]*max-width: 880px;/s, "article body should align to the featured image width below the media");
  assert.match(css, /\.article-reading-summary\s*{[^}]*border-left: 4px solid var\(--accent\);/s, "standard articles should have a restrained scannable summary panel");
  assert.match(css, /\.tag-list\s*{[^}]*margin: 24px 0 0;[^}]*max-width: 880px;/s, "article tags should align with the full article body width");
  assert.match(css, /\.article-related-section\s*{[^}]*max-width: 1300px;/s, "related articles should span the full article and sidebar frame");
  assert.ok(
    articlePage.indexOf('className="article-related-section article-section"') > articlePage.indexOf('className="article-content-layout"'),
    "related articles should render after the two-column article/ad layout",
  );
});

test("full-width public shell and richer ad surfaces are present", () => {
  for (const selector of [
    ".ad-creative-frame",
    ".ad-creative-header",
    ".ad-creative-meta",
    ".ad-dimensions",
    ".ad-rate",
    ".ad-creative-cta",
    ".mobile-sticky-ad",
  ]) {
    assert.ok(css.includes(selector), `${selector} should exist in globals.css`);
  }

  assert.match(css, /--site-container-wide: 1360px;/, "public pages should share one bounded wide-container token");
  assert.match(css, /\.site-container,[\s\S]*\.site-shell,[\s\S]*\.site-header-inner,[\s\S]*\.site-footer-inner\s*{[^}]*width: min\(var\(--site-container-wide\), calc\(100vw - 2 \* var\(--site-gutter\)\)\);/s, "header, content, and footer should share the wide-container contract");
  assert.ok(css.includes("position: sticky;"), "sidebar or sticky mobile ads should use sticky positioning");
  assert.ok(trackedAdPlacement.includes("ad-creative-header"), "ad placements should group booking metadata and CTA in one header row");
  assert.match(css, /\.ad-creative-frame\s*{[^}]*grid-template-columns: 1fr;/s, "ad cards should render as one cohesive vertical booking unit");
  assert.ok(css.includes(".ad-creative-frame-image .ad-creative-body"), "image ads should have a dedicated full-width body treatment");
  assert.ok(css.includes("Macedonian denar") === false, "public CSS should not leak English currency labels");
  assert.ok(articlePage.includes("article_before_related"), "article page should keep the before-related ad zone");
  assert.ok(homepage.includes("AdZoneGroup"), "homepage should render grouped in-feed ad slots");
  assert.ok(homepage.includes("slotKeys={adZoneAfterSlots}"), "homepage should render all generated in-feed slot keys");
  assert.ok(articlePage.includes("articleSidebarTopSlots"), "article page should render multiple top sidebar slots");
  assert.ok(articlePage.includes("articleSidebarStickySlots"), "article page should render multiple sticky sidebar slots");
  assert.ok(categoryPage.includes("slotKeys={adZones.inFeedSlots}"), "category archive should include grouped in-feed ads");
  assert.ok(tagPage.includes("slotKeys={adZones.inFeedSlots}"), "tag archive should include grouped in-feed ads");
  assert.ok(!siteFooter.includes("AdZoneGroup"), "compact footer should not render advertising inventory inside its shared shell");
  assert.match(css, /\.ad-zone-group\s*{[^}]*display: grid;/s, "grouped ad slots should use a responsive grid");
  assert.match(
    css,
    /\.ad-zone-group-feed\s*{[^}]*grid-template-columns: repeat\(4, minmax\(0, 1fr\)\);[^}]*justify-items: center;/s,
    "homepage and archive in-feed ad groups should place four medium rectangles in one desktop row",
  );
  assert.match(
    css,
    /@media \(max-width: 1220px\)\s*{[^}]*\.ad-zone-group-feed\s*{[^}]*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/s,
    "in-feed ad groups should collapse to a balanced two-by-two tablet layout",
  );
  assert.match(css, /\.article-ad-rail-sticky\s*{[^}]*display: grid;/s, "sticky article sidebar should stack multiple slots");
  assert.ok(searchPage.includes("search_results_top"), "search page should include the search top ad");
  assert.ok(authorPage.includes("author_page_top"), "author page should include the author top ad");
  assert.ok(eventsPage.includes("event_page_top"), "events page should include the events top ad");
});

test("footer and public state pages use polished editorial wrappers", () => {
  assert.ok(siteFooter.includes("footer-brand"), "SiteFooter should include a footer brand block");
  assert.ok(siteFooter.includes("footer-primary"), "SiteFooter should group the brand and newsletter in a balanced top row");
  assert.ok(siteFooter.includes("footer-newsletter-panel"), "SiteFooter should protect the newsletter from skinny columns");
  assert.ok(siteFooter.includes("footer-nav"), "SiteFooter should group compact primary navigation");
  assert.ok(siteFooter.includes("footer-legal"), "SiteFooter should group legal destinations in the bottom row");
  assert.ok(css.includes(".footer-brand"), "footer brand selector should exist");
  assert.ok(css.includes(".footer-primary"), "footer primary grid selector should exist");
  assert.ok(css.includes(".footer-newsletter-panel"), "footer newsletter panel selector should exist");
  assert.ok(css.includes(".footer-nav"), "footer navigation selector should exist");

  for (const [label, source] of [
    ["error page", errorPage],
    ["not found page", notFoundPage],
  ]) {
    assert.ok(source.includes("status-panel-editorial"), `${label} should use the polished status wrapper`);
  }

  assert.equal(
    existsSync(new URL("src/app/loading.tsx", frontendRoot)),
    false,
    "the root route must not stream before Next can determine a real HTTP 404",
  );
  assert.ok(!homepage.includes("<Suspense"), "the homepage should render primary content directly on the server");
  assert.ok(!homepage.includes("Се вчитува содржината"), "the stale full-page loading copy should not remain in homepage source");
});

test("mobile CSS includes narrow-screen safeguards for reader surfaces", () => {
  const narrowStart = css.indexOf("@media (max-width: 560px)");

  assert.notEqual(narrowStart, -1, "globals.css should include a narrow mobile breakpoint");

  const narrowCss = css.slice(narrowStart);

  for (const selector of [
    ".site-header-edition",
    ".lead-story h1",
    ".article-hero h1",
    ".article-card-feature",
    ".pagination",
  ]) {
    assert.ok(narrowCss.includes(selector), `${selector} should have narrow-screen rules`);
  }
});
