import type { ArticleDetail, AuthorDetail } from "@/lib/api/client";
import {
  archivePageJsonLd as archivePageJsonLdBase,
  articleJsonLd as articleJsonLdBase,
  authorProfileJsonLd as authorProfileJsonLdBase,
} from "./jsonLd.mjs";

export const authorProfileJsonLd = authorProfileJsonLdBase as (
  author: AuthorDetail,
  canonicalUrl: string,
) => Record<string, unknown>;

export const articleJsonLd = articleJsonLdBase as (
  article: ArticleDetail,
  canonicalUrl: string,
) => Record<string, unknown>;

export const archivePageJsonLd = archivePageJsonLdBase as (input: {
  breadcrumbs: Array<{ name: string; url: string | null }>;
  canonicalUrl: string;
  description?: string | null;
  name: string;
}) => Record<string, unknown>;
