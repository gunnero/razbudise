import {
  approvedClientLogos as approvedClientLogosBase,
  metricsReportingPeriod as metricsReportingPeriodBase,
  supportedDevicesLabel as supportedDevicesLabelBase,
  verifiedAudienceMetrics as verifiedAudienceMetricsBase,
} from "./advertisingPagePresentation.mjs";
import type { PublicSiteSettings } from "@/lib/api/client";
import type { PublicAdProduct } from "@/lib/ads/mediaKitPresentation";

export type AudienceMetric = { key: string; label: string; value: string };
export type ApprovedClientLogo = { name: string; path: string };

export const verifiedAudienceMetrics = verifiedAudienceMetricsBase as (settings: PublicSiteSettings | null | undefined) => AudienceMetric[];
export const metricsReportingPeriod = metricsReportingPeriodBase as (settings: PublicSiteSettings | null | undefined) => string | null;
export const approvedClientLogos = approvedClientLogosBase as (settings: PublicSiteSettings | null | undefined) => ApprovedClientLogo[];
export const supportedDevicesLabel = supportedDevicesLabelBase as (products: PublicAdProduct[]) => string;
