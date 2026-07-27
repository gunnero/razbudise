"use client";

import { useEffect } from "react";
import type { CSSProperties } from "react";

import type { AdPlacement } from "@/lib/api/client";
import {
  adClickHref,
  adCreativeDocument,
  adCreativeSandbox,
  adCreativeStyleVars,
  adDemoPresentation,
  impressionRequest,
} from "@/lib/ads/adTracking";
import type { AdDevice } from "@/lib/ads/device";
import { apiBaseUrl } from "@/lib/config/runtime";
import { selectMediaUrl } from "@/lib/media/mediaUrls";

type TrackedAdPlacementProps = {
  articleId?: number;
  device: AdDevice;
  placement: AdPlacement;
  zoneKey: string;
};

const publicApiBaseUrl = apiBaseUrl({
  internal: null,
  publicBase: process.env.NEXT_PUBLIC_API_BASE_URL,
});

export function TrackedAdPlacement({ articleId, device, placement, zoneKey }: TrackedAdPlacementProps) {
  useEffect(() => {
    const [url, init] = impressionRequest(publicApiBaseUrl, {
      articleId,
      creativeId: placement.id,
      deliveryToken: placement.delivery_token,
      device,
      impressionTrackingUrl: placement.impression_tracking_url,
      zoneKey,
    });

    if (!url) {
      return;
    }

    void fetch(url, init).catch(() => undefined);
  }, [articleId, device, placement.delivery_token, placement.id, placement.impression_tracking_url, zoneKey]);

  const href = adClickHref(placement, publicApiBaseUrl);
  const imageUrl = selectMediaUrl(placement.image, { variant: "original" });
  const presentation = adDemoPresentation(placement, zoneKey);
  const frameClassName = [
    "ad-creative-frame",
    placement.is_fallback ? "ad-creative-frame-fallback" : null,
    `ad-creative-frame-${placement.type}`,
  ]
    .filter(Boolean)
    .join(" ");
  const creativeStyle = adCreativeStyleVars(placement) as CSSProperties;
  const bodyClassName = [
    "ad-creative-body",
    presentation.dimensions ? "ad-creative-body-fixed" : null,
  ]
    .filter(Boolean)
    .join(" ");

  return (
    <div className={frameClassName}>
      <div className="ad-creative-header">
        <div className="ad-creative-meta">
          <strong>{presentation.zoneName}</strong>
          {presentation.dimensions ? <span className="ad-dimensions">{presentation.dimensions}</span> : null}
          <span className="ad-rate">{presentation.rateLabel}</span>
        </div>

        {href !== "#" ? (
          <a className="ad-creative-cta" href={href} rel="nofollow sponsored noreferrer" target="_blank">
            {presentation.cta}
          </a>
        ) : (
          <span className="ad-creative-cta">{presentation.cta}</span>
        )}
      </div>

      <div className={bodyClassName} style={creativeStyle}>
        {placement.type === "image" && imageUrl ? (
          <a href={href} rel="nofollow sponsored noreferrer" target="_blank">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={imageUrl} alt={placement.image?.alt_text ?? placement.name} />
          </a>
        ) : placement.html ? (
          <>
            <iframe
              className="ad-creative-sandbox"
              loading="lazy"
              referrerPolicy="no-referrer"
              sandbox={adCreativeSandbox(placement.type)}
              srcDoc={adCreativeDocument(placement.html)}
              title={`Реклама: ${placement.name}`}
            />
            {href !== "#" ? (
              <a
                aria-label={`Отвори реклама: ${placement.name}`}
                className="ad-creative-click-overlay"
                href={href}
                rel="nofollow sponsored noreferrer"
                target="_blank"
              />
            ) : null}
          </>
        ) : (
          <strong>{placement.name}</strong>
        )}
      </div>
    </div>
  );
}
