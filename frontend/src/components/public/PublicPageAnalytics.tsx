"use client";

import { usePathname } from "next/navigation";
import { useEffect } from "react";
import { articleClickEventForContext, articleSlugFromHref, editorialContextForPathname, rememberArticleEntryContext, sendEditorialAnalyticsEvent } from "@/lib/analytics/editorialTracking";

export function trackPublicPageEvent(name: string, parameters: Record<string, string | number | boolean | null> = {}) {
  window.gtag?.("event", name, parameters);
}

export function PublicPageAnalytics() {
  const pathname = usePathname();
  useEffect(() => {
    const context = editorialContextForPathname(pathname);
    function handleClick(event: MouseEvent) {
      const element = event.target instanceof Element ? event.target : null;
      const tagged = element?.closest<HTMLElement>("[data-public-event]");
      const name = tagged?.dataset.publicEvent;
      if (name) trackPublicPageEvent(name, { content_key: tagged?.dataset.contentKey ?? null, purpose: tagged?.dataset.purpose ?? null });
      if (context === "article") return;
      const anchor = element?.closest<HTMLAnchorElement>("a[href]");
      if (!anchor) {
        if (name) void sendEditorialAnalyticsEvent({ event_type: "internal_cta_click", context_page: context, content_key: tagged?.dataset.contentKey ?? name });
        return;
      }
      const target = new URL(anchor.href, window.location.origin);
      if (target.pathname.startsWith("/profil/") || target.pathname.startsWith("/avtor/")) {
        void sendEditorialAnalyticsEvent({ event_type: "author_profile_click", context_page: context, target_url: target.href, content_key: tagged?.dataset.contentKey });
        return;
      }
      const articleSlug = articleSlugFromHref(anchor.href, window.location.origin);
      const eventType = articleClickEventForContext(context);
      if (articleSlug && eventType) {
        rememberArticleEntryContext(articleSlug, context);
        void sendEditorialAnalyticsEvent({ event_type: eventType, article_slug: articleSlug, context_page: context, target_url: anchor.href });
      } else if (target.origin !== window.location.origin) {
        void sendEditorialAnalyticsEvent({ event_type: name?.includes("source") ? "source_link_click" : "external_link_click", context_page: "external", target_url: target.href, content_key: tagged?.dataset.contentKey });
      } else if (name) {
        void sendEditorialAnalyticsEvent({ event_type: "internal_cta_click", context_page: context, target_url: target.href, content_key: tagged?.dataset.contentKey ?? name });
      }
    }
    function handleSubmit(event: SubmitEvent) {
      const form = event.target instanceof Element ? event.target.closest("form") : null;
      if (form?.closest(".newsletter-signup")) void sendEditorialAnalyticsEvent({ event_type: "newsletter_signup_click", context_page: "newsletter", content_key: "footer" });
    }
    document.addEventListener("click", handleClick);
    document.addEventListener("submit", handleSubmit);
    return () => { document.removeEventListener("click", handleClick); document.removeEventListener("submit", handleSubmit); };
  }, [pathname]);
  return null;
}
