export const fallbackPublicSiteSettings = {
  siteName: "RazbudiSe",
  siteUrl: "http://localhost:3001",
  defaultMetaTitle: "RazbudiSe",
  defaultMetaDescription: "Разбуди се е македонски портал за живот, култура, хедонизам, интервјуа, настани и локални приказни.",
  defaultOgImage: null,
};

export function settingString(settings, key, fallback = null) {
  const value = settings?.[key];

  if (Array.isArray(value)) {
    return typeof value[0] === "string" && value[0].trim() !== "" ? value[0] : fallback;
  }

  if (typeof value === "string") {
    return value.trim() !== "" ? value : fallback;
  }

  return fallback;
}

export function publicSiteSettingsDefaults(settings) {
  const siteName = settingString(settings, "site_name", fallbackPublicSiteSettings.siteName);

  return {
    siteName,
    siteUrl: process.env.NEXT_PUBLIC_SITE_URL
      ?? fallbackPublicSiteSettings.siteUrl,
    defaultMetaTitle: settingString(settings, "default_meta_title", siteName),
    defaultMetaDescription: settingString(
      settings,
      "default_meta_description",
      fallbackPublicSiteSettings.defaultMetaDescription,
    ),
    defaultOgImage: settingString(settings, "default_og_image", fallbackPublicSiteSettings.defaultOgImage),
  };
}

export function metadataTitleWithoutBrand(value, siteName) {
  if (typeof value !== "string") {
    return value;
  }

  const escapedNames = [siteName, "Разбуди се", "RazbudiSe"]
    .filter((name) => typeof name === "string" && name.trim() !== "")
    .map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));

  if (escapedNames.length === 0) {
    return value.trim();
  }

  return value
    .replace(new RegExp(`\\s*(?:\\||-|–|—)\\s*(?:${escapedNames.join("|")})\\s*$`, "iu"), "")
    .trim();
}
