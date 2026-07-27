import { mediaFallbackLabel, selectMediaUrl } from "../media/mediaUrls.mjs";

const macedonianMonths = [
  "јануари",
  "февруари",
  "март",
  "април",
  "мај",
  "јуни",
  "јули",
  "август",
  "септември",
  "октомври",
  "ноември",
  "декември",
];

export function articleBadge(article) {
  if (article?.sponsored_label) {
    return article.sponsored_label;
  }

  if (article?.is_sponsored) {
    return "Спонзорирана објава";
  }

  if (article?.type === "pr") {
    return "ПР текст";
  }

  return null;
}

export function formatArticleDate(value) {
  if (!value) {
    return null;
  }

  const dateValue = String(value);
  const isoDate = dateValue.match(/^(\d{4})-(\d{2})-(\d{2})/);
  let day;
  let monthIndex;
  let year;

  if (isoDate) {
    year = Number(isoDate[1]);
    monthIndex = Number(isoDate[2]) - 1;
    day = Number(isoDate[3]);
  } else {
    const date = new Date(dateValue);

    if (Number.isNaN(date.getTime())) {
      return null;
    }

    year = date.getFullYear();
    monthIndex = date.getMonth();
    day = date.getDate();
  }

  const month = macedonianMonths[monthIndex];

  if (!month || !Number.isFinite(day) || !Number.isFinite(year)) {
    return null;
  }

  return `${String(day).padStart(2, "0")} ${month} ${year} г.`;
}

export function articleMetaParts(article) {
  return [
    formatArticleDate(article?.published_at),
    article?.reading_time_minutes ? `${article.reading_time_minutes} мин. читање` : null,
  ].filter(Boolean);
}

export function articleHeroMetaParts(article) {
  return articleHeroMetaItems(article).map((item) => item.label);
}

export function articleHeroMetaItems(article) {
  return [
    article?.author?.name
      ? {
          href: article.author.url ?? null,
          key: "author",
          label: article.author.name,
        }
      : null,
    article?.category?.name
      ? {
          href: article.category.url ?? null,
          key: "category",
          label: article.category.name,
        }
      : null,
    formatArticleDate(article?.published_at)
      ? {
          href: null,
          key: "published_at",
          label: formatArticleDate(article.published_at),
        }
      : null,
    article?.reading_time_minutes
      ? {
          href: null,
          key: "reading_time",
          label: `${article.reading_time_minutes} мин. читање`,
        }
      : null,
  ].filter(Boolean);
}

export function sponsoredCampaignRangeLabel(startAt, endAt) {
  const start = formatArticleDate(startAt);
  const end = formatArticleDate(endAt);

  if (start && end) {
    return `Кампања: ${start} - ${end}`;
  }

  if (start) {
    return `Кампања од ${start}`;
  }

  if (end) {
    return `Кампања до ${end}`;
  }

  return null;
}

export function articleImagePresentation(article, options = {}) {
  const image = article?.featured_image;
  const focalPoint = image?.focal_point;
  const imageUrl = selectMediaUrl(image, {
    baseUrl: options.baseUrl,
    preferred: options.preferred,
    variant: options.variant ?? "card",
  });

  if (!imageUrl) {
    return {
      hasImage: false,
      url: null,
      alt: article?.title ?? "",
      caption: null,
      focalPointStyle: undefined,
      placeholderLabel: mediaFallbackLabel,
    };
  }

  return {
    hasImage: true,
    url: imageUrl,
    alt: image.alt_text ?? article?.title ?? "",
    caption: [image.caption, image.credit].filter(Boolean).join(" · ") || null,
    focalPointStyle: focalPoint ? { objectPosition: `${focalPoint.x}% ${focalPoint.y}%` } : undefined,
    placeholderLabel: null,
  };
}

export function articleBodyClassName(article) {
  const hasHtmlBody = typeof article?.body_html === "string" && article.body_html.trim() !== "";
  const hasBlocks = Array.isArray(article?.blocks) && article.blocks.length > 0;
  const isImportedOrLegacyHtml = article?.old_wordpress_id || article?.old_url || (hasHtmlBody && !hasBlocks);

  return ["article-body", isImportedOrLegacyHtml ? "article-body-imported" : null]
    .filter(Boolean)
    .join(" ");
}

export function relatedArticlesHeading(count) {
  return count > 0 ? "Продолжи со читање" : null;
}

export function searchResultsHeading(query, total) {
  if (!query) {
    return "Внеси збор за пребарување";
  }

  if (total === 0) {
    return `Нема резултати за „${query}“`;
  }

  if (total === 1) {
    return `1 резултат за „${query}“`;
  }

  return `${total} резултати за „${query}“`;
}

export function archiveResultsSummary(label, total) {
  if (total === 0) {
    return `Нема објавени текстови во ${label}.`;
  }

  if (total === 1) {
    return `1 објава во ${label}.`;
  }

  return `${total} објави во ${label}.`;
}

export function searchFilterUrl({
  author,
  category,
  page,
  published_from,
  published_to,
  query,
  sponsored,
  tag,
  type,
}) {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries({
    query,
    page,
    category,
    tag,
    author,
    type,
    sponsored,
    published_from,
    published_to,
  })) {
    if (value) {
      params.set(key, String(value));
    }
  }

  const queryString = params.toString();

  return queryString ? `/search?${queryString}` : "/search";
}
