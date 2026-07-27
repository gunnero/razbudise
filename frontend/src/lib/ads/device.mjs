const tabletUserAgent = /ipad|tablet|kindle|silk|playbook|android(?!.*mobile)/i;
const mobileUserAgent = /iphone|ipod|windows phone|mobile|android/i;

export function adDeviceFromRequestHeaders({ mobileHint = null, userAgent = null } = {}) {
  const normalizedUserAgent = typeof userAgent === "string" ? userAgent : "";

  if (tabletUserAgent.test(normalizedUserAgent)) {
    return "tablet";
  }

  if (mobileHint === "?1" || mobileUserAgent.test(normalizedUserAgent)) {
    return "mobile";
  }

  return "desktop";
}
