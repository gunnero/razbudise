import {
  defaultRenewalDuration as defaultRenewalDurationBase,
  renewalRequestPayload as renewalRequestPayloadBase,
  renewalSubmitState as renewalSubmitStateBase,
} from "./customerDashboardPresentation.mjs";
import type { CustomerAdRenewalOption } from "@/lib/api/client";

export const defaultRenewalDuration = defaultRenewalDurationBase as (
  options: CustomerAdRenewalOption[],
) => 1 | 2 | 4;

export const renewalSubmitState = renewalSubmitStateBase as (
  options: CustomerAdRenewalOption[],
  selectedDuration: 1 | 2 | 4,
) => {
  canSubmit: boolean;
  duration_weeks: 1 | 2 | 4;
  price_denars: number | null;
};

export const renewalRequestPayload = renewalRequestPayloadBase as (
  lineItemId: number,
  durationWeeks: 1 | 2 | 4,
) => {
  lineItemId: number;
  durationWeeks: 1 | 2 | 4;
};
