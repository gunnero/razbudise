import assert from "node:assert/strict";
import test from "node:test";
import {
  adProductCreativeTypeOptions,
  adProductDurationOptions,
  adProductSelectionForChange,
  bookingRequestErrorMessage,
  bookingEndDate,
  creativeDimensionError,
  formatDenars,
  localDateInputValue,
  packageLabel,
} from "./bookingPresentation.mjs";

const products = [
  {
    zone_key: "home_top_billboard",
    allowed_sizes: [[970, 250]],
    prices: [
      { duration_weeks: 1, creative_type: "image", price_denars: 18000, political_multiplier: 1.5 },
      { duration_weeks: 2, creative_type: "html", price_denars: 32000, political_multiplier: 1.5 },
    ],
  },
  {
    zone_key: "article_sidebar_sticky",
    allowed_sizes: [[300, 600]],
    prices: [
      { duration_weeks: 4, creative_type: "script", price_denars: 30000, political_multiplier: 2 },
    ],
  },
];

test("formatDenars renders Macedonian denar prices", () => {
  assert.equal(formatDenars(18000), "18.000 ден.");
});

test("packageLabel renders launch durations", () => {
  assert.equal(packageLabel(1), "1 недела");
  assert.equal(packageLabel(2), "2 недели");
  assert.equal(packageLabel(4), "1 месец");
});

test("bookingEndDate locks duration from selected start", () => {
  assert.equal(bookingEndDate("2026-07-01", 2), "2026-07-15");
});

test("creativeDimensionError is specific", () => {
  assert.equal(
    creativeDimensionError({ requiredWidth: 300, requiredHeight: 250, actualWidth: 728, actualHeight: 90 }),
    "Оваа реклама мора да биде 300x250 px. Вашата слика е 728x90 px.",
  );
});

test("localDateInputValue uses the local calendar day instead of UTC", () => {
  const timestamp = new Date("2026-07-01T22:30:00.000Z");

  assert.equal(timestamp.toISOString().slice(0, 10), "2026-07-01");
  assert.equal(localDateInputValue(timestamp, "Europe/Skopje"), "2026-07-02");
});

test("available product options derive from active prices", () => {
  assert.deepEqual(adProductDurationOptions(products[0]), [1, 2]);
  assert.deepEqual(adProductCreativeTypeOptions(products[0]), ["image", "html"]);
  assert.deepEqual(adProductCreativeTypeOptions(products[0], 2), ["html"]);
});

test("adProductSelectionForChange picks valid product duration and creative pairs", () => {
  assert.deepEqual(
    adProductSelectionForChange(products[1], { duration_weeks: 1, creative_type: "image" }),
    { duration_weeks: 4, creative_type: "script" },
  );

  assert.deepEqual(
    adProductSelectionForChange(products[0], { duration_weeks: 2, creative_type: "image" }, "duration"),
    { duration_weeks: 2, creative_type: "html" },
  );

  assert.deepEqual(
    adProductSelectionForChange(products[0], { duration_weeks: 2, creative_type: "image" }, "creative"),
    { duration_weeks: 1, creative_type: "image" },
  );
});

test("bookingRequestErrorMessage returns Laravel field errors, then message, then generic fallback", () => {
  assert.equal(
    bookingRequestErrorMessage({
      payload: {
        message: "The given data was invalid.",
        errors: {
          "items.0.duration_weeks": ["No active ad price exists for the selected zone, duration, and creative type."],
        },
      },
    }),
    "Резервацијата не може да се испрати: No active ad price exists for the selected zone, duration, and creative type.",
  );

  assert.equal(
    bookingRequestErrorMessage({ payload: { message: "Too Many Attempts." } }),
    "Резервацијата не може да се испрати: Too Many Attempts.",
  );

  assert.equal(
    bookingRequestErrorMessage({}),
    "Не можевме да ја испратиме резервацијата. Проверете ги полињата и обидете се повторно.",
  );
});
