import assert from "node:assert/strict";
import test from "node:test";

import {
  articleBodyMode,
  articleBodySequence,
  articleGalleryImages,
  articleHtmlSequence,
} from "./renderArticleBlocks.mjs";
import { mediaBaseUrlFromApiBase } from "../media/mediaUrls.mjs";

test("articleBodyMode prefers blocks when present and falls back to body_html", () => {
  assert.equal(articleBodyMode({ blocks: [{ type: "paragraph", data: {} }], body_html: "<p>Fallback</p>" }), "blocks");
  assert.equal(articleBodyMode({ blocks: [], body_html: "<p>Fallback</p>" }), "html");
});

test("articleBodySequence injects ad zones after paragraph 3 and paragraph 7", () => {
  const article = {
    id: 42,
    blocks: Array.from({ length: 8 }, (_, index) => ({
      type: "paragraph",
      data: { html: `<p>Пасус ${index + 1}</p>` },
      sort_order: index + 1,
    })),
    body_html: null,
  };

  const sequence = articleBodySequence(article);

  assert.equal(sequence.filter((item) => item.kind === "block").length, 8);
  assert.deepEqual(
    sequence.filter((item) => item.kind === "ad").map((item) => item.zoneKey),
    ["article_after_paragraph_3", "article_after_paragraph_7"],
  );
  assert.equal(sequence[3].kind, "ad");
  assert.equal(sequence[3].articleId, 42);
  assert.equal(sequence[8].kind, "ad");
});

test("articleHtmlSequence injects ad zones into legacy HTML bodies after paragraph 3 and paragraph 7", () => {
  const sequence = articleHtmlSequence({
    id: 77,
    body_html: Array.from({ length: 8 }, (_, index) => `<p>HTML пасус ${index + 1}</p>`).join(""),
  });

  assert.equal(sequence.filter((item) => item.kind === "html").length, 8);
  assert.deepEqual(
    sequence.filter((item) => item.kind === "ad").map((item) => item.zoneKey),
    ["article_after_paragraph_3", "article_after_paragraph_7"],
  );
  assert.equal(sequence[3].kind, "ad");
  assert.equal(sequence[3].articleId, 77);
  assert.equal(sequence[8].kind, "ad");
});

test("articleHtmlSequence groups adjacent imported image figures into one gallery", () => {
  const sequence = articleHtmlSequence({
    id: 88,
    body_html: [
      "<p>Вовед во приказната.</p>",
      '<figure><figure><img src="/storage/wordpress/first.jpg" alt="Прва фотографија" /></figure>',
      '<figure><img src="/storage/wordpress/second.jpg" alt="" /><figcaption>Втора фотографија</figcaption></figure>',
      '<figure><img src="/storage/wordpress/third.jpg" /></figure></figure>',
      "<p>Продолжение по галеријата.</p>",
    ].join(""),
  });

  const galleries = sequence.filter((item) => item.kind === "gallery");

  assert.equal(galleries.length, 1);
  assert.equal(galleries[0].images.length, 3);
  const mediaBaseUrl = mediaBaseUrlFromApiBase();
  assert.deepEqual(
    galleries[0].images.map((image) => image.url),
    [
      `${mediaBaseUrl}/storage/wordpress/first.jpg`,
      `${mediaBaseUrl}/storage/wordpress/second.jpg`,
      `${mediaBaseUrl}/storage/wordpress/third.jpg`,
    ],
  );
  assert.equal(galleries[0].images[0].alt, "Прва фотографија");
  assert.equal(galleries[0].images[1].alt, "Втора фотографија");
  assert.equal(galleries[0].images[1].caption, "Втора фотографија");
});

test("articleHtmlSequence leaves single imported images as html chunks", () => {
  const sequence = articleHtmlSequence({
    id: 89,
    body_html: '<p>Вовед.</p><figure><img src="/storage/wordpress/solo.jpg" alt="Самостојна" /></figure><p>Крај.</p>',
  });

  assert.equal(sequence.some((item) => item.kind === "gallery"), false);
  assert.equal(sequence.filter((item) => item.kind === "html").length, 3);
  assert.match(sequence[1].html, /solo\.jpg/);
});

test("articleGalleryImages normalizes array and JSON encoded gallery data", () => {
  assert.deepEqual(
    articleGalleryImages({
      data: {
        images: [
          {
            url: "https://example.test/one.jpg",
            alt: "Прва слика",
            caption: "Фото 1",
          },
          {
            url: "",
            alt: "Без URL",
          },
        ],
      },
    }),
    [
      {
        url: "https://example.test/one.jpg",
        alt: "Прва слика",
        caption: "Фото 1",
      },
    ],
  );

  assert.deepEqual(
    articleGalleryImages({
      data: {
        images: JSON.stringify([
          {
            url: "https://example.test/two.jpg",
            alt: "Втора слика",
          },
        ]),
      },
    }),
    [
      {
        url: "https://example.test/two.jpg",
        alt: "Втора слика",
        caption: null,
      },
    ],
  );
});

test("articleGalleryImages falls back to caption or article title for missing alt text", () => {
  assert.deepEqual(
    articleGalleryImages(
      {
        data: {
          images: [
            {
              url: "https://example.test/no-alt.jpg",
              caption: "Фото приказ",
            },
            {
              url: "https://example.test/no-alt-or-caption.jpg",
            },
          ],
        },
      },
      { fallbackAlt: "Наслов на статија" },
    ),
    [
      {
        url: "https://example.test/no-alt.jpg",
        alt: "Фото приказ",
        caption: "Фото приказ",
      },
      {
        url: "https://example.test/no-alt-or-caption.jpg",
        alt: "Наслов на статија",
        caption: null,
      },
    ],
  );
});
