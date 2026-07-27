import assert from "node:assert/strict";
import test from "node:test";

import {
  archiveAdZones,
  archiveIntroCopy,
  archivePaginationSummary,
  archiveSectionTitle,
} from "./archivePresentation.mjs";

test("archiveAdZones returns expected zone keys for category and tag archives", () => {
  assert.deepEqual(archiveAdZones("category"), {
    top: "category_top",
    inFeed: "category_in_feed",
    inFeedSlots: [
      "category_in_feed",
      "category_in_feed_slot_2",
      "category_in_feed_slot_3",
      "category_in_feed_slot_4",
    ],
  });

  assert.deepEqual(archiveAdZones("tag"), {
    top: "search_results_top",
    inFeed: "category_in_feed",
    inFeedSlots: [
      "category_in_feed",
      "category_in_feed_slot_2",
      "category_in_feed_slot_3",
      "category_in_feed_slot_4",
    ],
  });
});

test("archivePaginationSummary creates compact Macedonian pagination copy", () => {
  assert.equal(archivePaginationSummary({ current_page: 2, last_page: 5, total: 41 }), "Страница 2 од 5 · 41 објави");
  assert.equal(archivePaginationSummary({ current_page: 1, last_page: 1, total: 1 }), "1 објава");
});

test("archiveSectionTitle names category and tag lists consistently", () => {
  assert.equal(archiveSectionTitle("category", "Вести"), "Објави во Вести");
  assert.equal(archiveSectionTitle("tag", "Битола"), "Објави со таг #Битола");
});

test("archiveIntroCopy gives readers useful category and tag context", () => {
  assert.equal(
    archiveIntroCopy("category", "Вести", null, 7),
    "Избор од 7 објави во рубриката Вести, подредени за лесно читање.",
  );
  assert.equal(
    archiveIntroCopy("tag", "Битола", null, 3),
    "Текстови поврзани со #Битола, собрани на едно место.",
  );
  assert.equal(
    archiveIntroCopy("category", "Живот", "Секојдневие, навики и инспирација.", 0),
    "Секојдневие, навики и инспирација.",
  );
});
