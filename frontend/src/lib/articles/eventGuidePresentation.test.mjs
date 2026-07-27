import assert from "node:assert/strict";
import test from "node:test";

import {
  eventSchemaItems,
  guideEventDateLabel,
  guideLastVerifiedLabel,
  guideQuickFacts,
  isEventGuideArticle,
} from "./eventGuidePresentation.mjs";

const eventGuide = {
  presentation_type: "event_guide",
  excerpt: "Краток водич за културните настани.",
  featured_image: {
    url: "https://razbudise.mk/storage/events/guide.jpg",
  },
  reading_time_minutes: 2,
  guide: {
    meta: {
      period_label: "20 до 26 јули",
      city_label: "Битола",
      last_verified_at: "2026-07-20",
      event_count: 3,
    },
    events: [
      {
        id: "herakleja",
        title: "Јубилејна вечер во Хераклеја",
        start_date: "2026-07-22",
        end_date: null,
        location: "Хераклеја",
        price_label: "600 денари",
        short_description: "Концертна вечер во античкиот локалитет.",
        source_url: "https://example.com/event",
        source_label: "Народен театар Битола",
      },
    ],
  },
};

test("event guide detection requires the explicit presentation type", () => {
  assert.equal(isEventGuideArticle(eventGuide), true);
  assert.equal(isEventGuideArticle({ ...eventGuide, presentation_type: "standard" }), false);
});

test("quick facts are derived from structured guide metadata", () => {
  assert.deepEqual(guideQuickFacts(eventGuide), ["3 настани", "20 до 26 јули", "Битола", "2 мин. читање"]);
});

test("event date labels hide missing dates and support ranges", () => {
  assert.equal(guideEventDateLabel({ start_date: "2026-07-22" }), "22 ЈУЛИ");
  assert.equal(guideEventDateLabel({ start_date: "2026-07-26", end_date: "2026-08-01" }), "26 ЈУЛИ до 1 АВГ");
  assert.equal(guideEventDateLabel({}), null);
});

test("last verified label uses the public Macedonian date format", () => {
  assert.equal(guideLastVerifiedLabel("2026-07-20"), "Последна проверка: 20 јули 2026 г.");
});

test("event schema renders only complete structured entries", () => {
  const schemas = eventSchemaItems(
    {
      ...eventGuide,
      guide: {
        ...eventGuide.guide,
        events: [
          eventGuide.guide.events[0],
          { id: "missing-location", title: "Без локација", start_date: "2026-07-23", source_url: "https://example.com" },
        ],
      },
    },
    "https://razbudise.mk/vodic",
  );

  assert.equal(schemas.length, 1);
  assert.equal(schemas[0]["@type"], "Event");
  assert.equal(schemas[0].name, "Јубилејна вечер во Хераклеја");
  assert.equal(schemas[0].description, "Концертна вечер во античкиот локалитет.");
  assert.deepEqual(schemas[0].image, ["https://razbudise.mk/storage/events/guide.jpg"]);
  assert.equal(schemas[0].location.address.addressCountry, "MK");
  assert.equal(schemas[0].location.address.addressLocality, "Хераклеја");
  assert.equal(schemas[0].offers["@type"], "Offer");
  assert.equal(schemas[0].offers.price, "600");
  assert.equal(schemas[0].offers.priceCurrency, "MKD");
  assert.equal(schemas[0].organizer.name, "Народен театар Битола");
});
