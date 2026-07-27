import { defaultApiBaseUrl, normalizedBaseUrl, publicSiteUrl } from "../config/runtime.mjs";

export const mediaFallbackLabel = "Разбуди се";

const variantOrders = {
  card: ["medium", "large", "thumbnail", "original"],
  lead: ["large", "medium", "original", "thumbnail"],
  thumbnail: ["thumbnail", "medium", "original", "large"],
  original: ["original", "large", "medium", "thumbnail"],
};

function publicApiBaseUrl(value = process.env.NEXT_PUBLIC_API_BASE_URL) {
  return normalizedBaseUrl(value, defaultApiBaseUrl);
}

export function mediaBaseUrlFromApiBase(value = publicApiBaseUrl()) {
  return normalizedBaseUrl(value, defaultApiBaseUrl).replace(/\/api(?:\/v\d+)?$/i, "");
}

function isLocalStorageUrl(url) {
  return ["localhost", "127.0.0.1", "::1"].includes(url.hostname) && url.pathname.startsWith("/storage/");
}

function publicAssetBaseUrl() {
  return publicSiteUrl();
}

export function normalizeMediaUrl(value, { baseUrl = publicApiBaseUrl() } = {}) {
  if (typeof value !== "string") {
    return null;
  }

  const candidate = value.trim();

  if (!candidate) {
    return null;
  }

  if (candidate.startsWith("//")) {
    return `https:${candidate}`;
  }

  if (/^data:image\//i.test(candidate) || /^blob:/i.test(candidate)) {
    return candidate;
  }

  if (/^https?:\/\//i.test(candidate)) {
    const url = new URL(candidate);

    if (isLocalStorageUrl(url)) {
      return `${mediaBaseUrlFromApiBase(baseUrl)}${url.pathname}${url.search}${url.hash}`;
    }

    return candidate;
  }

  if (/^[a-z][a-z\d+.-]*:/i.test(candidate)) {
    return null;
  }

  const path = candidate.replace(/^\/+/, "");
  const base = path.startsWith("storage/") ? mediaBaseUrlFromApiBase(baseUrl) : publicAssetBaseUrl();

  return `${base}/${path}`;
}

export function selectMediaUrl(asset, { baseUrl = publicApiBaseUrl(), variant = "card", preferred } = {}) {
  if (!asset) {
    return null;
  }

  const responsiveUrls = asset.responsive_urls ?? {};
  const order = Array.isArray(preferred) && preferred.length > 0
    ? preferred
    : variantOrders[variant] ?? variantOrders.card;

  for (const key of order) {
    const url = normalizeMediaUrl(responsiveUrls[key], { baseUrl });

    if (url) {
      return url;
    }
  }

  return normalizeMediaUrl(asset.url, { baseUrl });
}
