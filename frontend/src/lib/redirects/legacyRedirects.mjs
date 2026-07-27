export function shouldSkipRedirectLookup(pathname) {
  return (
    pathname === "/_next" ||
    pathname.startsWith("/_next/") ||
    pathname === "/api" ||
    pathname.startsWith("/api/") ||
    pathname === "/favicon.ico" ||
    pathname === "/robots.txt" ||
    pathname === "/sitemap.xml"
  );
}

export function requestCanonicalRedirect(pathname) {
  if (pathname === "/blog" || pathname === "/blog/") {
    return { pathname: "/", status: 301 };
  }

  if (pathname.length > 1 && pathname.endsWith("/")) {
    return { pathname: pathname.replace(/\/+$/, ""), status: 308 };
  }

  return null;
}

/**
 * @typedef {{ target_url: string, status_code: number }} ResolvedRedirect
 * @typedef {{ kind: "canonical", pathname: string, status: number } | { kind: "resolved", targetUrl: string, status: number }} RequestRedirectDecision
 *
 * @param {string} pathname
 * @param {ResolvedRedirect | null} resolvedRedirect
 * @returns {RequestRedirectDecision | null}
 */
export function requestRedirectDecision(pathname, resolvedRedirect = null) {
  const canonicalRedirect = requestCanonicalRedirect(pathname);

  if (canonicalRedirect?.status === 301) {
    return { kind: "canonical", ...canonicalRedirect };
  }

  if (resolvedRedirect) {
    return {
      kind: "resolved",
      status: resolvedRedirect.status_code,
      targetUrl: resolvedRedirect.target_url,
    };
  }

  return canonicalRedirect
    ? { kind: "canonical", ...canonicalRedirect }
    : null;
}

export function redirectLookupUrl(apiBaseUrl, requestUrl) {
  const baseUrl = apiBaseUrl.replace(/\/+$/, "");
  const url = new URL(requestUrl);

  return `${baseUrl}/redirects/resolve?url=${encodeURIComponent(url.pathname)}`;
}

export function redirectTargetUrl(requestUrl, targetUrl) {
  return new URL(targetUrl, requestUrl).toString();
}
