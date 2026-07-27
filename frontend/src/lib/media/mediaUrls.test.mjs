import assert from "node:assert/strict";
import test from "node:test";

import {
  mediaBaseUrlFromApiBase,
  mediaFallbackLabel,
  normalizeMediaUrl,
  selectMediaUrl,
} from "./mediaUrls.mjs";
import { publicSiteUrl } from "../config/runtime.mjs";

test("mediaBaseUrlFromApiBase removes the API suffix for local storage URLs", () => {
  assert.equal(mediaBaseUrlFromApiBase("http://localhost:8000/api"), "http://localhost:8000");
  assert.equal(mediaBaseUrlFromApiBase("https://cms.example.com/api/v1/"), "https://cms.example.com");
});

test("normalizeMediaUrl preserves absolute URLs and joins local paths without duplicate slashes", () => {
  assert.equal(
    normalizeMediaUrl("https://cdn.example.com/media/cover.jpg", { baseUrl: "http://localhost:8000/" }),
    "https://cdn.example.com/media/cover.jpg",
  );
  assert.equal(
    normalizeMediaUrl("/storage/media/cover.jpg", { baseUrl: "http://localhost:8000/" }),
    "http://localhost:8000/storage/media/cover.jpg",
  );
  assert.equal(
    normalizeMediaUrl("storage/media/cover.jpg", { baseUrl: "http://localhost:8000" }),
    "http://localhost:8000/storage/media/cover.jpg",
  );
  assert.equal(
    normalizeMediaUrl("/brand/demo/cover.jpg", { baseUrl: "http://localhost:8000/api" }),
    `${publicSiteUrl()}/brand/demo/cover.jpg`,
  );
});

test("normalizeMediaUrl rewrites local imported storage URLs to the configured backend media origin", () => {
  assert.equal(
    normalizeMediaUrl("http://localhost/storage/wordpress/cover.jpg", { baseUrl: "http://localhost:8000/api" }),
    "http://localhost:8000/storage/wordpress/cover.jpg",
  );
  assert.equal(
    normalizeMediaUrl("http://127.0.0.1/storage/wordpress/cover.jpg?size=large", { baseUrl: "http://localhost:8000/api" }),
    "http://localhost:8000/storage/wordpress/cover.jpg?size=large",
  );
});

test("selectMediaUrl prefers responsive card variants before the original URL", () => {
  assert.equal(
    selectMediaUrl(
      {
        url: "/storage/media/original.jpg",
        responsive_urls: {
          large: "/storage/media/large.jpg",
          medium: "/storage/media/medium.jpg",
          thumbnail: "/storage/media/thumb.jpg",
        },
      },
      { baseUrl: "http://localhost:8000/api", variant: "card" },
    ),
    "http://localhost:8000/storage/media/medium.jpg",
  );
});

test("selectMediaUrl uses the large responsive variant for lead images", () => {
  assert.equal(
    selectMediaUrl(
      {
        url: "/storage/media/original.jpg",
        responsive_urls: {
          large: "/storage/media/large.jpg",
          medium: "/storage/media/medium.jpg",
        },
      },
      { baseUrl: "http://localhost:8000/api", variant: "lead" },
    ),
    "http://localhost:8000/storage/media/large.jpg",
  );
});

test("selectMediaUrl falls back to the original URL and returns null for missing media", () => {
  assert.equal(
    selectMediaUrl({ url: "/storage/media/original.jpg", responsive_urls: {} }, { baseUrl: "http://localhost:8000/api" }),
    "http://localhost:8000/storage/media/original.jpg",
  );
  assert.equal(selectMediaUrl(null, { baseUrl: "http://localhost:8000/api" }), null);
  assert.equal(mediaFallbackLabel, "Разбуди се");
});
