"use client";

import { useEffect } from "react";

export function trackAdvertisingEvent(name: string, parameters: Record<string, string | number | boolean | null> = {}) {
  window.gtag?.("event", name, parameters);
}

export function AdvertisingAnalytics() {
  useEffect(() => {
    function handleClick(event: MouseEvent) {
      const target = event.target instanceof Element ? event.target.closest<HTMLElement>("[data-ad-event]") : null;
      const name = target?.dataset.adEvent;
      if (!name) return;

      trackAdvertisingEvent(name, {
        campaign_goal: target.dataset.adGoal ?? null,
        package_key: target.dataset.adPackage ?? null,
        placement_key: target.dataset.adPlacement ?? null,
      });
    }

    document.addEventListener("click", handleClick);
    return () => document.removeEventListener("click", handleClick);
  }, []);

  return null;
}
