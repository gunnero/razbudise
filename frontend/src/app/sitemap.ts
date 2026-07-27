import type { MetadataRoute } from "next";
import { getArticles, getAuthors, getCategories, getEvents, getTags } from "@/lib/api/client";
import { indexingDisabled } from "@/lib/config/runtime";
import { buildSitemapEntries, fetchAllSitemapItems, siteUrl } from "@/lib/seo/discovery";

// Production releases are built before the final CMS database is promoted.
// Generate discovery data at request time so the build cannot freeze an empty
// or staging sitemap into the release artifact.
export const dynamic = "force-dynamic";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  if (indexingDisabled()) {
    return [];
  }

  const [articles, events, categories, tags, authors] = await Promise.all([
    fetchAllSitemapItems((page, perPage) => getArticles(page, perPage)),
    fetchAllSitemapItems((page, perPage) => getEvents(page, perPage)),
    fetchAllSitemapItems((page, perPage) => getCategories(page, perPage)),
    fetchAllSitemapItems((page, perPage) => getTags(page, perPage)),
    fetchAllSitemapItems((page, perPage) => getAuthors(page, perPage)),
  ]);

  return buildSitemapEntries(
    {
      siteUrl: siteUrl(),
      articles,
      events,
      categories,
      tags,
      authors,
    },
    indexingDisabled(),
  );
}
