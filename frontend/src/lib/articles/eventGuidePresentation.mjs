import { formatArticleDate } from "./articlePresentation.mjs";

const shortMonths = [
  "ЈАН",
  "ФЕВ",
  "МАР",
  "АПР",
  "МАЈ",
  "ЈУН",
  "ЈУЛИ",
  "АВГ",
  "СЕП",
  "ОКТ",
  "НОЕ",
  "ДЕК",
];

export function isEventGuideArticle(article) {
  return article?.presentation_type === "event_guide" && Array.isArray(article?.guide?.events);
}

export function guideQuickFacts(article) {
  if (!isEventGuideArticle(article)) {
    return [];
  }

  return [
    article.guide.meta?.event_count ? `${article.guide.meta.event_count} настани` : null,
    article.guide.meta?.period_label ?? null,
    article.guide.meta?.city_label ?? null,
    article.reading_time_minutes ? `${article.reading_time_minutes} мин. читање` : null,
  ].filter(Boolean);
}

export function guideEventDateLabel(event) {
  const start = dateParts(event?.start_date);
  const end = dateParts(event?.end_date);

  if (start && end && event.start_date !== event.end_date) {
    if (start.month === end.month) {
      return `${start.day} до ${end.day} ${start.monthName}`;
    }

    return `${start.day} ${start.monthName} до ${end.day} ${end.monthName}`;
  }

  if (start) {
    return `${start.day} ${start.monthName}`;
  }

  return null;
}

export function guideLastVerifiedLabel(value) {
  const formatted = formatArticleDate(value);

  return formatted ? `Последна проверка: ${formatted}` : null;
}

export function eventSchemaItems(article, canonicalUrl) {
  if (!isEventGuideArticle(article)) {
    return [];
  }

  const image = firstImageUrl(article);

  return article.guide.events
    .filter((event) => event.title && event.start_date && event.location && (event.source_url || event.booking_url))
    .map((event) => ({
      "@context": "https://schema.org",
      "@type": "Event",
      name: event.title,
      description: event.short_description ?? article.excerpt ?? undefined,
      image: image ? [image] : undefined,
      startDate: event.start_date,
      endDate: event.end_date ?? undefined,
      eventStatus: "https://schema.org/EventScheduled",
      location: {
        "@type": "Place",
        name: event.location,
        address: {
          "@type": "PostalAddress",
          addressLocality: event.location,
          addressCountry: "MK",
        },
      },
      offers: eventOffer(event),
      organizer: eventOrganizer(event, canonicalUrl),
      url: event.booking_url ?? event.source_url ?? canonicalUrl,
    }))
    .map(cleanObjectDeep);
}

function dateParts(value) {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return null;
  }

  const [, month, day] = value.split("-").map(Number);
  const monthName = shortMonths[month - 1];

  return monthName ? { day, month, monthName } : null;
}

function firstImageUrl(article) {
  return article?.featured_image?.url || article?.seo?.og_image?.url || null;
}

function eventOrganizer(event, canonicalUrl) {
  if (event.source_label) {
    return cleanObjectDeep({
      "@type": "Organization",
      name: event.source_label,
      url: event.source_url ?? undefined,
    });
  }

  return {
    "@type": "Organization",
    name: "Разбуди се",
    url: new URL("/", canonicalUrl).toString(),
  };
}

function eventOffer(event) {
  const url = event.booking_url ?? event.source_url ?? undefined;
  const price = priceFromLabel(event.price_label);

  return cleanObjectDeep({
    "@type": "Offer",
    name: event.price_label ?? "Информации кај организаторот",
    url,
    availability: "https://schema.org/InStock",
    price: price ?? undefined,
    priceCurrency: price ? "MKD" : undefined,
  });
}

function priceFromLabel(label) {
  if (!label) {
    return null;
  }

  if (/слобод|бесплат|free/i.test(label)) {
    return "0";
  }

  const match = String(label).match(/\d+(?:[.,]\d+)?/);

  return match ? match[0].replace(",", ".") : null;
}

function cleanObjectDeep(value) {
  if (Array.isArray(value)) {
    return value.map(cleanObjectDeep).filter((item) => item !== undefined && item !== null && item !== "");
  }

  if (value && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value)
        .map(([key, item]) => [key, cleanObjectDeep(item)])
        .filter(([, item]) => item !== undefined && item !== null && item !== "" && (!Array.isArray(item) || item.length > 0)),
    );
  }

  return value;
}
