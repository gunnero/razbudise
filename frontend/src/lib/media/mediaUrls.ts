import {
  mediaBaseUrlFromApiBase as mediaBaseUrlFromApiBaseBase,
  mediaFallbackLabel as mediaFallbackLabelBase,
  normalizeMediaUrl as normalizeMediaUrlBase,
  selectMediaUrl as selectMediaUrlBase,
} from "./mediaUrls.mjs";
import type { MediaAsset } from "@/lib/api/client";

export type MediaVariant = "card" | "lead" | "thumbnail" | "original";

export type MediaUrlOptions = {
  baseUrl?: string | null;
  preferred?: string[];
  variant?: MediaVariant;
};

export const mediaFallbackLabel = mediaFallbackLabelBase as string;

export const mediaBaseUrlFromApiBase = mediaBaseUrlFromApiBaseBase as (value?: string | null) => string;

export const normalizeMediaUrl = normalizeMediaUrlBase as (
  value: string | null | undefined,
  options?: Pick<MediaUrlOptions, "baseUrl">,
) => string | null;

export const selectMediaUrl = selectMediaUrlBase as (
  asset:
    | (Pick<MediaAsset, "url" | "responsive_urls"> & {
        responsive_urls?: MediaAsset["responsive_urls"];
      })
    | null
    | undefined,
  options?: MediaUrlOptions,
) => string | null;
