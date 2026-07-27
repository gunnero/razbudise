"use client";

import { useState } from "react";

import type { CustomerAdBookingItem, CustomerRenewalResponse } from "@/lib/api/client";
import { renewCustomerAdBooking } from "@/lib/api/client";
import { formatDenars, packageLabel } from "@/lib/ads/bookingPresentation";
import {
  defaultRenewalDuration,
  renewalRequestPayload,
  renewalSubmitState,
} from "@/lib/ads/customerDashboardPresentation";

type AdRenewalPanelProps = {
  item: CustomerAdBookingItem;
  onRenewed: (response: CustomerRenewalResponse) => void;
};

type RenewalState =
  | { kind: "idle"; message: string | null }
  | { kind: "submitting"; message: string }
  | { kind: "success"; message: string }
  | { kind: "error"; message: string };

function renewalErrorMessage(error: unknown): string {
  if (error instanceof Error && error.message) {
    return error.message;
  }

  return "Не можеме да го испратиме барањето за продолжување. Обидете се повторно.";
}

export function AdRenewalPanel({ item, onRenewed }: AdRenewalPanelProps) {
  const renewalOptions = item.renewal_options.length > 0
    ? item.renewal_options
    : ([1, 2, 4] as const).map((durationWeeks) => ({
      available: item.can_renew,
      duration_weeks: durationWeeks,
      price_denars: null,
    }));
  const [durationWeeks, setDurationWeeks] = useState<1 | 2 | 4>(() => defaultRenewalDuration(renewalOptions));
  const [state, setState] = useState<RenewalState>({ kind: "idle", message: null });
  const selectedState = renewalSubmitState(renewalOptions, durationWeeks);

  async function handleRenewal(): Promise<void> {
    if (!selectedState.canSubmit || state.kind === "submitting") {
      return;
    }

    setState({ kind: "submitting", message: "Го испраќаме барањето..." });

    try {
      const payload = renewalRequestPayload(item.id, durationWeeks);
      const response = await renewCustomerAdBooking(payload.lineItemId, payload.durationWeeks);

      setState({
        kind: "success",
        message: `Продолжувањето е примено (${response.data.reference}). Ќе добиете профактура за рачна уплата.`,
      });
      onRenewed(response);
    } catch (error) {
      setState({ kind: "error", message: renewalErrorMessage(error) });
    }
  }

  return (
    <div className="search-form search-form-expanded" aria-label="Продолжување на рекламна позиција">
      <label>
        Продолжи за
        <select
          disabled={state.kind === "submitting"}
          value={durationWeeks}
          onChange={(event) => setDurationWeeks(Number(event.target.value) as 1 | 2 | 4)}
        >
          {renewalOptions.map((option) => (
            <option key={option.duration_weeks} value={option.duration_weeks}>
              {packageLabel(option.duration_weeks)}
              {option.price_denars === null ? "" : ` · ${formatDenars(option.price_denars)}`}
              {option.available ? "" : " · недостапно"}
            </option>
          ))}
        </select>
      </label>
      <button disabled={!selectedState.canSubmit || state.kind === "submitting"} onClick={handleRenewal} type="button">
        {state.kind === "submitting" ? "Се испраќа..." : "Побарај продолжување"}
      </button>
      <p className="ad-price-list-note">
        {selectedState.canSubmit
          ? `Избраниот период е слободен${selectedState.price_denars ? ` · ${formatDenars(selectedState.price_denars)}` : ""}. Продолжувањето почнува веднаш по тековниот закуп.`
          : "Избраниот период моментално не е слободен или нема активна цена за автоматско продолжување."}
      </p>
      {state.message ? <p className="ad-price-list-note">{state.message}</p> : null}
    </div>
  );
}
