import assert from "node:assert/strict";
import { test } from "node:test";
import {
  requestCanonicalRedirect,
  requestRedirectDecision,
  redirectLookupUrl,
  redirectTargetUrl,
  shouldSkipRedirectLookup,
} from "./legacyRedirects.mjs";

test("requestCanonicalRedirect keeps the legacy blog redirect one-hop and normalizes other trailing slashes", () => {
  assert.deepEqual(requestCanonicalRedirect("/blog/"), { pathname: "/", status: 301 });
  assert.deepEqual(requestCanonicalRedirect("/blog"), { pathname: "/", status: 301 });
  assert.deepEqual(requestCanonicalRedirect("/category/vesti/"), { pathname: "/category/vesti", status: 308 });
  assert.equal(requestCanonicalRedirect("/category/vesti"), null);
  assert.equal(requestCanonicalRedirect("/"), null);
});

test("requestRedirectDecision prefers a direct DB redirect over generic trailing-slash canonicalization", () => {
  assert.deepEqual(
    requestRedirectDecision("/old-wordpress-slug/", {
      target_url: "/replacement-slug",
      status_code: 301,
    }),
    { kind: "resolved", targetUrl: "/replacement-slug", status: 301 },
  );
  assert.deepEqual(
    requestRedirectDecision("/category/vesti/"),
    { kind: "canonical", pathname: "/category/vesti", status: 308 },
  );
  assert.deepEqual(
    requestRedirectDecision("/blog/", {
      target_url: "/unexpected",
      status_code: 302,
    }),
    { kind: "canonical", pathname: "/", status: 301 },
  );
});

test("shouldSkipRedirectLookup skips framework and metadata paths", () => {
  assert.equal(shouldSkipRedirectLookup("/_next/static/app.js"), true);
  assert.equal(shouldSkipRedirectLookup("/api/status"), true);
  assert.equal(shouldSkipRedirectLookup("/robots.txt"), true);
  assert.equal(shouldSkipRedirectLookup("/stara-objava"), false);
  assert.equal(shouldSkipRedirectLookup("/apite-enterier-inspiracija/"), false);
});

test("redirectLookupUrl sends only the requested path to the backend resolver", () => {
  assert.equal(
    redirectLookupUrl("http://localhost:8000/api/", "https://razbudise.mk/stara-objava/?utm=1"),
    "http://localhost:8000/api/redirects/resolve?url=%2Fstara-objava%2F",
  );
});

test("redirectTargetUrl resolves local target paths against the current site", () => {
  assert.equal(
    redirectTargetUrl("https://razbudise.mk/stara-objava/", "/nova-objava"),
    "https://razbudise.mk/nova-objava",
  );
});
