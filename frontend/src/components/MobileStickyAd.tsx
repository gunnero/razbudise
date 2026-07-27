import { AdZone } from "@/components/AdZone";
import { serverAdDevice } from "@/lib/ads/serverDevice";

export async function MobileStickyAd() {
  const device = await serverAdDevice();

  if (device !== "mobile") {
    return null;
  }

  return (
    <div className="mobile-sticky-ad" aria-label="Мобилна реклама">
      <AdZone device={device} zoneKey="mobile_sticky_bottom" />
    </div>
  );
}
