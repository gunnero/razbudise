import { adDeviceFromRequestHeaders as adDeviceFromRequestHeadersBase } from "./device.mjs";

export type AdDevice = "desktop" | "tablet" | "mobile";

export const adDeviceFromRequestHeaders = adDeviceFromRequestHeadersBase as (input?: {
  mobileHint?: string | null;
  userAgent?: string | null;
}) => AdDevice;
