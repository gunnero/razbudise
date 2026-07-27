import type { AdPlacement } from "@/lib/api/client";
import {
  adCreativeDocument as adCreativeDocumentBase,
  adCreativeSandbox as adCreativeSandboxBase,
  safelyLoadAdPlacement as safelyLoadAdPlacementBase,
} from "./adTracking.mjs";

type ImpressionRequestInput = {
  articleId?: number;
  creativeId: number;
  deliveryToken: string;
  device?: string;
  impressionTrackingUrl?: string;
  zoneKey: string;
};

type AdPresentation = {
  cta: string;
  dimensions: string | null;
  rateLabel: string;
  zoneName: string;
};

export type AdCreativeStyleVars = {
  "--ad-aspect-ratio"?: string;
  "--ad-height"?: string;
  "--ad-width"?: string;
};

export const adCreativeSandbox = adCreativeSandboxBase as (creativeType: AdPlacement["type"]) => "allow-scripts" | "";
export const adCreativeDocument = adCreativeDocumentBase as (markup: string | null | undefined) => string;

export function safelyLoadAdPlacement<T>(loadPlacement: () => Promise<T>): Promise<T | null> {
  return safelyLoadAdPlacementBase(loadPlacement) as Promise<T | null>;
}

export function trackingUrl(apiBaseUrl: string, path?: string | null): string | null {
  if (!path) {
    return null;
  }

  if (/^https?:\/\//i.test(path)) {
    return path;
  }

  return `${apiBaseUrl.replace(/\/$/, "")}/${path.replace(/^\//, "")}`;
}

export function adClickHref(placement: AdPlacement, apiBaseUrl?: string): string {
  const trackingHref = trackingUrl(apiBaseUrl ?? "", placement.click_tracking_url);

  return trackingHref ?? placement.click_url ?? "#";
}

export function adSlotState(placement: Pick<AdPlacement, "is_fallback" | "name"> | null): {
  kind: "campaign" | "fallback" | "hidden";
  label: string | null;
} {
  if (!placement) {
    return {
      kind: "hidden",
      label: null,
    };
  }

  return {
    kind: placement.is_fallback ? "fallback" : "campaign",
    label: "Реклама",
  };
}

const zoneNames: Record<string, string> = {
  article_after_paragraph_3: "Статија после параграф 3",
  article_after_paragraph_7: "Статија после параграф 7",
  article_after_title: "Статија после наслов",
  article_before_related: "Статија пред поврзани",
  article_sidebar_sticky: "Статија sticky sidebar",
  article_sidebar_top: "Статија sidebar горе",
  author_page_top: "Авторска страница горе",
  category_in_feed: "Архива во фид",
  category_top: "Архива горна реклама",
  event_page_top: "Настани горна реклама",
  home_after_featured: "Почетна после главна приказна",
  home_in_feed_1: "Почетна во фид 1",
  home_in_feed_1_slot_2: "Почетна во фид 1 - слот 2",
  home_in_feed_1_slot_3: "Почетна во фид 1 - слот 3",
  home_in_feed_1_slot_4: "Почетна во фид 1 - слот 4",
  home_in_feed_2: "Почетна во фид 2",
  home_in_feed_2_slot_2: "Почетна во фид 2 - слот 2",
  home_in_feed_2_slot_3: "Почетна во фид 2 - слот 3",
  home_in_feed_2_slot_4: "Почетна во фид 2 - слот 4",
  home_in_feed_3: "Почетна во фид 3",
  home_in_feed_3_slot_2: "Почетна во фид 3 - слот 2",
  home_in_feed_3_slot_3: "Почетна во фид 3 - слот 3",
  home_in_feed_3_slot_4: "Почетна во фид 3 - слот 4",
  home_top_billboard: "Почетна горен билборд",
  article_sidebar_sticky_slot_2: "Статија sticky sidebar - слот 2",
  article_sidebar_top_slot_2: "Статија sidebar горе - слот 2",
  article_sidebar_top_slot_3: "Статија sidebar горе - слот 3",
  category_in_feed_slot_2: "Архива во фид - слот 2",
  category_in_feed_slot_3: "Архива во фид - слот 3",
  category_in_feed_slot_4: "Архива во фид - слот 4",
  mobile_sticky_bottom: "Мобилен sticky bottom",
  newsletter_inline: "Newsletter inline",
  newsletter_inline_slot_2: "Newsletter inline - слот 2",
  search_results_top: "Пребарување горе",
};

const zoneWeeklyRates: Record<string, number> = {
  article_after_paragraph_3: 7500,
  article_after_paragraph_7: 7500,
  article_after_title: 9000,
  article_before_related: 7500,
  article_sidebar_sticky: 8500,
  article_sidebar_top: 6500,
  author_page_top: 7000,
  category_in_feed: 6000,
  category_top: 9000,
  event_page_top: 7000,
  home_after_featured: 12000,
  home_in_feed_1: 7500,
  home_in_feed_1_slot_2: 7500,
  home_in_feed_1_slot_3: 7500,
  home_in_feed_1_slot_4: 7500,
  home_in_feed_2: 7500,
  home_in_feed_2_slot_2: 7500,
  home_in_feed_2_slot_3: 7500,
  home_in_feed_2_slot_4: 7500,
  home_in_feed_3: 7500,
  home_in_feed_3_slot_2: 7500,
  home_in_feed_3_slot_3: 7500,
  home_in_feed_3_slot_4: 7500,
  home_top_billboard: 18000,
  article_sidebar_sticky_slot_2: 8500,
  article_sidebar_top_slot_2: 6500,
  article_sidebar_top_slot_3: 6500,
  category_in_feed_slot_2: 6000,
  category_in_feed_slot_3: 6000,
  category_in_feed_slot_4: 6000,
  mobile_sticky_bottom: 4500,
  newsletter_inline: 5000,
  newsletter_inline_slot_2: 5000,
  search_results_top: 7000,
};

function formatDenars(value: number): string {
  return String(Math.round(value)).replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

export function adCreativeDimensions(placement: Pick<AdPlacement, "height" | "width"> | null): string | null {
  if (!placement?.width || !placement?.height) {
    return null;
  }

  return `${placement.width}x${placement.height}`;
}

export function adCreativeStyleVars(placement: Pick<AdPlacement, "height" | "width"> | null): AdCreativeStyleVars {
  if (!placement?.width || !placement?.height) {
    return {};
  }

  return {
    "--ad-aspect-ratio": `${placement.width} / ${placement.height}`,
    "--ad-height": `${placement.height}px`,
    "--ad-width": `${placement.width}px`,
  };
}

export function adDemoPresentation(placement: Pick<AdPlacement, "height" | "width"> | null, zoneKey: string): AdPresentation {
  const weeklyRate = zoneWeeklyRates[zoneKey] ?? 6000;

  return {
    cta: "Резервирај простор",
    dimensions: adCreativeDimensions(placement),
    rateLabel: `од ${formatDenars(weeklyRate)} ден. / недела`,
    zoneName: zoneNames[zoneKey] ?? "Рекламен простор",
  };
}

export function impressionRequest(
  apiBaseUrl: string,
  { articleId, creativeId, deliveryToken, device, impressionTrackingUrl, zoneKey }: ImpressionRequestInput,
): [string | null, RequestInit] {
  return [
    trackingUrl(apiBaseUrl, impressionTrackingUrl ?? "/ads/impression"),
    {
      method: "POST",
      cache: "no-store",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        delivery_token: deliveryToken,
        creative_id: creativeId,
        zone_key: zoneKey,
        ...(articleId ? { article_id: articleId } : {}),
        ...(device ? { device } : {}),
      }),
    },
  ];
}
