import type { Metadata } from "next";
import { GoogleAnalytics } from "@/components/GoogleAnalytics";
import { MobileStickyAd } from "@/components/MobileStickyAd";
import { SkipLink } from "@/components/SkipLink";
import { SiteFooter } from "@/components/SiteFooter";
import { PrivacyPreferences } from "@/components/privacy/PrivacyPreferences";
import { PublicPageAnalytics } from "@/components/public/PublicPageAnalytics";
import { getPublicSiteSettings } from "@/lib/api/client";
import { gaMeasurementId, indexingDisabled } from "@/lib/config/runtime";
import { publicSiteSettingsDefaults } from "@/lib/settings/siteSettings";
import "./globals.css";

const themeInitScript = `
  (function () {
    try {
      var key = "razbudise-theme";
      var storedTheme = window.localStorage.getItem(key);
      var theme = storedTheme === "light" || storedTheme === "dark"
        ? storedTheme
        : (window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");

      document.documentElement.setAttribute("data-theme", theme);
      document.documentElement.style.colorScheme = theme;
    } catch (error) {
      document.documentElement.setAttribute("data-theme", "light");
      document.documentElement.style.colorScheme = "light";
    }
  })();
`;

export async function generateMetadata(): Promise<Metadata> {
  const settings = publicSiteSettingsDefaults(await getPublicSiteSettings());
  const noindex = indexingDisabled();

  return {
    title: {
      default: settings.defaultMetaTitle,
      template: `%s | ${settings.siteName}`,
    },
    description: settings.defaultMetaDescription,
    icons: {
      icon: [
        { url: "/favicon.ico" },
        { sizes: "300x300", type: "image/png", url: "/icon.png" },
      ],
      apple: [
        { sizes: "300x300", type: "image/png", url: "/apple-icon.png" },
      ],
    },
    metadataBase: new URL(settings.siteUrl),
    openGraph: {
      title: settings.defaultMetaTitle,
      description: settings.defaultMetaDescription,
      images: settings.defaultOgImage ? [settings.defaultOgImage] : undefined,
      siteName: settings.siteName,
      locale: "mk_MK",
      type: "website",
    },
    robots: noindex
      ? {
          index: false,
          follow: false,
          googleBot: {
            index: false,
            follow: false,
          },
        }
      : undefined,
  };
}

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const measurementId = gaMeasurementId();

  return (
    <html lang="mk" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeInitScript }} />
      </head>
      <body>
        <SkipLink />
        <PublicPageAnalytics />
        {children}
        <SiteFooter />
        <PrivacyPreferences />
        <MobileStickyAd />
        {measurementId ? <GoogleAnalytics measurementId={measurementId} /> : null}
      </body>
    </html>
  );
}
