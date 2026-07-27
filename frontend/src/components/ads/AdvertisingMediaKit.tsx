"use client";

import Link from "next/link";
import Image from "next/image";
import { useEffect, useMemo, useRef, useState } from "react";

import { formatDenars, packageLabel } from "@/lib/ads/bookingPresentation";
import { supportedDevicesLabel } from "@/lib/ads/advertisingPagePresentation";
import { bookingUrl, type PublicAdProduct } from "@/lib/ads/mediaKitPresentation";
import { trackAdvertisingEvent } from "@/components/ads/AdvertisingAnalytics";

const categories = [
  { key: "home", label: "Почетна страница" },
  { key: "article", label: "Во објава" },
  { key: "archives", label: "Рубрики и архиви" },
  { key: "mobile", label: "Мобилни позиции" },
  { key: "other", label: "Други позиции" },
] as const;

function sizesLabel(sizes: number[][]) {
  return sizes.length ? sizes.map(([width, height]) => `${width} × ${height}`).join(" · ") : "По договор";
}

function priceLabel(price: number | null) {
  return price === null ? "Цена по договор" : `од ${formatDenars(price)} / 7 дена`;
}

function bestUse(product: PublicAdProduct) {
  if (product.category === "article") return "Соодветна за кампањи што треба природно да стигнат до читатели додека ја следат содржината.";
  if (product.category === "archives") return "Соодветна за насочување публика според рубрика, автор, настан или пребарување.";
  if (product.category === "mobile") return "Соодветна за брза видливост кај посетители што читаат од мобилен уред.";
  if (product.id === "home-billboard") return "Соодветна за лансирање, голем настан или кампања со најголема видливост.";
  return "Соодветна за производи, услуги и кампањи што треба природно да се појавуваат меѓу содржините.";
}

type Availability = { available: boolean; next_available_start: string | null };

function PositionRow({ expanded, onToggle, product, recommended }: { expanded: boolean; onToggle: () => void; product: PublicAdProduct; recommended: boolean }) {
  const panelId = `position-${product.id}`;
  const [availability, setAvailability] = useState<Availability | null>(null);

  useEffect(() => {
    if (!expanded || !product.availabilityUrl || availability) return;
    const controller = new AbortController();
    const startDate = new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Skopje" }).format(new Date());
    fetch(`${product.availabilityUrl}?start_date=${startDate}&duration_weeks=1`, { signal: controller.signal })
      .then((response) => response.ok ? response.json() : null)
      .then((payload) => setAvailability(payload?.data ?? null))
      .catch(() => undefined);
    return () => controller.abort();
  }, [availability, expanded, product.availabilityUrl]);

  return (
    <article className={`media-kit-position-row${expanded ? " is-expanded" : ""}${recommended ? " is-recommended" : ""}`} data-position-id={product.id}>
      <div className="media-kit-position-summary">
        <div>{recommended ? <span className="media-kit-recommendation-badge">Препорачано за вашата цел</span> : null}<h3>{product.name}</h3><p>{product.description}</p><span className="media-kit-device-badge">{product.device}</span></div>
        <strong aria-label={`Почетна цена ${priceLabel(product.startingPrice)}`}>{priceLabel(product.startingPrice)}</strong>
        <div className="media-kit-position-actions">
          <button type="button" data-ad-event="ad_position_details_opened" data-ad-placement={product.id} aria-expanded={expanded} aria-controls={panelId} onClick={onToggle}>{expanded ? "Затвори" : "Детали"}</button>
          <Link data-ad-event="ad_position_reservation_clicked" data-ad-placement={product.id} href={bookingUrl(product.bookingZoneKey)}>Резервирај ја позицијата</Link>
        </div>
      </div>
        <div className="media-kit-position-detail" hidden={!expanded} id={panelId} role="region" aria-label={`Детали за ${product.name}`}>
          <div><h4>Формати</h4><p>{sizesLabel(product.sizes)}</p></div>
          <div><h4>Уреди</h4><p>{product.device}</p></div>
          <div><h4>Најдобра примена</h4><p>{bestUse(product)}</p></div>
          <div><h4>Достапност</h4><p>{product.availabilityUrl && !availability ? "Проверуваме термин…" : availability?.available ? "Достапна" : availability?.next_available_start ? `Провери термин · следен можен почеток ${availability.next_available_start}` : "Провери термин"}</p></div>
          <figure className="media-kit-position-preview"><Image src="/brand/advertising-placement-devices.png" alt="Мал преглед на избраната рекламна позиција" width={1200} height={800} /><figcaption>{product.name}</figcaption></figure>
          <div className="media-kit-position-prices"><h4>Период и цена</h4>
            {product.prices.map((price) => (
              <div key={`${price.duration_weeks}-${price.creative_type}`}><span>{packageLabel(price.duration_weeks)}</span><strong>{formatDenars(price.price_denars)}</strong><Link data-ad-event="ad_position_reservation_clicked" data-ad-placement={product.id} href={bookingUrl(product.bookingZoneKey, price.duration_weeks)}>Резервирај ја позицијата</Link></div>
            ))}
          </div>
        </div>
    </article>
  );
}

export function AdvertisingMediaKit({ products }: { products: PublicAdProduct[] }) {
  const sectionRef = useRef<HTMLElement>(null);
  const [catalogueOpen, setCatalogueOpen] = useState(false);
  const [openCategory, setOpenCategory] = useState<string | null>(null);
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [recommendedIds, setRecommendedIds] = useState<string[]>([]);
  const grouped = useMemo(() => categories.map((category) => ({ ...category, products: products.filter((product) => product.category === category.key) })).filter((group) => group.products.length), [products]);
  const expandedProduct = products.find((product) => product.id === expandedId) ?? null;
  const lowestPrice = products.reduce<number | null>((lowest, product) => product.startingPrice === null ? lowest : lowest === null ? product.startingPrice : Math.min(lowest, product.startingPrice), null);
  const deviceSummary = supportedDevicesLabel(products);

  useEffect(() => {
    function openFromHash() {
      if (window.location.hash !== "#pozicii") return;
      setCatalogueOpen(true);
      setOpenCategory("home");
      window.requestAnimationFrame(() => sectionRef.current?.scrollIntoView({ behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "start" }));
    }

    openFromHash();
    window.addEventListener("hashchange", openFromHash);
    return () => window.removeEventListener("hashchange", openFromHash);
  }, []);

  useEffect(() => {
    function openGoal(event: MouseEvent) {
      const target = event.target instanceof Element ? event.target.closest<HTMLElement>("[data-ad-goal-category]") : null;
      const category = target?.dataset.adGoalCategory;
      if (!category) return;
      const recommendations = (target.dataset.adRecommendations ?? "").split(",").filter(Boolean);
      setCatalogueOpen(true);
      setOpenCategory(category);
      setExpandedId(null);
      setRecommendedIds(recommendations);
      window.history.replaceState(null, "", "#pozicii");
      window.requestAnimationFrame(() => {
        sectionRef.current?.scrollIntoView({ behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "start" });
        sectionRef.current?.querySelector<HTMLElement>(`[data-position-id="${recommendations[0]}"] button`)?.focus({ preventScroll: true });
      });
    }
    document.addEventListener("click", openGoal);
    return () => document.removeEventListener("click", openGoal);
  }, []);

  if (!products.length) return <section className="media-kit-empty"><h2>Ценовникот моментално се подготвува</h2><p>Испратете барање и нашиот тим ќе ви предложи најсоодветна позиција.</p><Link className="media-kit-button" href="/reklami/zakazi">Побарај понуда</Link></section>;

  return (
    <section ref={sectionRef} className={`media-kit-position-catalog${expandedProduct ? " has-mobile-action" : ""}`} id="pozicii" aria-labelledby="positions-heading">
      <div className="media-kit-section-heading"><p className="kicker">Целосен ценовник</p><h2 id="positions-heading">Сите рекламни позиции</h2><p>Отворете категорија за да ги погледнете достапните позиции, формати и цени.</p></div>
      <div className="media-kit-catalog-summary" aria-label="Краток преглед на ценовникот"><strong>{products.length} достапни позиции</strong><span>{priceLabel(lowestPrice)}</span><span>{deviceSummary}</span></div>

      <details className="media-kit-catalog-disclosure" open={catalogueOpen} onToggle={(event) => { const open = event.currentTarget.open; if (open && !catalogueOpen) trackAdvertisingEvent("ad_catalogue_opened"); setCatalogueOpen(open); if (open && openCategory === null) setOpenCategory("home"); }}>
        <summary aria-expanded={catalogueOpen}><span>{catalogueOpen ? "Затвори ги позициите" : "Погледни ги сите позиции"}</span></summary>
        <div className="media-kit-position-groups">
          {grouped.map((group) => (
            <details key={group.key} open={openCategory === group.key} onToggle={(event) => { if (event.currentTarget.open) { setOpenCategory(group.key); setExpandedId(null); } else if (openCategory === group.key) setOpenCategory(null); }}>
              <summary><span>{group.label}</span><small>{group.products.length} {group.products.length === 1 ? "позиција" : "позиции"}</small></summary>
              <div>{group.products.map((product) => <PositionRow key={product.id} product={product} recommended={recommendedIds.includes(product.id)} expanded={expandedId === product.id} onToggle={() => setExpandedId((current) => current === product.id ? null : product.id)} />)}</div>
            </details>
          ))}
        </div>
      </details>
      {expandedProduct ? <aside className="media-kit-mobile-reservation" aria-label="Избрана рекламна позиција"><div><small>Избрана позиција</small><strong>{expandedProduct.name}</strong><span>{priceLabel(expandedProduct.startingPrice)}</span></div><button type="button" aria-label="Затвори ја мобилната резервација" onClick={() => setExpandedId(null)}>Затвори</button><Link data-ad-event="ad_position_reservation_clicked" data-ad-placement={expandedProduct.id} href={bookingUrl(expandedProduct.bookingZoneKey)}>Резервирај</Link></aside> : null}
    </section>
  );
}
