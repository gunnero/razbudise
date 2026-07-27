import type { MetadataRoute } from "next";
import {
  buildRobotsRules as buildRobotsRulesBase,
  buildSitemapEntries as buildSitemapEntriesBase,
  fetchAllSitemapItems as fetchAllSitemapItemsBase,
  publicUrl as publicUrlBase,
} from "./discovery.mjs";
import type { ArticleCardItem, AuthorListItem, Category, PaginatedResponse, Tag } from "@/lib/api/client";
import { publicSiteUrl } from "@/lib/config/runtime";

type SitemapSource = {
  siteUrl: string;
  articles?: Array<Pick<ArticleCardItem, "updated_at" | "url" | "published_at" | "type">>;
  events?: Array<Pick<ArticleCardItem, "updated_at" | "url" | "published_at" | "type">>;
  categories?: Array<Pick<Category, "updated_at" | "url">>;
  tags?: Array<Pick<Tag, "updated_at" | "url">>;
  authors?: Array<Pick<AuthorListItem, "updated_at" | "url">>;
};

const buildSitemapEntriesBaseTyped = buildSitemapEntriesBase as (
  source: SitemapSource,
  disableIndexing?: boolean,
) => MetadataRoute.Sitemap;

const buildRobotsRulesBaseTyped = buildRobotsRulesBase as (
  baseUrl: string,
  disableIndexing?: boolean,
) => MetadataRoute.Robots;

const publicUrlBaseTyped = publicUrlBase as (baseUrl: string, path: string) => string;

type SitemapPageFetcher<T> = (page: number, perPage: number) => Promise<PaginatedResponse<T>>;

const fetchAllSitemapItemsBaseTyped = fetchAllSitemapItemsBase as <T>(
  fetchPage: SitemapPageFetcher<T>,
  perPage?: number,
) => Promise<T[]>;

export function siteUrl() {
  return publicSiteUrl();
}

export function publicUrl(baseUrl: string, path: string) {
  return publicUrlBaseTyped(baseUrl, path);
}

export function buildSitemapEntries(source: SitemapSource, disableIndexing = false): MetadataRoute.Sitemap {
  return buildSitemapEntriesBaseTyped(source, disableIndexing);
}

export function fetchAllSitemapItems<T>(fetchPage: SitemapPageFetcher<T>, perPage = 100): Promise<T[]> {
  return fetchAllSitemapItemsBaseTyped(fetchPage, perPage);
}

export function buildRobotsRules(baseUrl: string, disableIndexing = false): MetadataRoute.Robots {
  return buildRobotsRulesBaseTyped(baseUrl, disableIndexing);
}
