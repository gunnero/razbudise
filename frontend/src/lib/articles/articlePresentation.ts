import {
  articleBadge as articleBadgeBase,
  articleBodyClassName as articleBodyClassNameBase,
  articleHeroMetaItems as articleHeroMetaItemsBase,
  articleHeroMetaParts as articleHeroMetaPartsBase,
  articleImagePresentation as articleImagePresentationBase,
  articleMetaParts as articleMetaPartsBase,
  archiveResultsSummary as archiveResultsSummaryBase,
  formatArticleDate as formatArticleDateBase,
  relatedArticlesHeading as relatedArticlesHeadingBase,
  searchFilterUrl as searchFilterUrlBase,
  searchResultsHeading as searchResultsHeadingBase,
  sponsoredCampaignRangeLabel as sponsoredCampaignRangeLabelBase,
} from "./articlePresentation.mjs";
import type { ArticleCardItem, MediaAsset } from "@/lib/api/client";
import type { MediaUrlOptions } from "@/lib/media/mediaUrls";

export const articleBadge = articleBadgeBase as (
  article: Pick<ArticleCardItem, "is_sponsored" | "sponsored_label" | "type"> | null | undefined,
) => string | null;

export const formatArticleDate = formatArticleDateBase as (value: string | null | undefined) => string | null;

export const articleMetaParts = articleMetaPartsBase as (
  article: Pick<ArticleCardItem, "published_at" | "reading_time_minutes"> | null | undefined,
) => string[];

export const articleHeroMetaParts = articleHeroMetaPartsBase as (
  article:
    | (Pick<ArticleCardItem, "published_at" | "reading_time_minutes"> & {
        author?: { name?: string | null; url?: string | null } | null;
        category?: { name?: string | null; url?: string | null } | null;
      })
    | null
    | undefined,
) => string[];

export const articleHeroMetaItems = articleHeroMetaItemsBase as (
  article:
    | (Pick<ArticleCardItem, "published_at" | "reading_time_minutes"> & {
        author?: { name?: string | null; url?: string | null } | null;
        category?: { name?: string | null; url?: string | null } | null;
      })
    | null
    | undefined,
) => Array<{
  href: string | null;
  key: "author" | "category" | "published_at" | "reading_time";
  label: string;
}>;

export const articleImagePresentation = articleImagePresentationBase as (
  article:
    | {
        title?: string | null;
        featured_image?: (Pick<MediaAsset, "url" | "responsive_urls" | "alt_text" | "caption" | "credit" | "focal_point">) | null;
      }
    | null
    | undefined,
  options?: MediaUrlOptions,
) => {
  hasImage: boolean;
  url: string | null;
  alt: string;
  caption: string | null;
  focalPointStyle: { objectPosition: string } | undefined;
  placeholderLabel: string | null;
};

export const articleBodyClassName = articleBodyClassNameBase as (
  article:
    | (Record<string, unknown> & {
        blocks?: unknown[] | null;
        body_html?: string | null;
        old_wordpress_id?: number | null;
        old_url?: string | null;
      })
    | null
    | undefined,
) => string;

export const relatedArticlesHeading = relatedArticlesHeadingBase as (count: number) => string | null;

export const searchResultsHeading = searchResultsHeadingBase as (query: string, total: number) => string;

export const archiveResultsSummary = archiveResultsSummaryBase as (label: string, total: number) => string;

export const searchFilterUrl = searchFilterUrlBase as (state: {
  author?: string | null;
  category?: string | null;
  page?: number | string | null;
  published_from?: string | null;
  published_to?: string | null;
  query?: string | null;
  sponsored?: boolean | string | null;
  tag?: string | null;
  type?: string | null;
}) => string;

export const sponsoredCampaignRangeLabel = sponsoredCampaignRangeLabelBase as (
  startAt?: string | null,
  endAt?: string | null,
) => string | null;
