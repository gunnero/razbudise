import { readPrivacyConsent } from "@/lib/privacy/consent";

export type EditorialEventType =
  | "engaged_session" | "scroll_depth" | "completion" | "outbound_click"
  | "social_share" | "homepage_article_click" | "category_article_click"
  | "author_article_click" | "search_article_click" | "related_article_click"
  | "author_profile_click" | "source_link_click" | "internal_cta_click"
  | "newsletter_signup_click" | "external_link_click";

export type EditorialContext = "article" | "homepage" | "category_page" | "author_page" | "internal_search" | "related_article" | "newsletter" | "external";

const visitorStorageKey = "razbudise-analytics-visitor";
const sessionStorageKey = "razbudise-analytics-session";
const pendingContextStorageKey = "razbudise-analytics-pending-context";
const editorialStaffStorageKey = "razbudise-editorial-staff";
const categoryListingPaths = new Set(["/vesti", "/nastani", "/intervju", "/kolumni"]);
const reservedTopLevelPaths = new Set([
  "avtor", "avtori", "category", "cenovnik-za-reklamiranje", "impresum", "intervju",
  "kolumni", "kontakt", "nastani", "politika-za-privatnost", "profil", "reklami",
  "search", "tag", "tim", "uslovi-za-koristenje", "vesti", "za-nas",
]);

function token(storage: Storage, key: string): string {
  const existing = storage.getItem(key);
  if (existing && existing.length >= 16) return existing;
  const generated = window.crypto.randomUUID();
  storage.setItem(key, generated);
  return generated;
}

export function sanitizedTrackingUrl(value: string | null | undefined): string | null {
  if (!value) return null;
  try {
    const url = new URL(value, window.location.origin);
    return `${url.origin}${url.pathname}`;
  } catch {
    return null;
  }
}

export function editorialContextForPathname(pathname: string): EditorialContext {
  if (pathname === "/") return "homepage";
  if (pathname.startsWith("/category/") || categoryListingPaths.has(pathname)) return "category_page";
  if (pathname.startsWith("/profil/") || pathname.startsWith("/avtor/") || pathname === "/avtor" || pathname === "/avtori" || pathname.startsWith("/tim")) return "author_page";
  if (pathname === "/search" || pathname.startsWith("/search/")) return "internal_search";
  return "article";
}

export function rememberArticleEntryContext(slug: string, context: EditorialContext): void {
  if (typeof window === "undefined" || context === "article") return;
  window.sessionStorage.setItem(pendingContextStorageKey, JSON.stringify({ slug, context, expires: Date.now() + 120_000 }));
}

export function consumeArticleEntryContext(slug: string): EditorialContext {
  if (typeof window === "undefined") return "article";
  try {
    const stored = JSON.parse(window.sessionStorage.getItem(pendingContextStorageKey) ?? "null") as { slug?: string; context?: EditorialContext; expires?: number } | null;
    window.sessionStorage.removeItem(pendingContextStorageKey);
    if (stored?.slug === slug && (stored.expires ?? 0) >= Date.now() && stored.context) return stored.context;
    const referrer = document.referrer ? new URL(document.referrer) : null;
    if (referrer?.origin === window.location.origin) {
      const referrerContext = editorialContextForPathname(referrer.pathname);
      return referrerContext === "article" ? "related_article" : referrerContext;
    }
  } catch { /* malformed first-party state is ignored */ }
  return "article";
}

export function articleSlugFromHref(href: string, origin: string): string | null {
  try {
    const url = new URL(href, origin);
    if (url.origin !== origin) return null;
    const segments = url.pathname.split("/").filter(Boolean);
    if (segments.length !== 1 || reservedTopLevelPaths.has(segments[0])) return null;
    return decodeURIComponent(segments[0]);
  } catch {
    return null;
  }
}

export function articleClickEventForContext(context: EditorialContext): EditorialEventType | null {
  return ({
    homepage: "homepage_article_click",
    category_page: "category_article_click",
    author_page: "author_article_click",
    internal_search: "search_article_click",
    related_article: "related_article_click",
  } as Partial<Record<EditorialContext, EditorialEventType>>)[context] ?? null;
}

function campaignParameters() {
  const parameters = new URLSearchParams(window.location.search);
  return {
    utm_source: parameters.get("utm_source"),
    utm_medium: parameters.get("utm_medium"),
    utm_campaign: parameters.get("utm_campaign"),
  };
}

function consentPayload() {
  const consent = readPrivacyConsent();
  if (consent?.analytics !== true || window.localStorage.getItem(editorialStaffStorageKey) === "1") return null;
  return {
    analytics_consent: true,
    consent_version: consent.version,
    visitor_token: token(window.localStorage, visitorStorageKey),
    session_token: token(window.sessionStorage, sessionStorageKey),
    referrer: sanitizedTrackingUrl(document.referrer),
    ...campaignParameters(),
  };
}

export async function sendEditorialAnalyticsEvent(payload: {
  event_type: EditorialEventType;
  article_slug?: string | null;
  context_page?: EditorialContext;
  target_url?: string | null;
  engaged_ms?: number;
  scroll_depth?: 25 | 50 | 75 | 100;
  content_key?: string | null;
}): Promise<void> {
  const consent = consentPayload();
  if (!consent) return;
  const apiBase = (process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8001/api").replace(/\/+$/, "");
  await fetch(`${apiBase}/editorial-analytics/events`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ...payload, ...consent, target_url: sanitizedTrackingUrl(payload.target_url) }),
    keepalive: true,
  }).catch(() => undefined);
}

export function articleViewPayload(context: EditorialContext) {
  const consent = consentPayload();
  return consent ? { ...consent, context_page: context } : null;
}
