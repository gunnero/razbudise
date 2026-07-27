import assert from "node:assert/strict";
import test from "node:test";

import {
  fallbackPublicSiteSettings,
  publicSiteSettingsDefaults,
  settingString,
  metadataTitleWithoutBrand,
} from "./siteSettings.mjs";

test("settingString reads scalar and array-backed public setting values", () => {
  assert.equal(settingString({ site_name: ["Разбуди се"] }, "site_name", "Fallback"), "Разбуди се");
  assert.equal(settingString({ site_name: "RazbudiSe" }, "site_name", "Fallback"), "RazbudiSe");
});

test("metadataTitleWithoutBrand removes one terminal brand suffix", () => {
  assert.equal(metadataTitleWithoutBrand("Живот - Разбуди се", "Разбуди се"), "Живот");
  assert.equal(metadataTitleWithoutBrand("Живот | RazbudiSe", "Разбуди се"), "Живот");
  assert.equal(metadataTitleWithoutBrand("Разбуди се со добра приказна", "Разбуди се"), "Разбуди се со добра приказна");
});

test("publicSiteSettingsDefaults maps API settings with safe fallbacks", () => {
  const originalSiteUrl = process.env.NEXT_PUBLIC_SITE_URL;
  process.env.NEXT_PUBLIC_SITE_URL = "https://frontend.example.test";

  const defaults = publicSiteSettingsDefaults({
    site_name: ["Разбуди се"],
    site_url: ["https://razbudise.mk"],
    default_meta_title: ["Разбуди се"],
    default_meta_description: ["Македонски портал"],
    default_og_image: ["/storage/og.jpg"],
  });

  assert.deepEqual(defaults, {
    siteName: "Разбуди се",
    siteUrl: "https://frontend.example.test",
    defaultMetaTitle: "Разбуди се",
    defaultMetaDescription: "Македонски портал",
    defaultOgImage: "/storage/og.jpg",
  });

  if (originalSiteUrl === undefined) {
    delete process.env.NEXT_PUBLIC_SITE_URL;
  } else {
    process.env.NEXT_PUBLIC_SITE_URL = originalSiteUrl;
  }
});

test("publicSiteSettingsDefaults falls back when API settings are missing", () => {
  const defaults = publicSiteSettingsDefaults(null);

  assert.equal(defaults.siteName, "RazbudiSe");
  assert.equal(
    defaults.siteUrl,
    process.env.NEXT_PUBLIC_SITE_URL ?? fallbackPublicSiteSettings.siteUrl,
  );
  assert.equal(defaults.defaultMetaTitle, "RazbudiSe");
  assert.equal(
    defaults.defaultMetaDescription,
    "Разбуди се е македонски портал за живот, култура, хедонизам, интервјуа, настани и локални приказни.",
  );
});
