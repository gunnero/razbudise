import assert from "node:assert/strict";
import test from "node:test";
import { openGraphImages } from "./metadataImages.mjs";

test("openGraphImages prefers the page image over the site default image", () => {
  assert.deepEqual(openGraphImages("/article.jpg", "/default-og.jpg"), [{ url: "/article.jpg" }]);
});

test("openGraphImages falls back to the site default image", () => {
  assert.deepEqual(openGraphImages(null, "/default-og.jpg"), [{ url: "/default-og.jpg" }]);
});

test("openGraphImages can normalize relative images against the public site URL", () => {
  assert.deepEqual(openGraphImages(null, "/default-og.jpg", "https://razbudise.mk/"), [
    { url: "https://razbudise.mk/default-og.jpg" },
  ]);
  assert.deepEqual(openGraphImages("/article.jpg", "/default-og.jpg", "https://razbudise.mk"), [
    { url: "https://razbudise.mk/article.jpg" },
  ]);
});

test("openGraphImages omits empty image values", () => {
  assert.equal(openGraphImages(null, null), undefined);
  assert.equal(openGraphImages("", "   "), undefined);
});
