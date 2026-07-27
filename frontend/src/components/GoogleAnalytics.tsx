"use client";

import Script from "next/script";
import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import { privacyConsentChangedEvent, readPrivacyConsent, type PrivacyConsent } from "@/lib/privacy/consent";

declare global {
  interface Window {
    dataLayer?: unknown[];
    gtag?: (...args: unknown[]) => void;
  }
}

export function GoogleAnalytics({ measurementId }: { measurementId: string }) {
  const pathname = usePathname();
  const [enabled, setEnabled] = useState(false);

  useEffect(() => {
    const update = (event?: Event) => {
      const detail = (event as CustomEvent<PrivacyConsent> | undefined)?.detail;
      const analyticsAllowed = (detail ?? readPrivacyConsent())?.analytics === true;
      (window as unknown as Record<string, unknown>)[`ga-disable-${measurementId}`] = !analyticsAllowed;
      if (!analyticsAllowed) {
        for (const cookie of document.cookie.split(";")) {
          const name = cookie.split("=")[0]?.trim();
          if (name === "_gid" || name === "_gat" || name?.startsWith("_ga")) {
            document.cookie = `${name}=; Max-Age=0; Path=/; SameSite=Lax`;
          }
        }
      }
      setEnabled(analyticsAllowed);
    };
    update();
    window.addEventListener(privacyConsentChangedEvent, update);
    return () => window.removeEventListener(privacyConsentChangedEvent, update);
  }, [measurementId]);

  useEffect(() => {
    if (!enabled) return;
    window.gtag?.("event", "page_view", {
      page_location: window.location.href,
      page_path: pathname,
      page_title: document.title,
    });
  }, [enabled, pathname]);

  if (!enabled) return null;

  return (
    <>
      <Script
        src={`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`}
        strategy="afterInteractive"
      />
      <Script id="google-analytics" strategy="afterInteractive">
        {`window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}window.gtag=gtag;gtag('js',new Date());gtag('config','${measurementId}',{send_page_view:false});`}
      </Script>
    </>
  );
}
