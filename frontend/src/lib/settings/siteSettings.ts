import {
  publicSiteSettingsDefaults as publicSiteSettingsDefaultsBase,
  settingString as settingStringBase,
  metadataTitleWithoutBrand as metadataTitleWithoutBrandBase,
} from "./siteSettings.mjs";
import type { PublicSiteSettings } from "@/lib/api/client";

export type PublicSiteSettingsDefaults = {
  siteName: string;
  siteUrl: string;
  defaultMetaTitle: string;
  defaultMetaDescription: string;
  defaultOgImage: string | null;
};

export const settingString = settingStringBase as (
  settings: PublicSiteSettings | null | undefined,
  key: keyof PublicSiteSettings,
  fallback?: string | null,
) => string | null;

export const publicSiteSettingsDefaults = publicSiteSettingsDefaultsBase as (
  settings: PublicSiteSettings | null | undefined,
) => PublicSiteSettingsDefaults;

export const metadataTitleWithoutBrand = metadataTitleWithoutBrandBase as (
  value: string,
  siteName: string,
) => string;
