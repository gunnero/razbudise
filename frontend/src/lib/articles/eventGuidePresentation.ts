import {
  eventSchemaItems as eventSchemaItemsBase,
  guideEventDateLabel as guideEventDateLabelBase,
  guideLastVerifiedLabel as guideLastVerifiedLabelBase,
  guideQuickFacts as guideQuickFactsBase,
  isEventGuideArticle as isEventGuideArticleBase,
} from "./eventGuidePresentation.mjs";
import type { ArticleDetail } from "@/lib/api/client";

export const isEventGuideArticle = isEventGuideArticleBase as (article: ArticleDetail | null | undefined) => boolean;

export const guideQuickFacts = guideQuickFactsBase as (article: ArticleDetail | null | undefined) => string[];

export const guideEventDateLabel = guideEventDateLabelBase as (
  event: NonNullable<ArticleDetail["guide"]>["events"][number] | null | undefined,
) => string | null;

export const guideLastVerifiedLabel = guideLastVerifiedLabelBase as (value: string | null | undefined) => string | null;

export const eventSchemaItems = eventSchemaItemsBase as (
  article: ArticleDetail | null | undefined,
  canonicalUrl: string,
) => Array<Record<string, unknown>>;
