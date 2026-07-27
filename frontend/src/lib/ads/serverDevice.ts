import { headers } from "next/headers";

import { adDeviceFromRequestHeaders, type AdDevice } from "@/lib/ads/device";

export async function serverAdDevice(): Promise<AdDevice> {
  const requestHeaders = await headers();

  return adDeviceFromRequestHeaders({
    mobileHint: requestHeaders.get("sec-ch-ua-mobile"),
    userAgent: requestHeaders.get("user-agent"),
  });
}
