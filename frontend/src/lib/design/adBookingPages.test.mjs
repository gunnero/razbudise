import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import test from "node:test";

const pricePage = readFileSync("src/app/cenovnik-za-reklamiranje/page.tsx", "utf8");
const mediaKitPath = "src/components/ads/AdvertisingMediaKit.tsx";
const mediaKit = existsSync(mediaKitPath) ? readFileSync(mediaKitPath, "utf8") : "";
const presentation = readFileSync("src/lib/ads/advertisingPagePresentation.mjs", "utf8");
const bookingPage = readFileSync("src/app/reklami/zakazi/page.tsx", "utf8");
const bookingForm = readFileSync("src/components/ads/AdBookingForm.tsx", "utf8");
const bookingEditor = readFileSync("src/components/ads/AdBookingLineItemEditor.tsx", "utf8");
const creativeUpload = readFileSync("src/components/ads/AdCreativeUpload.tsx", "utf8");
const dashboardPage = readFileSync("src/app/reklami/dashboard/page.tsx", "utf8");
const renewalPanel = readFileSync("src/components/ads/AdRenewalPanel.tsx", "utf8");
const revisionForm = readFileSync("src/components/ads/AdCreativeRevisionForm.tsx", "utf8");

test("advertising price and booking pages use live ad booking flow", () => {
  assert.ok(pricePage.includes("getAdProducts"));
  assert.ok(pricePage.includes("/reklami/zakazi"));
  assert.ok(bookingPage.includes("AdBookingForm"));
  assert.ok(bookingForm.includes("ЕДБ"));
  assert.ok(bookingForm.includes("Точни димензии"));
  assert.ok(bookingForm.includes("Политичка"));
  assert.ok(bookingForm.includes("discount"));
});

test("advertising page uses a short goal, package, and collapsed catalogue flow", () => {
  assert.ok(pricePage.includes("AdvertisingMediaKit"));
  assert.ok(pricePage.includes("Што сакате да постигнете?"));
  assert.ok(pricePage.includes("Препорачани пакети"));
  assert.ok(pricePage.includes("Како се резервира реклама?"));
  assert.ok(pricePage.includes("Чести прашања"));
  assert.ok(mediaKit.includes("Сите рекламни позиции"));
  assert.ok(mediaKit.includes("Погледни ги сите позиции"));
  assert.ok(mediaKit.includes("catalogueOpen"));
  assert.ok(mediaKit.includes('window.location.hash !== "#pozicii"'));
  assert.ok(mediaKit.includes('setOpenCategory("home")'));
  assert.ok(mediaKit.includes('window.matchMedia("(prefers-reduced-motion: reduce)")'));
  assert.ok(mediaKit.includes("<details"), "grouped inventory should use native progressive disclosure");
  assert.ok(mediaKit.includes('aria-expanded={expanded}'), "position details should expose their selected state");
  assert.ok(mediaKit.includes("media-kit-mobile-reservation"));
  assert.ok(mediaKit.includes("expandedProduct ?"), "mobile reservation action should appear only after interaction");
  assert.ok(mediaKit.includes("bookingUrl"), "public products should preserve deep links into booking");
  assert.equal(pricePage.includes("Најбарани позиции"), false, "featured positions must not duplicate catalogue products");
  assert.equal(pricePage.includes("media-kit-preview"), false, "a large standalone placement preview must not render");
  for (const group of ["Почетна страница", "Во објава", "Рубрики и архиви", "Мобилни позиции", "Други позиции"]) assert.ok(mediaKit.includes(group));
  assert.ok(pricePage.includes('key: "starter"'));
  assert.ok(pricePage.includes('key: "reach"'));
  assert.ok(pricePage.includes('key: "premium"'));
  assert.ok(pricePage.includes("packageBookingUrl"));
  assert.ok(presentation.includes("advertising_monthly_unique_visitors"), "verified audience metrics should come from public settings");
  assert.ok(presentation.includes("advertising_metrics_verified"), "audience metrics should require an explicit verification gate");
  assert.ok(pricePage.includes("packageTotal"), "package prices should derive from active period prices");
  assert.ok(mediaKit.includes("availabilityUrl"), "expanded positions should check live availability");
  assert.ok(mediaKit.includes("media-kit-position-preview"), "expanded positions should include a focused preview");
  assert.ok(pricePage.includes("AdvertisingAnalytics"), "the sales journey should use the existing analytics integration");
  assert.ok(pricePage.includes("advertising-placement-devices-light.png"), "the pricing hero should include a light-theme illustration");
  assert.ok(pricePage.includes("advertising-placement-devices.png"), "the pricing hero should retain the dark-theme illustration");
  assert.ok(pricePage.includes("Резервирај пакет"), "package CTAs should state the conversion action clearly");
  assert.ok(pricePage.includes("ad_goal_recommendation_clicked"), "goal recommendations should remain measurable");
  assert.ok(pricePage.includes("ad_package_reservation_clicked"), "package reservations should remain measurable");
  assert.ok(pricePage.includes("package_name"), "package name should be preserved in reservation context");
  assert.ok(pricePage.includes("Колку брзо може да започне кампањата?"), "the campaign start FAQ should lead the FAQ section");
  assert.ok(mediaKit.includes("recommendedIds"), "goal CTAs should highlight the matching inventory");
  assert.ok(mediaKit.includes("focus({ preventScroll: true })"), "goal recommendations should preserve keyboard focus");
  assert.ok(mediaKit.includes("hidden={!expanded}"), "catalogue details should stay crawlable while collapsed visually");
  assert.ok(bookingPage.includes("initialZoneKeys"));
  assert.ok(bookingForm.includes("initialLineItems"));
  assert.equal(mediaKit.includes("internalZoneKeys"), false, "internal inventory labels must not be rendered");
});

test("customer ad dashboard exposes renewals, attention states, and creative revisions", () => {
  assert.ok(dashboardPage.includes("Потребно внимание"));
  assert.ok(dashboardPage.includes("AdCreativeRevisionForm"));
  assert.ok(renewalPanel.includes("renewal_options"));
  assert.ok(revisionForm.includes("submitCustomerAdCreativeRevision"));
  assert.ok(revisionForm.includes("uploadCustomerAdCreative"));
  assert.ok(revisionForm.includes("техничка проверка"));
});

test("public booking uses an accessible creative upload instead of exposing media IDs", () => {
  assert.equal(bookingEditor.includes("Број на слика од медиумската библиотека"), false);
  assert.equal(bookingEditor.includes("inputMode=\"numeric\""), false);
  assert.ok(bookingEditor.includes("AdCreativeUpload"));
  assert.ok(creativeUpload.includes("Прикачи рекламна слика"));
  assert.ok(creativeUpload.includes("Повлечете ја сликата тука"));
  assert.ok(creativeUpload.includes("Препорачана големина"));
  assert.ok(creativeUpload.includes("uploadPublicAdCreative"));
  assert.ok(creativeUpload.includes("removePublicAdCreative"));
  assert.ok(creativeUpload.includes("getCustomerAdCreativeMedia"));
  assert.ok(creativeUpload.includes("claimCustomerAdCreative"));
  assert.ok(creativeUpload.includes("if (value?.upload_token)"), "removing any selected creative must revoke its pending claim");
  assert.ok(creativeUpload.includes('aria-live="polite"'));
  assert.ok(creativeUpload.includes("Замени"));
  assert.ok(creativeUpload.includes("Отстрани"));
});
