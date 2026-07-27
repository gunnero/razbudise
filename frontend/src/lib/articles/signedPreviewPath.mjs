const SIGNED_PREVIEW_PATH = /^\/api\/preview\/articles\/([1-9]\d*)$/;

/**
 * Convert Laravel's relative signed preview URL into a path relative to the
 * configured API base. The production API base already ends in `/api`, while
 * `temporarySignedRoute()` includes that prefix in the generated URL.
 *
 * Keeping this parser strict prevents a preview query parameter from becoming
 * a server-side request to an arbitrary URL or an unrelated API endpoint.
 */
export function signedPreviewApiPath(value) {
  if (typeof value !== "string" || value === "" || !value.startsWith("/") || value.startsWith("//")) {
    return null;
  }

  let url;

  try {
    url = new URL(value, "https://preview.invalid");
  } catch {
    return null;
  }

  const match = url.pathname.match(SIGNED_PREVIEW_PATH);
  const expires = url.searchParams.get("expires");
  const signature = url.searchParams.get("signature");

  if (!match || !/^\d+$/.test(expires ?? "") || !/^[a-f0-9]{64}$/i.test(signature ?? "")) {
    return null;
  }

  return `/preview/articles/${match[1]}${url.search}`;
}
