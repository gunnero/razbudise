import assert from "node:assert/strict";
import test from "node:test";

import { headerDateTimeParts } from "./headerDateTime.mjs";

test("headerDateTimeParts formats the masthead date and clock in Macedonian", () => {
  const parts = headerDateTimeParts(new Date("2026-06-29T19:35:02.000Z"));

  assert.deepEqual(parts, {
    dateLabel: "понеделник, јуни 29",
    dateTime: "2026-06-29T19:35:02.000Z",
    timeLabel: "21:35:02",
  });
});

test("headerDateTimeParts respects the configured timezone", () => {
  assert.equal(
    headerDateTimeParts(new Date("2026-06-29T22:15:04.000Z")).dateLabel,
    "вторник, јуни 30",
  );
});
