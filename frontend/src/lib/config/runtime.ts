import {
  absolutePublicUrl as absolutePublicUrlBase,
  apiBaseUrl as apiBaseUrlBase,
  canonicalUrlForPath as canonicalUrlForPathBase,
  defaultApiBaseUrl,
  defaultPublicSiteUrl,
  gaMeasurementId as gaMeasurementIdBase,
  indexingDisabled as indexingDisabledBase,
  normalizedBaseUrl as normalizedBaseUrlBase,
  publicSiteUrl as publicSiteUrlBase,
} from "./runtime.mjs";

export { defaultApiBaseUrl, defaultPublicSiteUrl };

export const normalizedBaseUrl = normalizedBaseUrlBase as (value?: string | null, fallback?: string) => string;

export const publicSiteUrl = publicSiteUrlBase as (value?: string | null) => string;

export const apiBaseUrl = apiBaseUrlBase as (source?: {
  internal?: string | null;
  publicBase?: string | null;
}) => string;

export const indexingDisabled = indexingDisabledBase as (value?: string | null, siteUrl?: string | null) => boolean;

export const gaMeasurementId = gaMeasurementIdBase as (value?: string | null) => string | null;

export const absolutePublicUrl = absolutePublicUrlBase as (path?: string | null, baseUrl?: string) => string;

export const canonicalUrlForPath = canonicalUrlForPathBase as (
  path: string,
  explicitCanonical?: string | null,
  baseUrl?: string,
) => string;
