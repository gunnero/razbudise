import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import {
  buildRobotsRules,
  buildSitemapEntries,
  fetchAllSitemapItems,
  publicUrl,
} from "./discovery.mjs";

const sitemapSource = readFileSync(new URL("../../app/sitemap.ts", import.meta.url), "utf8");

test("sitemap is generated from the promoted CMS at request time", () => {
  assert.match(sitemapSource, /export const dynamic = ["']force-dynamic["']/);
  assert.equal(sitemapSource.includes("Promise.allSettled"), false);
  assert.ok(sitemapSource.includes("Promise.all("));
});

test("publicUrl creates absolute canonical URLs without duplicate slashes", () => {
  assert.equal(publicUrl("https://razbudise.mk/", "/category/vesti"), "https://razbudise.mk/category/vesti");
  assert.equal(publicUrl("https://razbudise.mk", "profil/redakcija"), "https://razbudise.mk/profil/redakcija");
  assert.equal(publicUrl("https://razbudise.mk///", "//category//vesti"), "https://razbudise.mk/category/vesti");
});

test("fetchAllSitemapItems fetches every advertised page", async () => {
  const calls = [];
  const pages = new Map([
    [1, {
      data: [{ url: "/prva" }],
      links: { next: "https://api.razbudise.mk/articles?page=2" },
      meta: { current_page: 1, last_page: 3 },
    }],
    [2, {
      data: [{ url: "/vtora" }],
      links: { next: "https://api.razbudise.mk/articles?page=3" },
      meta: { current_page: 2, last_page: 3 },
    }],
    [3, {
      data: [{ url: "/treta" }],
      links: { next: null },
      meta: { current_page: 3, last_page: 3 },
    }],
  ]);

  const items = await fetchAllSitemapItems(async (page, perPage) => {
    calls.push([page, perPage]);
    return pages.get(page);
  });

  assert.deepEqual(items, [{ url: "/prva" }, { url: "/vtora" }, { url: "/treta" }]);
  assert.deepEqual(calls, [[1, 100], [2, 100], [3, 100]]);
});

test("buildSitemapEntries includes public static, article, event, category, tag, and author URLs", () => {
  const entries = buildSitemapEntries({
    siteUrl: "https://razbudise.mk",
    articles: [
      { url: "/objava", published_at: "2026-06-25T10:00:00.000Z", updated_at: "2026-06-27T11:30:00.000Z" },
      { url: null, published_at: "2026-06-24T10:00:00.000Z" },
    ],
    events: [
      { url: "/event-objava", published_at: "2026-06-26T10:00:00.000Z" },
      { url: "/objava", published_at: "2026-06-25T10:00:00.000Z" },
    ],
    categories: [{ url: "/category/vesti" }],
    tags: [{ url: "/tag/kultura", updated_at: "2026-06-20T09:00:00.000Z" }],
    authors: [{ url: "/profil/redakcija", updated_at: "2026-06-19T09:00:00.000Z" }],
  });

  assert.deepEqual(
    entries.map((entry) => entry.url),
    [
      "https://razbudise.mk/",
      "https://razbudise.mk/tim",
      "https://razbudise.mk/nastani",
      "https://razbudise.mk/vesti",
      "https://razbudise.mk/intervju",
      "https://razbudise.mk/kolumni",
      "https://razbudise.mk/za-nas",
      "https://razbudise.mk/kontakt",
      "https://razbudise.mk/impresum",
      "https://razbudise.mk/uslovi-za-koristenje",
      "https://razbudise.mk/politika-za-privatnost",
      "https://razbudise.mk/objava",
      "https://razbudise.mk/event-objava",
      "https://razbudise.mk/category/vesti",
      "https://razbudise.mk/tag/kultura",
      "https://razbudise.mk/profil/redakcija",
    ],
  );
  assert.equal(
    entries.find((entry) => entry.url === "https://razbudise.mk/objava")?.lastModified?.toISOString(),
    "2026-06-27T11:30:00.000Z",
  );
  assert.equal(
    entries.find((entry) => entry.url === "https://razbudise.mk/tag/kultura")?.lastModified?.toISOString(),
    "2026-06-20T09:00:00.000Z",
  );
  assert.equal(
    entries.find((entry) => entry.url === "https://razbudise.mk/profil/redakcija")?.lastModified?.toISOString(),
    "2026-06-19T09:00:00.000Z",
  );
  assert.equal(entries[0].priority, 1);
  assert.equal(entries[2].changeFrequency, "daily");
});

test("buildSitemapEntries keeps sitemap URLs canonical and local", () => {
  const entries = buildSitemapEntries({
    siteUrl: "https://razbudise.mk/",
    articles: [
      { url: "/objava?preview_token=secret", published_at: "2026-06-25T10:00:00.000Z" },
      { url: "https://external.example/story", published_at: "2026-06-25T10:00:00.000Z" },
      { url: "/clean-story#comments", published_at: "2026-06-25T10:00:00.000Z" },
      { url: "/clean-story", published_at: "2026-06-26T10:00:00.000Z" },
    ],
    categories: [
      { url: "/category/vesti?utm_source=social", updated_at: "2026-06-20T09:00:00.000Z" },
      { url: "/category/vesti", updated_at: "2026-06-21T09:00:00.000Z" },
    ],
  });

  assert.equal(entries.some((entry) => entry.url.includes("preview_token")), false);
  assert.equal(entries.some((entry) => entry.url.includes("external.example")), false);
  assert.equal(entries.some((entry) => entry.url.includes("#")), false);
  assert.equal(entries.filter((entry) => entry.url === "https://razbudise.mk/clean-story").length, 1);
  assert.equal(entries.filter((entry) => entry.url === "https://razbudise.mk/category/vesti").length, 1);
});

test("buildRobotsRules exposes the sitemap and blocks private app paths", () => {
  const rules = buildRobotsRules("https://razbudise.mk");

  assert.equal(rules.sitemap, "https://razbudise.mk/sitemap.xml");
  assert.deepEqual(rules.rules, {
    userAgent: "*",
    allow: "/",
    disallow: ["/backoffice", "/api", "/*?preview_token=*"],
  });
  assert.equal(rules.host, "https://razbudise.mk");
});

test("buildRobotsRules blocks all crawlers when public indexing is disabled", () => {
  const rules = buildRobotsRules("https://preview.example.test", true);

  assert.deepEqual(rules.rules, {
    userAgent: "*",
    disallow: "/",
  });
  assert.equal(rules.sitemap, undefined);
  assert.equal(rules.host, "https://preview.example.test");
});

test("buildSitemapEntries returns no public URLs when public indexing is disabled", () => {
  const entries = buildSitemapEntries({
    siteUrl: "https://preview.example.test",
    articles: [{ url: "/objava", published_at: "2026-06-25T10:00:00.000Z" }],
    categories: [{ url: "/category/vesti" }],
  }, true);

  assert.deepEqual(entries, []);
});
