import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

import { signedPreviewApiPath } from "./signedPreviewPath.mjs";

const signature = "a".repeat(64);

test("normalizes Laravel's API-prefixed temporary signed preview path", () => {
  assert.equal(
    signedPreviewApiPath(`/api/preview/articles/42?expires=2000000000&signature=${signature}`),
    `/preview/articles/42?expires=2000000000&signature=${signature}`,
  );
});

test("rejects unsigned, unrelated, absolute, and protocol-relative preview targets", () => {
  assert.equal(signedPreviewApiPath("/api/preview/articles/42"), null);
  assert.equal(signedPreviewApiPath(`/preview/articles/42?expires=2000000000&signature=${signature}`), null);
  assert.equal(signedPreviewApiPath(`/api/articles/42?expires=2000000000&signature=${signature}`), null);
  assert.equal(signedPreviewApiPath(`https://example.test/api/preview/articles/42?expires=2000000000&signature=${signature}`), null);
  assert.equal(signedPreviewApiPath(`//example.test/api/preview/articles/42?expires=2000000000&signature=${signature}`), null);
});

test("editorial discovery fetches bypass the stale public cache window", () => {
  const apiClient = readFileSync(new URL("../api/client.ts", import.meta.url), "utf8");

  assert.match(apiClient, /const liveEditorialRequest = \{\s*cache: "no-store"/);

  for (const request of [
    "getAuthors",
    "getAuthorArticles",
    "getArticles",
    "getHomepage",
    "getArticle",
    "getRelatedArticles",
    "getCategoryArticles",
    "getTagArticles",
    "searchArticles",
  ]) {
    const start = apiClient.indexOf(`export async function ${request}`);
    const nextExport = apiClient.indexOf("export async function ", start + 1);
    const source = apiClient.slice(start, nextExport === -1 ? undefined : nextExport);

    assert.notEqual(start, -1, `${request} must remain part of the public API client`);
    assert.match(source, /liveEditorialRequest/, `${request} must not serve stale publication state`);
  }
});

test("the article page exposes only temporary signed previews", () => {
  const apiClient = readFileSync(new URL("../api/client.ts", import.meta.url), "utf8");
  const articlePage = readFileSync(new URL("../../app/[slug]/page.tsx", import.meta.url), "utf8");

  assert.equal(apiClient.includes("getArticlePreview("), false);
  assert.equal(apiClient.includes("preview_token"), false);
  assert.equal(articlePage.includes("preview_token"), false);
  assert.match(articlePage, /getArticleSignedPreview\(previewPath\)/);
});
