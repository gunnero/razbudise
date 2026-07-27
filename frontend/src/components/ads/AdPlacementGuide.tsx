"use client";

import Image from "next/image";
import Link from "next/link";
import { useMemo, useState } from "react";

import type { AdProduct } from "@/lib/api/client";
import { formatDenars } from "@/lib/ads/bookingPresentation";

type GuideViewKey = "home" | "article" | "category" | "footer" | "tablet" | "mobile";

type PlacementNotes = {
  desktop: string;
  mobile: string;
  tablet: string;
};

type SketchSample = {
  cards: Array<{
    category: string;
    title: string;
  }>;
  eyebrow: string;
  headline: string;
  summary: string;
  ticker: string;
};

const guideViews: Array<{
  description: string;
  key: GuideViewKey;
  label: string;
}> = [
  {
    key: "home",
    label: "Почетна",
    description: "Горен билборд, позиции после главната приказна и реклами помеѓу објавите.",
  },
  {
    key: "article",
    label: "Статија",
    description: "Позиции околу насловот, во текстот, пред поврзани објави и во sidebar.",
  },
  {
    key: "category",
    label: "Категорија",
    description: "Позиции на архиви, пребарување, авторски страници и настани.",
  },
  {
    key: "footer",
    label: "Footer",
    description: "Билтен и партнерски позиции во долниот дел на страницата.",
  },
  {
    key: "tablet",
    label: "Tablet",
    description: "Како се прередуваат позициите кога страницата се гледа на таблет.",
  },
  {
    key: "mobile",
    label: "Mobile",
    description: "Мобилна колона, sticky позиција и скалирани банери.",
  },
];

const sketchSamples: Record<GuideViewKey, SketchSample> = {
  home: {
    eyebrow: "Главни приказни",
    headline: "Локална приказна што го буди градот",
    summary: "Живот, култура и настани подредени како на почетната страница.",
    ticker: "Локална приказна, интервју и културен настан во истиот уреднички ритам.",
    cards: [
      { category: "Живот", title: "Мали навики што го прават денот полесен" },
      { category: "Урбано", title: "Градски пулс и нови места за средба" },
      { category: "Интервју", title: "Разговор со луѓе кои имаат што да кажат" },
      { category: "Настани", title: "Календар на идеи за следното излегување" },
    ],
  },
  article: {
    eyebrow: "Статија",
    headline: "Наслов, фотографија и текст во читлива колона",
    summary: "Рекламите се појавуваат околу насловот, во текстот и покрај читањето.",
    ticker: "Пример статија со автор, категорија, датум и поврзани приказни.",
    cards: [
      { category: "Автор", title: "Редакциски вовед и краток опис" },
      { category: "Текст", title: "Пасуси со природни места за реклами" },
      { category: "Поврзано", title: "Продолжи со читање по завршувањето" },
      { category: "Sidebar", title: "Десна колона за desktop позициите" },
    ],
  },
  category: {
    eyebrow: "Категорија",
    headline: "Архива со најнови објави од рубриката",
    summary: "Категориите, пребарувањето и авторските страници користат feed позиции.",
    ticker: "Пример рубрика со нови објави, архивски резултати и реклами во feed.",
    cards: [
      { category: "Категорија", title: "Најчитани приказни од оваа рубрика" },
      { category: "Пребарување", title: "Резултати што остануваат лесни за скенирање" },
      { category: "Автор", title: "Профил со најнови текстови" },
      { category: "Настани", title: "Календарски страници со топ позиција" },
    ],
  },
  footer: {
    eyebrow: "Footer",
    headline: "Билтен, навигација и партнерски простор",
    summary: "Долниот дел ја собира публиката што стигнала до крајот на страницата.",
    ticker: "Newsletter inline позициите стојат покрај билтенот и footer навигацијата.",
    cards: [
      { category: "Билтен", title: "Разбуди се во твоето сандаче" },
      { category: "Навигација", title: "За нас, контакт и рекламни информации" },
      { category: "Социјални", title: "Facebook, Instagram и YouTube канал" },
      { category: "Партнер", title: "Јасно означен простор за бренд" },
    ],
  },
  tablet: {
    eyebrow: "Tablet",
    headline: "Истата страница во две поудобни колони",
    summary: "Позициите се редат без да го прекинат читањето или навигацијата.",
    ticker: "Tablet приказ со скалирани банери и помалку тесни sidebar позиции.",
    cards: [
      { category: "Почетна", title: "Главната приказна останува прва" },
      { category: "Feed", title: "Рекламите се редат меѓу објавите" },
      { category: "Статија", title: "Текстот останува во широка читлива колона" },
      { category: "Footer", title: "Билтенот преминува во две колони" },
    ],
  },
  mobile: {
    eyebrow: "Mobile",
    headline: "Една колона за брзо и чисто читање",
    summary: "Сите реклами се појавуваат во природниот редослед или како sticky mobile позиција.",
    ticker: "Mobile позициите се full-width и внимателно поставени меѓу приказните.",
    cards: [
      { category: "Мени", title: "Групирани рубрики наместо долга хоризонтална листа" },
      { category: "Текст", title: "Пасусите остануваат лесни за читање" },
      { category: "Реклама", title: "Sticky bottom позиција кога е договорена" },
      { category: "Feed", title: "Една објава, па јасен рекламен блок" },
    ],
  },
};

function formatSizes(sizes: number[][]): string {
  if (sizes.length === 0) {
    return "По договор";
  }

  return sizes.map(([width, height]) => `${width}x${height}`).join(", ");
}

function lowestPrice(product: AdProduct): string {
  const price = product.prices.reduce<number | null>((lowest, current) => {
    if (lowest === null || current.price_denars < lowest) {
      return current.price_denars;
    }

    return lowest;
  }, null);

  return price === null ? "Цена по договор" : `од ${formatDenars(price)}`;
}

function creativeTypeLabels(product: AdProduct): string {
  const labels = new Map([
    ["image", "слика"],
    ["html", "HTML"],
    ["script", "script"],
  ]);
  const types = [...new Set(product.prices.map((price) => price.creative_type))];

  if (types.length === 0) {
    return "по договор";
  }

  return types.map((type) => labels.get(type) ?? type).join(", ");
}

function productView(product: AdProduct): GuideViewKey {
  const key = product.zone_key;

  if (key.startsWith("article_")) return "article";
  if (key.startsWith("category_") || key.startsWith("search_") || key.startsWith("author_") || key.startsWith("event_")) {
    return "category";
  }
  if (key.startsWith("newsletter_")) return "footer";
  if (key.startsWith("mobile_")) return "mobile";

  return "home";
}

function productRole(product: AdProduct): string {
  const key = product.zone_key;

  if (key.includes("billboard") || key.endsWith("_top")) return "billboard";
  if (key.includes("sidebar")) return "sidebar";
  if (key.includes("sticky")) return "mobile";
  if (key.includes("newsletter")) return "footer";
  if (key.includes("in_feed")) return "feed";
  if (key.includes("after_title") || key.includes("after_featured")) return "featured";
  if (key.includes("paragraph") || key.includes("before_related")) return "inline";

  return "feed";
}

function viewProducts(products: AdProduct[], view: GuideViewKey): AdProduct[] {
  const matching = products.filter((product) => {
    if (view === "tablet") {
      return !product.zone_key.startsWith("mobile_");
    }

    if (view === "mobile") {
      return (
        product.zone_key.startsWith("mobile_") ||
        product.zone_key.includes("in_feed") ||
        product.zone_key.includes("after_title") ||
        product.zone_key.includes("paragraph") ||
        product.zone_key.includes("top")
      );
    }

    return productView(product) === view;
  });

  return matching.sort((a, b) => placementPriority(a) - placementPriority(b));
}

function placementPriority(product: AdProduct): number {
  const key = product.zone_key;

  if (key.includes("billboard") || key.endsWith("_top")) return 1;
  if (key.includes("after_title") || key.includes("after_featured")) return 2;
  if (key.includes("in_feed")) return 3;
  if (key.includes("paragraph")) return 4;
  if (key.includes("sidebar")) return 5;
  if (key.includes("sticky")) return 6;
  if (key.includes("newsletter")) return 7;

  return 8;
}

function placementNotes(product: AdProduct): PlacementNotes {
  const key = product.zone_key;

  if (key.startsWith("mobile_sticky")) {
    return {
      desktop: "Не се прикажува на desktop; позицијата е резервирана за помали екрани.",
      tablet: "Tablet позиција: се појавува долу кога ширината е тесна и не ја турка содржината.",
      mobile: "Mobile позиција: sticky долу на екранот, видлива додека читателот скрола.",
    };
  }

  if (key.includes("sidebar")) {
    return {
      desktop: "Desktop позиција: десна колона покрај текстот или главната содржина.",
      tablet: "Tablet позиција: се поместува под главниот текст или под првиот блок со содржина.",
      mobile: "Mobile позиција: се прикажува како full-width блок помеѓу содржината, не како тесен sidebar.",
    };
  }

  if (key.includes("newsletter")) {
    return {
      desktop: "Desktop позиција: долниот footer дел, покрај билтенот и навигацијата.",
      tablet: "Tablet позиција: останува во footer, најчесто во две или една колона според ширината.",
      mobile: "Mobile позиција: се реди под footer навигацијата како full-width блок.",
    };
  }

  if (key.includes("in_feed")) {
    return {
      desktop: "Desktop позиција: меѓу картичките со објави во почетна или архивна мрежа.",
      tablet: "Tablet позиција: во feed со две колони, после група објави.",
      mobile: "Mobile позиција: во една колона помеѓу објавите, со истата нарачана зона.",
    };
  }

  if (key.includes("paragraph")) {
    return {
      desktop: "Desktop позиција: во текстот на статијата, после договорениот пасус.",
      tablet: "Tablet позиција: во истата точка на читање, скалирана на ширината на текстот.",
      mobile: "Mobile позиција: во текстот како full-width блок после истиот пасус.",
    };
  }

  if (key.includes("after_title")) {
    return {
      desktop: "Desktop позиција: веднаш под насловот и мета информациите на статијата.",
      tablet: "Tablet позиција: под насловот, пред главната фотографија или првиот текст.",
      mobile: "Mobile позиција: под насловот како full-width блок пред читањето.",
    };
  }

  if (key.includes("after_featured")) {
    return {
      desktop: "Desktop позиција: по главната приказна на почетната страница.",
      tablet: "Tablet позиција: под главната приказна, пред следната група објави.",
      mobile: "Mobile позиција: под главната приказна како посебен блок.",
    };
  }

  if (key.includes("top") || key.includes("billboard")) {
    return {
      desktop: "Desktop позиција: горен дел на страницата, пред главната содржина.",
      tablet: "Tablet позиција: горе над содржината, скалирана на достапната ширина.",
      mobile: "Mobile позиција: горе во една колона или заменета со посоодветна mobile позиција кога е договорено.",
    };
  }

  return {
    desktop: "Desktop позиција: во контекстот прикажан во оваа зона.",
    tablet: "Tablet позиција: се реди според ширината на екранот без да ја крши содржината.",
    mobile: "Mobile позиција: се прикажува како full-width блок во природниот редослед на читање.",
  };
}

export function AdPlacementGuide({ products }: { products: AdProduct[] }) {
  const [activeView, setActiveView] = useState<GuideViewKey>("home");
  const [selectedKey, setSelectedKey] = useState<string | null>(products[0]?.zone_key ?? null);

  const visibleProducts = useMemo(() => viewProducts(products, activeView), [activeView, products]);
  const selectedProduct =
    visibleProducts.find((product) => product.zone_key === selectedKey) ?? visibleProducts[0] ?? products[0] ?? null;
  const activeGuideView = guideViews.find((view) => view.key === activeView) ?? guideViews[0];
  const activeSample = sketchSamples[activeView];

  if (products.length === 0) {
    return null;
  }

  const notes = selectedProduct ? placementNotes(selectedProduct) : null;

  return (
    <section className="ad-placement-guide" aria-label="Водич за рекламни позиции">
      <div className="ad-placement-guide-header">
        <div>
          <p className="kicker">Позиции</p>
          <h2>Каде ќе се гледа вашата реклама</h2>
        </div>
        <p>
          Изберете позиција од скицата и проверете димензија, почетна цена и како се прикажува на desktop,
          tablet и mobile.
        </p>
      </div>

      <div className="ad-placement-tabs" role="tablist" aria-label="Тип на страница или уред">
        {guideViews.map((view) => (
          <button
            aria-selected={view.key === activeView}
            className={view.key === activeView ? "ad-placement-tab ad-placement-tab-active" : "ad-placement-tab"}
            key={view.key}
            onClick={() => setActiveView(view.key)}
            role="tab"
            type="button"
          >
            {view.label}
          </button>
        ))}
      </div>

      <div className="ad-placement-layout ad-placement-map">
        <div className={`ad-site-sketch ad-site-sketch-${activeView}`} aria-label={activeGuideView.description}>
          <div className="ad-site-sketch-masthead" aria-hidden="true">
            <div className="ad-site-sketch-social">
              <span>FB</span>
              <span>IG</span>
            </div>
            <div className="ad-site-sketch-logo">
              <Image alt="" height={70} priority={false} sizes="190px" src="/brand/razbudise-logo.png" width={230} />
            </div>
            <div className="ad-site-sketch-search">Пребарување</div>
          </div>
          <nav className="ad-site-sketch-nav" aria-hidden="true">
            <span>Почетна</span>
            <span>Живот</span>
            <span>Урбано</span>
            <span>Битолски муабети</span>
            <span>Вести</span>
          </nav>
          <div className="ad-site-sketch-ticker" aria-hidden="true">
            <strong>ТОП ВЕСТИ</strong>
            <span>{activeSample.ticker}</span>
          </div>
          <div className="ad-site-sketch-story" aria-hidden="true">
            <article className="ad-site-sketch-lead-card">
              <div className="ad-site-sketch-image">
                <span>Разбуди се</span>
              </div>
              <p className="ad-site-sketch-eyebrow">{activeSample.eyebrow}</p>
              <h3>{activeSample.headline}</h3>
              <p>{activeSample.summary}</p>
            </article>
            <div className="ad-site-sketch-card-grid">
              {activeSample.cards.map((card) => (
                <article className="ad-site-sketch-card" key={`${activeView}-${card.category}-${card.title}`}>
                  <small>{card.category}</small>
                  <strong>{card.title}</strong>
                </article>
              ))}
            </div>
          </div>
          {visibleProducts.length === 0 ? (
            <div className="ad-site-sketch-empty">Нема активни позиции за овој приказ.</div>
          ) : (
            visibleProducts.map((product) => (
              <button
                aria-pressed={selectedProduct?.zone_key === product.zone_key}
                className={[
                  "ad-sketch-zone",
                  `ad-sketch-zone-${productRole(product)}`,
                  selectedProduct?.zone_key === product.zone_key ? "ad-sketch-zone-active" : "",
                ]
                  .filter(Boolean)
                  .join(" ")}
                data-zone={product.zone_key}
                key={product.zone_key}
                onClick={() => setSelectedKey(product.zone_key)}
                type="button"
              >
                <span>{product.name}</span>
                <small>{formatSizes(product.allowed_sizes)}</small>
              </button>
            ))
          )}
        </div>

        <aside className="ad-placement-detail" aria-live="polite">
          {selectedProduct && notes ? (
            <>
              <p className="kicker">{selectedProduct.page_context}</p>
              <h3>{selectedProduct.name}</h3>
              {selectedProduct.description ? <p>{selectedProduct.description}</p> : null}
              <dl className="ad-placement-facts">
                <div>
                  <dt>Димензија</dt>
                  <dd>{formatSizes(selectedProduct.allowed_sizes)}</dd>
                </div>
                <div>
                  <dt>Почетна цена</dt>
                  <dd>{lowestPrice(selectedProduct)}</dd>
                </div>
                <div>
                  <dt>Формати</dt>
                  <dd>{creativeTypeLabels(selectedProduct)}</dd>
                </div>
              </dl>
              <div className="ad-placement-device-list">
                <article>
                  <h4>Desktop позиција</h4>
                  <p>{notes.desktop}</p>
                </article>
                <article>
                  <h4>Tablet позиција</h4>
                  <p>{notes.tablet}</p>
                </article>
                <article>
                  <h4>Mobile позиција</h4>
                  <p>{notes.mobile}</p>
                </article>
              </div>
              <Link className="legal-page-action" href={`/reklami/zakazi?zone=${selectedProduct.zone_key}`}>
                Резервирај ја оваа позиција
              </Link>
            </>
          ) : (
            <p>Изберете позиција за повеќе детали.</p>
          )}
        </aside>
      </div>
    </section>
  );
}
