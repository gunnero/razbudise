import type { Metadata } from "next";

import { AdBookingForm } from "@/components/ads/AdBookingForm";
import { LegalPage } from "@/components/legal/LegalPage";
import { getAdProducts } from "@/lib/api/client";

type AdBookingPageProps = {
  searchParams: Promise<{ goal?: string; package?: string; package_price?: string; period?: string; positions?: string; zone?: string }>;
};

export const metadata: Metadata = {
  title: "Закажи реклама",
  description: "Јавна форма за резервација на рекламна позиција на Разбуди се.",
  alternates: {
    canonical: "/reklami/zakazi",
  },
};

export default async function AdBookingPage({ searchParams }: AdBookingPageProps) {
  const [{ goal, package: packageKey, package_price: packagePrice, period, positions, zone }, products] = await Promise.all([searchParams, getAdProducts().catch(() => null)]);
  const productList = products ?? [];
  const packageLabels: Record<string, string> = { starter: "Старт", reach: "Досег", premium: "Премиум" };
  const initialZoneKeys = positions?.split(",").filter(Boolean);
  const parsedPackagePrice = packagePrice && /^\d+$/.test(packagePrice) ? Number(packagePrice) : undefined;

  return (
    <LegalPage
      eyebrow="Рекламирање"
      title="Закажете рекламна кампања"
      description="Испратете ги основните податоци за кампањата. Тимот на Разбуди се ќе ја провери достапноста, материјалите и конечната понуда."
      action={{ href: "/cenovnik-za-reklamiranje", label: "Назад кон ценовникот" }}
      sections={[]}
    >
      <section className="ad-price-list" aria-label="Форма за резервација на реклами">
        {products === null ? (
          <p className="ad-price-list-note">
            Рекламните позиции се привремено недостапни. Формата може да се прегледа, но испраќањето ќе биде
            овозможено кога ценовникот повторно ќе се вчита.
          </p>
        ) : null}
      </section>
      <AdBookingForm initialCampaignGoal={goal} initialDurationWeeks={period === "1" || period === "2" || period === "4" ? Number(period) as 1 | 2 | 4 : undefined} initialPackageKey={packageKey && packageLabels[packageKey] ? packageKey : undefined} initialPackageLabel={packageKey ? packageLabels[packageKey] : undefined} initialPackagePrice={parsedPackagePrice} initialZoneKey={zone} initialZoneKeys={initialZoneKeys} products={productList} />
    </LegalPage>
  );
}
