"use client";

import { useEffect } from "react";
import { privacyConsentChangedEvent, type PrivacyConsent } from "@/lib/privacy/consent";
import { articleSlugFromHref, articleViewPayload, consumeArticleEntryContext, sendEditorialAnalyticsEvent } from "@/lib/analytics/editorialTracking";

export function ArticleViewTracker({ slug }: { slug: string }) {
  useEffect(() => {
    let viewSent = false;
    let completedSent = false;
    const scrollMilestones = new Set<number>();
    let activeMs = 0;
    let visibleSince: number | null = document.visibilityState === "visible" ? Date.now() : null;
    const entryContext = consumeArticleEntryContext(slug);
    const apiBase = (process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8001/api").replace(/\/+$/, "");

    const trackView = (event?: Event) => {
      const detail = (event as CustomEvent<PrivacyConsent> | undefined)?.detail;
      if (viewSent || (detail && detail.analytics !== true)) return;
      const payload = articleViewPayload(entryContext);
      if (!payload) return;
      viewSent = true;
      void fetch(`${apiBase}/articles/${encodeURIComponent(slug)}/view`, {
        body: JSON.stringify(payload), headers: { "Content-Type": "application/json" }, keepalive: true, method: "POST",
      }).catch(() => undefined);
    };

    const checkpointEngagement = () => {
      if (visibleSince !== null) {
        activeMs += Date.now() - visibleSince;
        visibleSince = null;
      }
      if (viewSent && activeMs >= 10_000) {
        void sendEditorialAnalyticsEvent({ event_type: "engaged_session", article_slug: slug, context_page: entryContext, engaged_ms: activeMs });
      }
    };

    const handleVisibility = () => {
      if (document.visibilityState === "hidden") checkpointEngagement();
      else if (visibleSince === null) visibleSince = Date.now();
    };

    const trackScroll = () => {
      if (!viewSent) return;
      const readingFrame = document.querySelector<HTMLElement>(".article-reading-frame");
      if (!readingFrame) return;
      const frameTop = window.scrollY + readingFrame.getBoundingClientRect().top;
      const progressed = window.scrollY + window.innerHeight - frameTop;
      const percentage = Math.max(0, Math.min(100, Math.round((progressed / Math.max(1, readingFrame.scrollHeight)) * 100)));
      for (const milestone of [25, 50, 75, 100] as const) {
        if (percentage >= milestone && !scrollMilestones.has(milestone)) {
          scrollMilestones.add(milestone);
          void sendEditorialAnalyticsEvent({ event_type: "scroll_depth", article_slug: slug, context_page: entryContext, scroll_depth: milestone });
        }
      }
      if (percentage >= 90 && !completedSent) {
        completedSent = true;
        void sendEditorialAnalyticsEvent({ event_type: "completion", article_slug: slug, context_page: entryContext });
      }
    };

    const trackClick = (event: MouseEvent) => {
      const anchor = event.target instanceof Element ? event.target.closest<HTMLAnchorElement>("a[href]") : null;
      const articleRoot = document.querySelector<HTMLElement>(".article-detail");
      if (!anchor || !articleRoot?.contains(anchor)) return;
      const target = new URL(anchor.href, window.location.origin);
      const publicEvent = anchor.closest<HTMLElement>("[data-public-event]")?.dataset.publicEvent ?? "";
      if (/source|booking/.test(publicEvent)) {
        void sendEditorialAnalyticsEvent({ event_type: "source_link_click", article_slug: slug, context_page: "article", target_url: target.href });
        return;
      }
      if (/author_profile/.test(publicEvent) || target.pathname.startsWith("/profil/") || target.pathname.startsWith("/avtor/")) {
        void sendEditorialAnalyticsEvent({ event_type: "author_profile_click", article_slug: slug, context_page: "article", target_url: target.href });
        return;
      }
      const targetSlug = articleSlugFromHref(target.href, window.location.origin);
      if (targetSlug && targetSlug !== slug) {
        void sendEditorialAnalyticsEvent({ event_type: "related_article_click", article_slug: targetSlug, context_page: "related_article", target_url: target.href, content_key: slug });
        return;
      }
      if (target.origin !== window.location.origin) {
        void sendEditorialAnalyticsEvent({ event_type: publicEvent.includes("share") ? "social_share" : "external_link_click", article_slug: slug, context_page: "external", target_url: target.href });
      }
    };

    trackView();
    window.addEventListener(privacyConsentChangedEvent, trackView);
    window.addEventListener("scroll", trackScroll, { passive: true });
    window.addEventListener("pagehide", checkpointEngagement);
    document.addEventListener("click", trackClick);
    document.addEventListener("visibilitychange", handleVisibility);
    return () => {
      checkpointEngagement();
      window.removeEventListener(privacyConsentChangedEvent, trackView);
      window.removeEventListener("scroll", trackScroll);
      window.removeEventListener("pagehide", checkpointEngagement);
      document.removeEventListener("click", trackClick);
      document.removeEventListener("visibilitychange", handleVisibility);
    };
  }, [slug]);
  return null;
}
