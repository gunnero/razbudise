import type { Metadata } from "next";
import Link from "next/link";
import Image from "next/image";

import { AdvertisingAnalytics } from "@/components/ads/AdvertisingAnalytics";
import { AdvertisingFaq } from "@/components/ads/AdvertisingFaq";
import { AdvertisingMediaKit } from "@/components/ads/AdvertisingMediaKit";
import { SiteHeader } from "@/components/SiteHeader";
import { SiteContainer } from "@/components/layout/SiteContainer";
import { getAdProducts, getPublicSiteSettings } from "@/lib/api/client";
import { formatDenars } from "@/lib/ads/bookingPresentation";
import {
  approvedClientLogos,
  metricsReportingPeriod,
  verifiedAudienceMetrics,
} from "@/lib/ads/advertisingPagePresentation";
import { publicAdProducts, type PublicAdProduct } from "@/lib/ads/mediaKitPresentation";
import { settingString } from "@/lib/settings/siteSettings";

export const metadata: Metadata = {
  title: { absolute: "Ценовник за рекламирање | Разбуди се" },
  description: "Рекламирајте го вашиот бизнис на Разбуди се преку банери, позиции во објави, рубрики, мобилни формати и комбинирани кампањи.",
  alternates: { canonical: "/cenovnik-za-reklamiranje" },
  robots: { index: true, follow: true },
  openGraph: {
    title: "Рекламирање на Разбуди се",
    description: "Донесете го вашиот бренд пред вистинската публика со јасни позиции, цени и едноставна резервација.",
    type: "website",
    url: "/cenovnik-za-reklamiranje",
  },
};

function byId(products: PublicAdProduct[], id: string) { return products.find((product) => product.id === id); }
function from(price: number | null) { return price === null ? "По договор" : `од ${formatDenars(price)}`; }
function priceForDuration(product: PublicAdProduct | undefined, durationWeeks: number) {
  const prices = product?.prices.filter((price) => price.duration_weeks === durationWeeks) ?? [];
  return prices.length ? Math.min(...prices.map((price) => price.price_denars)) : null;
}
function packageTotal(items: Array<PublicAdProduct | undefined>, durationWeeks: number) {
  const products = items.filter((item): item is PublicAdProduct => Boolean(item));
  const prices = products.map((item) => priceForDuration(item, durationWeeks));
  return products.length && prices.every((price): price is number => price !== null) ? prices.reduce((total, price) => total + price, 0) : null;
}
function packageBookingUrl(key: string, name: string, items: Array<PublicAdProduct | undefined>, durationWeeks: 1 | 2 | 4, goal: string) {
  const positions = items.filter((item): item is PublicAdProduct => Boolean(item));
  const price = packageTotal(positions, durationWeeks);
  const params = new URLSearchParams({ package: key, package_name: name, period: String(durationWeeks), positions: positions.map((item) => item.bookingZoneKey).join(","), goal });
  if (price !== null) params.set("package_price", String(price));
  return `/reklami/zakazi?${params.toString()}`;
}

export default async function AdvertisingPriceListPage() {
  const [apiProducts, siteSettings] = await Promise.all([getAdProducts().catch(() => null), getPublicSiteSettings()]);
  const products = publicAdProducts(apiProducts ?? []);
  const vatAnswer = settingString(siteSettings, "advertising_vat_answer", "Вкупната цена и применливите даноци ќе бидат јасно наведени во понудата и профактурата.");
  const audienceMetrics = verifiedAudienceMetrics(siteSettings).slice(0, 4);
  const reportingPeriod = metricsReportingPeriod(siteSettings);
  const clientLogos = approvedClientLogos(siteSettings);
  const goalCards = [
    { key: "visibility", title: "Најголема видливост", text: "За бренд кампањи, нови производи, настани и големи објави.", product: byId(products, "home-billboard"), category: "home", recommendations: "home-billboard,home-featured" },
    { key: "reach", title: "Поголем досег", text: "За производи, услуги, ресторани, локални бизниси и промоции.", product: byId(products, "home-feed"), category: "home", recommendations: "home-feed,category-feed,article-title" },
    { key: "engagement", title: "Ангажирани читатели", text: "За нативни кампањи, спонзорирана содржина и контекстуално рекламирање.", product: byId(products, "article-inline"), category: "article", recommendations: "article-inline,article-title,article-related" },
  ];
  const packages = [
    { key: "starter", name: "Старт", label: "За помали кампањи и насочена видливост.", duration: 1 as const, goal: "reach", items: [byId(products, "home-feed")] },
    { key: "reach", name: "Досег", label: "За поширока видливост на почетна и во рубрики.", duration: 2 as const, goal: "reach", items: [byId(products, "home-billboard"), byId(products, "category-feed")] },
    { key: "premium", name: "Премиум", label: "За лансирања и комбинирано присуство.", duration: 4 as const, goal: "visibility", items: [byId(products, "home-billboard"), byId(products, "article-title"), byId(products, "mobile-sticky")] },
  ];
  const faqItems = [
    { question: "Колку брзо може да започне кампањата?", answer: settingString(siteSettings, "advertising_faq_campaign_start", "Кампањата може да започне по потврда на терминот, техничка проверка на материјалот и евидентирана уплата. Точниот датум зависи од достапноста на избраната позиција.")! },
    { question: "Кои формати се прифаќаат?", answer: settingString(siteSettings, "advertising_faq_formats", "JPG, PNG, WEBP и GIF, како и HTML или рекламен код по техничка проверка.")! },
    { question: "Кои димензии се потребни?", answer: settingString(siteSettings, "advertising_faq_dimensions", "Димензиите зависат од позицијата и се прикажани во каталогот и резервацијата.")! },
    { question: "Дали рекламата се прикажува на компјутер и мобилен?", answer: settingString(siteSettings, "advertising_faq_devices", "Секоја позиција јасно ги наведува поддржаните уреди. Достапни се формати за компјутер, таблет и мобилен уред.")! },
    { question: "Како се одредува цената?", answer: settingString(siteSettings, "advertising_faq_pricing", "Цената зависи од избраната позиција, периодот, форматот и комбинацијата на позиции во кампањата.")! },
    { question: "Може ли комбинирана кампања?", answer: settingString(siteSettings, "advertising_faq_combined_campaign", "Да. За повеќе позиции или подолг период ќе подготвиме понуда според целта и буџетот.")! },
    { question: "Дали цените вклучуваат даноци?", answer: vatAnswer! },
  ];

  return (
    <SiteContainer as="main" className="site-shell media-kit-page">
      <SiteHeader />
      <AdvertisingAnalytics />
      <section className="media-kit-hero">
        <div className="media-kit-hero-copy">
          <p className="kicker">Рекламирање на Разбуди се</p>
          <h1>Рекламирајте се на Разбуди се</h1>
          <p>Допрете до активна публика преку банери, позиции во објави, рубрики и мобилни формати. Изберете кампања според вашата цел, буџет и период.</p>
          <div className="media-kit-hero-actions"><Link className="media-kit-button" data-ad-event="ad_hero_reservation_clicked" href="/reklami/zakazi">Резервирај реклама</Link><Link className="media-kit-text-link" data-ad-event="ad_hero_catalogue_clicked" href="#pozicii">Погледни позиции</Link></div>
        </div>
        <picture className="media-kit-hero-artwork">
          <Image className="media-kit-hero-illustration media-kit-hero-illustration-light" src="/brand/advertising-placement-devices-light.png" alt="Приказ на рекламни позиции на компјутер и мобилен уред" width={1200} height={800} priority />
          <Image className="media-kit-hero-illustration media-kit-hero-illustration-dark" src="/brand/advertising-placement-devices.png" alt="" aria-hidden="true" width={1200} height={800} />
        </picture>
      </section>

      <section className="media-kit-audience" aria-labelledby="audience-heading">
        <div><p className="kicker">Публика и вредност</p><h2 id="audience-heading">Допрете до публика што чита, истражува и реагира</h2><p>Вашата реклама се појавува во контекст на релевантна содржина, на компјутер и мобилен уред.</p></div>
        {audienceMetrics.length ? <div className="media-kit-audience-data"><dl>{audienceMetrics.map((metric) => <div key={metric.key}><dd>{metric.value}</dd><dt>{metric.label}</dt></div>)}</dl><p className="media-kit-metric-verification"><strong>Податоците се потврдени преку Google Analytics 4</strong>{reportingPeriod ? <span>Период: {reportingPeriod}</span> : null}</p></div> : null}
      </section>

      {clientLogos.length ? <section className="media-kit-clients" aria-labelledby="clients-heading"><p className="kicker">Доверба</p><h2 id="clients-heading">Брендови што се рекламирале со нас</h2><div>{clientLogos.map((client) => <Image key={client.name} src={client.path} alt={client.name} width={180} height={72} />)}</div></section> : null}

      <section className="media-kit-section" aria-labelledby="goals-heading">
        <div className="media-kit-section-heading"><p className="kicker">Изберете според целта</p><h2 id="goals-heading">Што сакате да постигнете?</h2></div>
        <div className="media-kit-goals">{goalCards.map(({ key, title, text, product, category, recommendations }) => <article key={key}><span>Цел</span><h3>{title}</h3><p>{text}</p><strong>{from(product?.startingPrice ?? null)} / 7 дена</strong><Link data-ad-event="ad_goal_recommendation_clicked" data-ad-goal={key} data-ad-goal-category={category} data-ad-recommendations={recommendations} href="#pozicii">Види препорачани позиции</Link></article>)}</div>
      </section>

      <section className="media-kit-section" id="paketi" aria-labelledby="packages-heading">
        <div className="media-kit-section-heading"><p className="kicker">Препорачани пакети</p><h2 id="packages-heading">Полесен избор за цела кампања</h2><p>Пакетите се препорака. Конечната понуда се потврдува според достапноста и избраниот период.</p></div>
        <div className="media-kit-packages">{packages.map((offer, index) => { const price = packageTotal(offer.items, offer.duration); const durationDays = offer.duration * 7; return <article className={index === 1 ? "is-featured" : ""} key={offer.key}>{index === 1 ? <span aria-label="Најпопуларен пакет">Најпопуларно</span> : null}<h3>{offer.name}</h3><p>{offer.label}</p><strong aria-label={price === null ? "Цена по понуда" : `Цена од ${formatDenars(price)} за ${durationDays} дена`}>{price === null ? "Цена по понуда" : `од ${formatDenars(price)} / ${durationDays} дена`}</strong><ul>{offer.items.filter(Boolean).map((item) => <li key={item!.id}>{item!.name}</li>)}</ul><Link data-ad-event="ad_package_reservation_clicked" data-ad-goal={offer.goal} data-ad-package={offer.key} href={packageBookingUrl(offer.key, offer.name, offer.items, offer.duration, offer.goal)}>Резервирај пакет</Link></article>; })}</div>
      </section>

      {apiProducts === null ? <p className="media-kit-alert">Живите цени моментално не се достапни. Сепак можете да испратите барање за понуда.</p> : null}
      <AdvertisingMediaKit products={products} />

      <section className="media-kit-process" aria-labelledby="process-heading"><div><p className="kicker">Како функционира</p><h2 id="process-heading">Како се резервира реклама?</h2></div><ol><li><strong>Изберете позиција или пакет</strong><span>Одберете опција што одговара на вашата цел и буџет.</span></li><li><strong>Изберете период</strong><span>Изберете период и посакуван датум за почеток.</span></li><li><strong>Испратете барање</strong><span>Испратете кратки информации и рекламен материјал.</span></li><li><strong>Потврда и старт</strong><span>По потврда и уплата, кампањата се активира.</span></li></ol><Link className="media-kit-button" data-ad-event="ad_process_reservation_clicked" href="/reklami/zakazi">Резервирај реклама</Link></section>

      <section className="media-kit-faq" aria-labelledby="faq-heading"><p className="kicker">Чести прашања</p><h2 id="faq-heading">Сè што треба да знаете</h2><AdvertisingFaq items={faqItems} /></section>

      <section className="media-kit-final-cta"><p className="kicker">Следен чекор</p><h2>Подготвени сте да ја зголемите видливоста на вашиот бренд?</h2><p>Кажете ни што сакате да постигнете, колкав буџет имате и колку долго сакате да трае кампањата. Ќе ви предложиме соодветна комбинација на позиции.</p><div><Link className="media-kit-button" data-ad-event="ad_final_recommendation_clicked" href="/reklami/zakazi?goal=recommendation">Побарај препорака</Link><a className="media-kit-text-link" href="mailto:info@razbudise.mk">Контактирај нè</a></div></section>
    </SiteContainer>
  );
}
