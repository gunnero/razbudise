import type { CSSProperties } from "react";

import { getAdPlacement } from "@/lib/api/client";
import { TrackedAdPlacement } from "@/components/TrackedAdPlacement";
import { adCreativeDimensions, adCreativeStyleVars, adSlotState, safelyLoadAdPlacement } from "@/lib/ads/adTracking";
import type { AdDevice } from "@/lib/ads/device";
import { serverAdDevice } from "@/lib/ads/serverDevice";

type AdZoneProps = {
  articleId?: number;
  categoryId?: number | null;
  categorySlug?: string | null;
  device?: AdDevice;
  label?: string;
  zoneKey: string;
};

export async function AdZone({ articleId, categoryId, categorySlug, device, label = "Реклама", zoneKey }: AdZoneProps) {
  const resolvedDevice = device ?? await serverAdDevice();
  const placement = await safelyLoadAdPlacement(async () =>
    getAdPlacement(zoneKey, {
      articleId,
      category: categorySlug,
      categoryId,
      device: resolvedDevice,
    }),
  );

  const state = adSlotState(placement);

  if (state.kind === "hidden" || !placement) {
    return null;
  }

  const dimensions = adCreativeDimensions(placement);
  const isCompactBanner = dimensions && placement.height !== null && placement.height <= 120;
  const isMediumTile =
    dimensions &&
    placement.width !== null &&
    placement.height !== null &&
    placement.width <= 360 &&
    placement.height > 120 &&
    placement.height <= 320;
  const zoneClassName = [
    "ad-zone",
    state.kind === "fallback" ? "ad-zone-fallback" : null,
    dimensions ? "ad-zone-fixed" : null,
    isCompactBanner ? "ad-zone-compact" : null,
    isMediumTile ? "ad-zone-tile" : null,
  ]
    .filter(Boolean)
    .join(" ");
  const zoneStyle = dimensions ? (adCreativeStyleVars(placement) as CSSProperties) : undefined;

  return (
    <aside className={zoneClassName} data-zone-key={zoneKey} aria-label={label} style={zoneStyle}>
      <span className="ad-zone-label">{state.label ?? label}</span>
      <TrackedAdPlacement articleId={articleId} device={resolvedDevice} placement={placement} zoneKey={zoneKey} />
    </aside>
  );
}
