const reservedTopLevelPaths = new Set([
  "avtor", "avtori", "category", "cenovnik-za-reklamiranje", "impresum", "intervju",
  "kolumni", "kontakt", "nastani", "politika-za-privatnost", "profil", "reklami",
  "search", "tag", "tim", "uslovi-za-koristenje", "vesti", "za-nas",
]);
const categoryListingPaths = new Set(["/vesti", "/nastani", "/intervju", "/kolumni"]);
export function editorialContextForPathname(pathname) {
  if (pathname === "/") return "homepage";
  if (pathname.startsWith("/category/") || categoryListingPaths.has(pathname)) return "category_page";
  if (pathname.startsWith("/profil/") || pathname.startsWith("/avtor/") || pathname === "/avtor" || pathname === "/avtori" || pathname.startsWith("/tim")) return "author_page";
  if (pathname === "/search" || pathname.startsWith("/search/")) return "internal_search";
  return "article";
}
export function articleSlugFromHref(href, origin) {
  try {
    const url = new URL(href, origin);
    if (url.origin !== origin) return null;
    const segments = url.pathname.split("/").filter(Boolean);
    if (segments.length !== 1 || reservedTopLevelPaths.has(segments[0])) return null;
    return decodeURIComponent(segments[0]);
  } catch { return null; }
}
export function articleClickEventForContext(context) {
  return { homepage: "homepage_article_click", category_page: "category_article_click", author_page: "author_article_click", internal_search: "search_article_click", related_article: "related_article_click" }[context] ?? null;
}
