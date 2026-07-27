import assert from "node:assert/strict";
import test from "node:test";

import { adDeviceFromRequestHeaders } from "./device.mjs";

test("ad device detection uses request client hints when available", () => {
  assert.equal(adDeviceFromRequestHeaders({ mobileHint: "?1" }), "mobile");
  assert.equal(adDeviceFromRequestHeaders({ mobileHint: "?0" }), "desktop");
});

test("ad device detection distinguishes common mobile and tablet user agents", () => {
  assert.equal(
    adDeviceFromRequestHeaders({
      userAgent: "Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit Mobile/15E148",
    }),
    "mobile",
  );
  assert.equal(
    adDeviceFromRequestHeaders({
      userAgent: "Mozilla/5.0 (Linux; Android 14; Pixel 8 Pro) AppleWebKit Chrome Mobile Safari",
    }),
    "mobile",
  );
  assert.equal(
    adDeviceFromRequestHeaders({
      userAgent: "Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit Safari",
    }),
    "tablet",
  );
  assert.equal(
    adDeviceFromRequestHeaders({
      userAgent: "Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit Safari",
    }),
    "tablet",
  );
});

test("ad device detection defaults unknown and desktop requests to desktop", () => {
  assert.equal(adDeviceFromRequestHeaders(), "desktop");
  assert.equal(
    adDeviceFromRequestHeaders({
      userAgent: "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit Chrome Safari",
    }),
    "desktop",
  );
});
