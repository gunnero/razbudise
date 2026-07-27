export const defaultPublicSiteUrl = "http://localhost:3001";
export const defaultApiBaseUrl = "http://localhost:8001/api";

export function normalizedBaseUrl(value, fallback = defaultPublicSiteUrl) {
  const candidate = typeof value === "string" && value.trim() !== "" ? value.trim() : fallback;

  return candidate.replace(/\/+$/, "");
}

export function publicSiteUrl(value = process.env.NEXT_PUBLIC_SITE_URL) {
  return normalizedBaseUrl(value, defaultPublicSiteUrl);
}

export function apiBaseUrl({
  internal = process.env.INTERNAL_API_BASE_URL,
  publicBase = process.env.NEXT_PUBLIC_API_BASE_URL,
} = {}) {
  return normalizedBaseUrl(internal || publicBase, defaultApiBaseUrl);
}

function isStagingHost(siteUrl) {
  try {
    const hostname = new URL(normalizedBaseUrl(siteUrl, defaultPublicSiteUrl)).hostname.toLowerCase();

    return hostname === "preview.example.test";
  } catch {
    return false;
  }
}

export function indexingDisabled(
  value = process.env.NEXT_PUBLIC_INDEXING_DISABLED,
  siteUrl = process.env.NEXT_PUBLIC_SITE_URL,
) {
  return ["1", "true", "yes", "on"].includes(String(value || "").trim().toLowerCase()) || isStagingHost(siteUrl);
}

export function gaMeasurementId(value = process.env.NEXT_PUBLIC_GA_MEASUREMENT_ID) {
  const candidate = String(value || "").trim().toUpperCase();

  return /^G-[A-Z0-9]+$/.test(candidate) ? candidate : null;
}

export function absolutePublicUrl(path, baseUrl = publicSiteUrl()) {
  if (!path) {
    return baseUrl;
  }

  if (/^https?:\/\//i.test(path)) {
    return path;
  }

  return `${normalizedBaseUrl(baseUrl)}${path.startsWith("/") ? path : `/${path}`}`;
}

function canonicalUrl(value, baseUrl) {
  const url = new URL(value || "/", `${normalizedBaseUrl(baseUrl)}/`);

  url.pathname = url.pathname.replace(/\/{2,}/g, "/");
  url.hash = "";

  for (const key of [...url.searchParams.keys()]) {
    if (key !== "page") {
      url.searchParams.delete(key);
    }
  }

  const page = Number(url.searchParams.get("page") ?? 1);

  if (!Number.isFinite(page) || page <= 1) {
    url.searchParams.delete("page");
  } else {
    url.searchParams.set("page", String(Math.floor(page)));
  }

  return url.toString();
}

export function canonicalUrlForPath(path, explicitCanonical = null, baseUrl = publicSiteUrl()) {
  return canonicalUrl(explicitCanonical || path, baseUrl);
}
