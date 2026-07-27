import assert from "node:assert/strict";
import test from "node:test";

import {
  absolutePublicUrl,
  apiBaseUrl,
  canonicalUrlForPath,
  gaMeasurementId,
  indexingDisabled,
  normalizedBaseUrl,
  publicSiteUrl,
} from "./runtime.mjs";

test("normalizedBaseUrl trims trailing slashes and falls back for empty values", () => {
  assert.equal(normalizedBaseUrl("https://razbudise.mk///"), "https://razbudise.mk");
  assert.equal(normalizedBaseUrl("", "https://fallback.test/"), "https://fallback.test");
});

test("publicSiteUrl and apiBaseUrl expose stable local defaults", () => {
  assert.equal(publicSiteUrl(null), "http://localhost:3001");
  assert.equal(apiBaseUrl({ internal: null, publicBase: null }), "http://localhost:8001/api");
  assert.equal(apiBaseUrl({ internal: "http://backend:8000/api", publicBase: "http://localhost:8001/api" }), "http://backend:8000/api");
});

test("absolutePublicUrl and canonicalUrlForPath build public URLs without duplicate slashes", () => {
  assert.equal(absolutePublicUrl("/profil/redakcija", "https://razbudise.mk/"), "https://razbudise.mk/profil/redakcija");
  assert.equal(absolutePublicUrl("https://cdn.example.test/image.jpg", "https://razbudise.mk"), "https://cdn.example.test/image.jpg");
  assert.equal(canonicalUrlForPath("/category/vesti", null, "https://razbudise.mk/"), "https://razbudise.mk/category/vesti");
  assert.equal(
    canonicalUrlForPath("/category/vesti", "https://razbudise.mk/custom-canonical", "https://fallback.test"),
    "https://razbudise.mk/custom-canonical",
  );
});

test("canonicalUrlForPath strips non-canonical query noise and fragments while preserving useful pagination", () => {
  assert.equal(
    canonicalUrlForPath("/category/vesti?page=1&utm_source=facebook#comments", null, "https://razbudise.mk"),
    "https://razbudise.mk/category/vesti",
  );
  assert.equal(
    canonicalUrlForPath("/category/vesti?page=3&utm_medium=social", null, "https://razbudise.mk"),
    "https://razbudise.mk/category/vesti?page=3",
  );
  assert.equal(
    canonicalUrlForPath("/objava", "https://razbudise.mk/objava?preview_token=secret#draft", "https://fallback.test"),
    "https://razbudise.mk/objava",
  );
});

test("indexingDisabled treats explicit true-like values as a staging lock", () => {
  assert.equal(indexingDisabled("true"), true);
  assert.equal(indexingDisabled("1"), true);
  assert.equal(indexingDisabled("yes"), true);
  assert.equal(indexingDisabled("false"), false);
  assert.equal(indexingDisabled(""), false);
  assert.equal(indexingDisabled(undefined), false);
});

test("indexingDisabled keeps preview.example.test locked even when the env flag is missing or false", () => {
  assert.equal(indexingDisabled(undefined, "https://preview.example.test"), true);
  assert.equal(indexingDisabled("false", "https://preview.example.test/"), true);
  assert.equal(indexingDisabled(undefined, "https://razbudise.mk"), false);
});

test("gaMeasurementId accepts GA4 web stream IDs and rejects unsafe values", () => {
  assert.equal(gaMeasurementId(" g-qz2h51xpvv "), "G-QZ2H51XPVV");
  assert.equal(gaMeasurementId("UA-123-1"), null);
  assert.equal(gaMeasurementId("G-ABC\" onload=alert(1)"), null);
  assert.equal(gaMeasurementId(""), null);
});
