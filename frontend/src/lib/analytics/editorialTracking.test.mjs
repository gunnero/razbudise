import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { articleClickEventForContext, articleSlugFromHref, editorialContextForPathname } from "./editorialTracking.mjs";

test("public routes map to explicit editorial attribution contexts", () => {
  assert.equal(editorialContextForPathname("/"), "homepage");
  assert.equal(editorialContextForPathname("/category/nastani"), "category_page");
  assert.equal(editorialContextForPathname("/vesti"), "category_page");
  assert.equal(editorialContextForPathname("/profil/elena"), "author_page");
  assert.equal(editorialContextForPathname("/avtori"), "author_page");
  assert.equal(editorialContextForPathname("/tim"), "author_page");
  assert.equal(editorialContextForPathname("/avtor"), "author_page");
  assert.equal(editorialContextForPathname("/search"), "internal_search");
  assert.equal(editorialContextForPathname("/moja-objava"), "article");
  assert.equal(articleClickEventForContext("homepage"), "homepage_article_click");
});

test("only real top-level article URLs become tracked article slugs", () => {
  const origin = "https://razbudise.mk";
  assert.equal(articleSlugFromHref("/moja-objava", origin), "moja-objava");
  assert.equal(articleSlugFromHref("/category/nastani", origin), null);
  assert.equal(articleSlugFromHref("https://example.com/story", origin), null);
});

test("tracking requires explicit privacy consent and never sends raw IP", () => {
  const source = readFileSync(new URL("./editorialTracking.ts", import.meta.url), "utf8");
  assert.match(source, /consent\?\.analytics !== true/);
  assert.match(source, /analytics_consent: true/);
  assert.match(source, /razbudise-editorial-staff/);
  assert.match(source, /localStorage\.getItem\(editorialStaffStorageKey\) === "1"/);
  assert.doesNotMatch(source, /ip_address|client_ip|remote_addr/i);
});

test("article engagement keeps the captured entry attribution context", () => {
  const source = readFileSync(new URL("../../components/ArticleViewTracker.tsx", import.meta.url), "utf8");
  const sessionEvents = ["engaged_session", "scroll_depth", "completion"];
  for (const eventType of sessionEvents) {
    assert.match(source, new RegExp(`event_type: "${eventType}"[^\\n]+context_page: entryContext`));
  }
});

test("the analytics regression suite is part of the default frontend release gate", () => {
  const packageJson = JSON.parse(readFileSync(new URL("../../../package.json", import.meta.url), "utf8"));
  assert.match(packageJson.scripts.test, /src\/lib\/analytics\/editorialTracking\.test\.mjs/);
});
