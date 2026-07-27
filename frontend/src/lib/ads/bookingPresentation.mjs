export function formatDenars(amount) {
  const roundedAmount = Math.round(Number(amount));
  const groupedAmount = String(roundedAmount).replace(/\B(?=(\d{3})+(?!\d))/g, ".");

  return `${groupedAmount} ден.`;
}

export function packageLabel(durationWeeks) {
  if (durationWeeks === 1) return "1 недела";
  if (durationWeeks === 2) return "2 недели";
  if (durationWeeks === 4) return "1 месец";
  return `${durationWeeks} недели`;
}

export function bookingEndDate(startDate, durationWeeks) {
  const date = new Date(`${startDate}T00:00:00.000Z`);
  date.setUTCDate(date.getUTCDate() + durationWeeks * 7);
  return date.toISOString().slice(0, 10);
}

export function creativeDimensionError({ requiredWidth, requiredHeight, actualWidth, actualHeight }) {
  return `Оваа реклама мора да биде ${requiredWidth}x${requiredHeight} px. Вашата слика е ${actualWidth}x${actualHeight} px.`;
}

export function localDateInputValue(date = new Date(), timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone) {
  const parts = new Intl.DateTimeFormat("en-US", {
    day: "2-digit",
    month: "2-digit",
    timeZone,
    year: "numeric",
  }).formatToParts(date);
  const valueFor = (type) => parts.find((part) => part.type === type)?.value ?? "";

  return `${valueFor("year")}-${valueFor("month")}-${valueFor("day")}`;
}

export function adProductDurationOptions(product) {
  const durations = new Set((product?.prices ?? []).map((price) => price.duration_weeks));

  return [...durations].sort((a, b) => a - b);
}

export function adProductCreativeTypeOptions(product, durationWeeks = null) {
  const prices = product?.prices ?? [];
  const types = prices
    .filter((price) => durationWeeks === null || price.duration_weeks === durationWeeks)
    .map((price) => price.creative_type);

  return [...new Set(types)];
}

function firstPrice(product) {
  return product?.prices?.[0] ?? null;
}

function priceForSelection(product, selection) {
  return (product?.prices ?? []).find(
    (price) => price.duration_weeks === selection.duration_weeks && price.creative_type === selection.creative_type,
  ) ?? null;
}

export function adProductSelectionForChange(product, selection = {}, changedField = "product") {
  const prices = product?.prices ?? [];

  if (prices.length === 0) {
    return {
      creative_type: selection.creative_type ?? "image",
      duration_weeks: selection.duration_weeks ?? 1,
    };
  }

  if (changedField === "duration") {
    const currentCreativeForDuration = prices.find(
      (price) => price.duration_weeks === selection.duration_weeks && price.creative_type === selection.creative_type,
    );
    const firstForDuration = prices.find((price) => price.duration_weeks === selection.duration_weeks);
    const price = currentCreativeForDuration ?? firstForDuration ?? firstPrice(product);

    return {
      creative_type: price.creative_type,
      duration_weeks: price.duration_weeks,
    };
  }

  if (changedField === "creative") {
    const currentDurationForCreative = prices.find(
      (price) => price.duration_weeks === selection.duration_weeks && price.creative_type === selection.creative_type,
    );
    const firstForCreative = prices.find((price) => price.creative_type === selection.creative_type);
    const price = currentDurationForCreative ?? firstForCreative ?? firstPrice(product);

    return {
      creative_type: price.creative_type,
      duration_weeks: price.duration_weeks,
    };
  }

  const currentPrice = priceForSelection(product, selection);
  const price = currentPrice ?? firstPrice(product);

  return {
    creative_type: price.creative_type,
    duration_weeks: price.duration_weeks,
  };
}

export function adProductPriceForSelection(product, selection) {
  const price = priceForSelection(product, selection);

  if (!price) {
    return null;
  }

  const multiplier = selection.is_political_or_sensitive ? Number(price.political_multiplier || 1) : 1;

  return Math.round(price.price_denars * (Number.isFinite(multiplier) ? multiplier : 1));
}

export function bookingRequestErrorMessage(
  error,
  fallback = "Не можевме да ја испратиме резервацијата. Проверете ги полињата и обидете се повторно.",
) {
  const payload = error?.payload;
  const errors = payload?.errors;

  if (errors && typeof errors === "object") {
    for (const value of Object.values(errors)) {
      if (Array.isArray(value) && typeof value[0] === "string" && value[0].trim() !== "") {
        return `Резервацијата не може да се испрати: ${value[0]}`;
      }

      if (typeof value === "string" && value.trim() !== "") {
        return `Резервацијата не може да се испрати: ${value}`;
      }
    }
  }

  if (typeof payload?.message === "string" && payload.message.trim() !== "") {
    return `Резервацијата не може да се испрати: ${payload.message}`;
  }

  if (typeof error?.message === "string" && error.message.trim() !== "" && error.name !== "ApiRequestError") {
    return `Резервацијата не може да се испрати: ${error.message}`;
  }

  return fallback;
}
