export function defaultRenewalDuration(options) {
  const firstAvailable = (options ?? []).find((option) => option.available && option.price_denars !== null);

  return firstAvailable?.duration_weeks ?? options?.[0]?.duration_weeks ?? 1;
}

export function renewalSubmitState(options, selectedDuration) {
  const option = (options ?? []).find((candidate) => candidate.duration_weeks === selectedDuration) ?? null;

  return {
    canSubmit: Boolean(option?.available && option.price_denars !== null),
    duration_weeks: selectedDuration,
    price_denars: option?.price_denars ?? null,
  };
}

export function renewalRequestPayload(lineItemId, durationWeeks) {
  return {
    lineItemId,
    durationWeeks,
  };
}
