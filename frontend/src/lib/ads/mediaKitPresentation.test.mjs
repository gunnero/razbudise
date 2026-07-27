import assert from "node:assert/strict";
import test from "node:test";

import { bookingUrl, priceForDuration, publicAdProducts } from "./mediaKitPresentation.mjs";

const price = (duration_weeks, price_denars) => ({ creative_type: "image", duration_weeks, political_multiplier: 2, price_denars });
const product = (zone_key, prices = [price(1, 7500)]) => ({ zone_key, name: zone_key, description: null, page_context: "home", allowed_sizes: [[300, 250]], prices });

test("public inventory deduplicates internal slots and keeps a valid booking zone", () => {
  const result = publicAdProducts([product("home_in_feed_1"), product("home_in_feed_1_slot_2"), product("home_in_feed_2")]);
  assert.equal(result.length, 1);
  assert.equal(result[0].name, "Во почетниот фид");
  assert.equal(result[0].bookingZoneKey, "home_in_feed_1");
  assert.deepEqual(result[0].internalZoneKeys, ["home_in_feed_1", "home_in_feed_1_slot_2", "home_in_feed_2"]);
  assert.equal("match" in result[0], false, "server-to-client presentation data must contain only serializable fields");
  assert.doesNotThrow(() => structuredClone(result[0]));
});

test("public inventory uses the active 7-day price and keeps every active period", () => {
  const result = publicAdProducts([product("article_after_title", [price(4, 32000), price(1, 9000), price(2, 17000)])]);
  assert.equal(result[0].startingPrice, 9000);
  assert.deepEqual(result[0].prices.map((item) => item.duration_weeks), [1, 2, 4]);
});

test("booking links remain connected to the existing booking flow", () => {
  assert.equal(bookingUrl("home_top_billboard"), "/reklami/zakazi?zone=home_top_billboard");
  assert.equal(bookingUrl("home_top_billboard", 2), "/reklami/zakazi?zone=home_top_billboard&period=2");
  assert.equal(bookingUrl(), "/reklami/zakazi");
});

test("package prices use the requested active duration instead of multiplying weekly prices", () => {
  const [result] = publicAdProducts([product("home_in_feed_1", [price(1, 7500), price(2, 14000), price(4, 26000)])]);
  assert.equal(priceForDuration(result, 1), 7500);
  assert.equal(priceForDuration(result, 2), 14000);
  assert.equal(priceForDuration(result, 4), 26000);
  assert.equal(priceForDuration(result, 3), null);
});
