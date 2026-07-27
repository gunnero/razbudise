import assert from "node:assert/strict";
import test from "node:test";

import {
  defaultRenewalDuration,
  renewalRequestPayload,
  renewalSubmitState,
} from "./customerDashboardPresentation.mjs";

const renewalOptions = [
  { duration_weeks: 1, available: false, price_denars: 9000 },
  { duration_weeks: 2, available: true, price_denars: 16000 },
  { duration_weeks: 4, available: false, price_denars: 30000 },
];

test("unavailable selected duration disables renewal submission", () => {
  assert.deepEqual(
    renewalSubmitState(renewalOptions, 1),
    { canSubmit: false, duration_weeks: 1, price_denars: 9000 },
  );
});

test("unpriced selected duration disables renewal submission", () => {
  assert.deepEqual(
    renewalSubmitState([{ duration_weeks: 1, available: true, price_denars: null }], 1),
    { canSubmit: false, duration_weeks: 1, price_denars: null },
  );
});

test("available selected duration can submit", () => {
  assert.deepEqual(
    renewalSubmitState(renewalOptions, 2),
    { canSubmit: true, duration_weeks: 2, price_denars: 16000 },
  );
});

test("default selected renewal duration picks first available option", () => {
  assert.equal(defaultRenewalDuration(renewalOptions), 2);
});

test("selected duration payload is preserved for submission", () => {
  assert.deepEqual(renewalRequestPayload(42, 4), { lineItemId: 42, durationWeeks: 4 });
});
