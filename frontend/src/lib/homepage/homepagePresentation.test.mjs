import assert from "node:assert/strict";
import test from "node:test";

import {
  homepageAdZoneAfter,
  homepageLeadLayout,
  homepagePromoSections,
  homepageSectionGridClassName,
  mobileHomepageOrder,
  visibleHomepageSections,
} from "./homepagePresentation.mjs";

const lead = { slug: "lead", title: "Lead" };
const second = { slug: "second", title: "Second" };
const third = { slug: "third", title: "Third" };
const fourth = { slug: "fourth", title: "Fourth" };
const fifth = { slug: "fifth", title: "Fifth" };
const sixth = { slug: "sixth", title: "Sixth" };
const seventh = { slug: "seventh", title: "Seventh" };
const eighth = { slug: "eighth", title: "Eighth" };
const ninth = { slug: "ninth", title: "Ninth" };
const tenth = { slug: "tenth", title: "Tenth" };
const eleventh = { slug: "eleventh", title: "Eleventh" };
const twelfth = { slug: "twelfth", title: "Twelfth" };
const thirteenth = { slug: "thirteenth", title: "Thirteenth" };
const fourteenth = { slug: "fourteenth", title: "Fourteenth" };
const withImage = { slug: "with-image", title: "With image", featured_image: { url: "/cover.jpg" } };

test("homepageLeadLayout chooses the hero section and splits lead side stories", () => {
  const layout = homepageLeadLayout([
    { key: "latest", layout: "grid", articles: [third] },
    {
      key: "editorial",
      layout: "hero",
      articles: [
        lead,
        second,
        third,
        fourth,
        fifth,
        sixth,
        seventh,
        eighth,
        ninth,
        tenth,
        eleventh,
        twelfth,
        thirteenth,
        fourteenth,
      ],
    },
  ]);

  assert.equal(layout.featured.key, "editorial");
  assert.equal(layout.leadArticle.slug, "lead");
  assert.deepEqual(layout.sideArticles.map((article) => article.slug), [
    "second",
    "third",
    "fourth",
    "fifth",
    "sixth",
    "seventh",
    "eighth",
    "ninth",
  ]);
  assert.deepEqual(layout.remainingFeatured.map((article) => article.slug), ["tenth", "eleventh", "twelfth"]);
});

test("homepageLeadLayout prefers a hero lead article with a usable image", () => {
  const layout = homepageLeadLayout([
    { key: "editorial", layout: "hero", articles: [lead, withImage, second] },
  ]);

  assert.equal(layout.leadArticle.slug, "with-image");
  assert.deepEqual(layout.sideArticles.map((article) => article.slug), ["lead", "second"]);
});

test("homepageLeadLayout fills eight unique rail slots from lower-priority sections", () => {
  const layout = homepageLeadLayout([
    { key: "priority", layout: "grid", articles: [fourteenth] },
    { key: "editorial", layout: "hero", articles: [lead, second, third, fourth, fifth, sixth, seventh] },
    { key: "news", layout: "grid", articles: [lead, seventh, eighth, ninth, tenth] },
  ]);

  assert.deepEqual(layout.sideArticles.map((article) => article.slug), [
    "second",
    "third",
    "fourth",
    "fifth",
    "sixth",
    "seventh",
    "eighth",
    "ninth",
  ]);
  assert.equal(layout.sideArticles.some((article) => article.slug === "fourteenth"), false);
  assert.equal(new Set(layout.sideArticles.map((article) => article.slug)).size, 8);
});

test("visibleHomepageSections removes the featured section and empty sections", () => {
  assert.deepEqual(
    visibleHomepageSections(
      [
        { key: "hero", layout: "hero", articles: [lead] },
        { key: "empty", layout: "grid", articles: [] },
        { key: "news", layout: "grid", articles: [second] },
      ],
      "hero",
    ).map((section) => section.key),
    ["news"],
  );
});

test("visibleHomepageSections removes duplicates, promo rows, and excessive sections", () => {
  const sections = visibleHomepageSections([
    { key: "hero", layout: "hero", articles: [lead] },
    { key: "promo", type: "promo_cards", articles: [second] },
    { key: "one", articles: [lead, second, third, fourth, fifth, sixth, seventh] },
    { key: "two", articles: [second, eighth] },
    { key: "three", articles: [ninth] },
    { key: "four", articles: [tenth] },
    { key: "five", articles: [eleventh] },
    { key: "six", articles: [twelfth] },
  ], "hero");

  assert.deepEqual(sections.map((section) => section.key), ["one", "two", "three", "four", "five"]);
  assert.deepEqual(sections[0].articles.map((article) => article.slug), ["second", "third", "fourth", "fifth", "sixth", "seventh"]);
  assert.deepEqual(sections[1].articles.map((article) => article.slug), ["eighth"]);
});

test("visibleHomepageSections removes supplemental lead-rail stories from later sections", () => {
  const sections = visibleHomepageSections([
    { key: "hero", layout: "hero", articles: [lead] },
    { key: "news", articles: [second, third, fourth] },
  ], "hero", [lead, second, third]);

  assert.deepEqual(sections[0].articles.map((article) => article.slug), ["fourth"]);
});

test("homepagePromoSections returns editable promo card sections without mixing them into article grids", () => {
  assert.deepEqual(
    homepagePromoSections([
      { key: "hero", type: "latest", layout: "hero", articles: [lead], promo_cards: [] },
      { key: "special", type: "promo_cards", layout: "grid", articles: [], promo_cards: [{ title: "Мими Yugo" }] },
      { key: "empty", type: "promo_cards", layout: "grid", articles: [], promo_cards: [] },
      { key: "latest", type: "latest", layout: "grid", articles: [second], promo_cards: [] },
    ]).map((section) => section.key),
    ["special"],
  );
});

test("homepageAdZoneAfter uses configured section zone before default in-feed slots", () => {
  assert.equal(homepageAdZoneAfter({ ad_zone_after: "home_after_featured" }, 0), "home_after_featured");
  assert.equal(homepageAdZoneAfter({ ad_zone_after: null }, 0), "home_in_feed_1");
  assert.equal(homepageAdZoneAfter({ ad_zone_after: null }, 1), "home_in_feed_2");
  assert.equal(homepageAdZoneAfter({ ad_zone_after: null }, 2), "home_in_feed_3");
  assert.equal(homepageAdZoneAfter({ ad_zone_after: null }, 5), null);
});

test("homepageSectionGridClassName balances short homepage sections", () => {
  assert.equal(
    homepageSectionGridClassName({ layout: "grid", articles: [lead, second, third, fourth] }),
    "article-grid article-grid-count-4",
  );
  assert.equal(
    homepageSectionGridClassName({ layout: "compact", articles: [lead, second, third, fourth] }),
    "article-grid article-grid-compact article-grid-count-4",
  );
  assert.equal(
    homepageSectionGridClassName({ layout: "grid", articles: [lead, second] }),
    "article-grid article-grid-count-2",
  );
  assert.equal(
    homepageSectionGridClassName({ layout: "grid", articles: [lead] }),
    "article-grid article-grid-single",
  );
  assert.equal(
    homepageSectionGridClassName({ layout: "grid", articles: [lead, second, third, fourth, fifth, sixth] }),
    "article-grid",
  );
  assert.equal(
    homepageSectionGridClassName({ layout: "list", articles: [lead, second, third, fourth] }),
    "article-list",
  );
});

test("mobileHomepageOrder keeps launch-critical homepage blocks in order", () => {
  assert.deepEqual(
    mobileHomepageOrder([
      { key: "hero", layout: "hero", articles: [lead] },
      { key: "special", type: "promo_cards", layout: "grid", articles: [], promo_cards: [{ title: "Мими Yugo" }] },
      { key: "latest", layout: "grid", articles: [second] },
      { key: "culture", layout: "grid", articles: [third] },
    ]),
    [
      "lead-story",
      "home-top-billboard",
      "latest-stories",
      "promo:special",
      "section:latest",
      "section:culture",
      "newsletter",
      "footer",
    ],
  );
});
