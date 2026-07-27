const DEFAULT_SITEMAP_PAGE_SIZE = 100;

function cleanPath(path) {
  const value = String(path || "/").trim();
  const suffixStart = value.search(/[?#]/);
  const pathname = suffixStart === -1 ? value : value.slice(0, suffixStart);
  const suffix = suffixStart === -1 ? "" : value.slice(suffixStart);
  const normalizedPathname = pathname.replace(/^\/+/, "").replace(/\/{2,}/g, "/");

  return normalizedPathname ? `/${normalizedPathname}${suffix}` : `/${suffix}`;
}

function localCanonicalPath(siteUrl, path) {
  if (!path || typeof path !== "string") {
    return null;
  }

  try {
    const base = new URL(`${siteUrl.replace(/\/+$/, "")}/`);
    const url = new URL(path, base);

    if (/^https?:\/\//i.test(path) && url.origin !== base.origin) {
      return null;
    }

    return cleanPath(url.pathname);
  } catch {
    return null;
  }
}

export function publicUrl(siteUrl, path) {
  const base = siteUrl.replace(/\/+$/, "");

  return `${base}${cleanPath(path)}`;
}

export async function fetchAllSitemapItems(fetchPage, perPage = DEFAULT_SITEMAP_PAGE_SIZE) {
  const items = [];
  let page = 1;

  while (true) {
    const response = await fetchPage(page, perPage);

    if (!response) {
      break;
    }

    if (Array.isArray(response.data)) {
      items.push(...response.data);
    }

    const reportedCurrentPage = Number(response.meta?.current_page ?? page);
    const currentPage = Number.isFinite(reportedCurrentPage) ? reportedCurrentPage : page;
    const lastPage = Number(response.meta?.last_page);

    if (Number.isFinite(lastPage)) {
      if (currentPage >= lastPage) {
        break;
      }

      page = Math.max(page + 1, currentPage + 1);
      continue;
    }

    if (!response.links?.next) {
      break;
    }

    page += 1;
  }

  return items;
}

export function buildSitemapEntries(
  { siteUrl, articles = [], events = [], categories = [], tags = [], authors = [] },
  disableIndexing = false,
) {
  if (disableIndexing) {
    return [];
  }

  const staticEntries = [
    { path: "/", changeFrequency: "hourly", priority: 1 },
    { path: "/tim", changeFrequency: "weekly", priority: 0.7 },
    { path: "/nastani", changeFrequency: "daily", priority: 0.8 },
    { path: "/vesti", changeFrequency: "daily", priority: 0.8 },
    { path: "/intervju", changeFrequency: "weekly", priority: 0.75 },
    { path: "/kolumni", changeFrequency: "weekly", priority: 0.75 },
    { path: "/za-nas", changeFrequency: "monthly", priority: 0.45 },
    { path: "/kontakt", changeFrequency: "monthly", priority: 0.45 },
    { path: "/impresum", changeFrequency: "monthly", priority: 0.35 },
    { path: "/uslovi-za-koristenje", changeFrequency: "monthly", priority: 0.35 },
    { path: "/politika-za-privatnost", changeFrequency: "monthly", priority: 0.35 },
  ].map((entry) => ({
    url: publicUrl(siteUrl, entry.path),
    changeFrequency: entry.changeFrequency,
    priority: entry.priority,
  }));

  const articleEntries = [...articles, ...events].flatMap((article) => {
    const path = localCanonicalPath(siteUrl, article.url);

    if (!path) {
      return [];
    }

    return [{
      url: publicUrl(siteUrl, path),
      lastModified: article.updated_at
        ? new Date(article.updated_at)
        : article.published_at
          ? new Date(article.published_at)
          : undefined,
      changeFrequency: article.type === "event" ? "daily" : "weekly",
      priority: article.type === "event" ? 0.75 : 0.8,
    }];
  });

  const taxonomyEntries = [...categories, ...tags, ...authors].flatMap((item) => {
    const path = localCanonicalPath(siteUrl, item.url);

    if (!path) {
      return [];
    }

    return [{
      url: publicUrl(siteUrl, path),
      lastModified: item.updated_at ? new Date(item.updated_at) : undefined,
      changeFrequency: "weekly",
      priority: 0.6,
    }];
  });

  const seenUrls = new Set();

  return [...staticEntries, ...articleEntries, ...taxonomyEntries].filter((entry) => {
    if (seenUrls.has(entry.url)) {
      return false;
    }

    seenUrls.add(entry.url);

    return true;
  });
}

export function buildRobotsRules(siteUrl, disableIndexing = false) {
  if (disableIndexing) {
    return {
      rules: {
        userAgent: "*",
        disallow: "/",
      },
      host: siteUrl.replace(/\/+$/, ""),
    };
  }

  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/backoffice", "/api", "/*?preview_token=*"],
    },
    sitemap: publicUrl(siteUrl, "/sitemap.xml"),
    host: siteUrl.replace(/\/+$/, ""),
  };
}
