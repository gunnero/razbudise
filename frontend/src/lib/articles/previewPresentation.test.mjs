import assert from "node:assert/strict";
import test from "node:test";

import {
  mobileArticleFlow,
  previewBanner,
  previewMetadata,
} from "./previewPresentation.mjs";

test("previewBanner makes draft/scheduled preview mode obvious for editors", () => {
  assert.deepEqual(previewBanner(true), {
    label: "Уреднички преглед",
    message: "Ова е преглед на необјавена содржина. Линкот е само за редакциски преглед.",
  });
  assert.equal(previewBanner(false), null);
});

test("previewMetadata only overrides inherited robots metadata for previews", () => {
  const ordinaryMetadata = previewMetadata(false);

  assert.deepEqual(ordinaryMetadata, {});
  assert.equal(Object.hasOwn(ordinaryMetadata, "robots"), false);
  assert.deepEqual(previewMetadata(true), {
    robots: {
      index: false,
      follow: false,
      googleBot: {
        index: false,
        follow: false,
      },
    },
  });
});

test("mobileArticleFlow keeps article content and ads in a predictable order", () => {
  assert.deepEqual(mobileArticleFlow({ hasFeaturedImage: true, hasRelated: true }), [
    "preview-banner",
    "hero",
    "ad-after-title",
    "featured-image",
    "body",
    "tags",
    "ad-before-related",
    "related",
  ]);
});
