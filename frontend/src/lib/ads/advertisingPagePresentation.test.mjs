import test from "node:test";
import assert from "node:assert/strict";

import {
  approvedClientLogos,
  metricsReportingPeriod,
  supportedDevicesLabel,
  verifiedAudienceMetrics,
} from "./advertisingPagePresentation.mjs";

test("verified audience metrics require an explicit verification flag and hide missing or zero values", () => {
  assert.deepEqual(verifiedAudienceMetrics({ advertising_monthly_page_views: "12000" }), []);
  assert.deepEqual(verifiedAudienceMetrics({ advertising_metrics_verified: "true", advertising_monthly_unique_visitors: "0", advertising_monthly_page_views: "12000" }), [
    { key: "advertising_monthly_page_views", label: "Прегледи на страници", value: "12.000" },
  ]);
});

test("metrics reporting period renders only configured data", () => {
  assert.equal(metricsReportingPeriod({ advertising_metrics_reporting_period: ["Последни 30 дена"] }), "Последни 30 дена");
  assert.equal(metricsReportingPeriod({}), null);
});

test("approved client logos require named root-relative approved assets", () => {
  assert.deepEqual(approvedClientLogos({ advertising_approved_client_logos: { "Одобрен бренд": "/storage/clients/brand.svg", "Надворешен": "https://example.com/logo.svg" } }), [
    { name: "Одобрен бренд", path: "/storage/clients/brand.svg" },
  ]);
  assert.deepEqual(approvedClientLogos({}), []);
});

test("supported device summary derives its labels from active products", () => {
  assert.equal(supportedDevicesLabel([{ device: "Компјутер и таблет" }, { device: "Мобилни уреди" }]), "Компјутер, таблет и мобилен");
  assert.equal(supportedDevicesLabel([]), "Уреди според позицијата");
});
