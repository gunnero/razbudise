"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";

import type { AdBookingRequestInput, AdProduct } from "@/lib/api/client";
import { submitAdBookingRequest } from "@/lib/api/client";
import {
  adProductPriceForSelection,
  adProductSelectionForChange,
  bookingRequestErrorMessage,
  formatDenars,
  localDateInputValue,
} from "@/lib/ads/bookingPresentation";
import {
  AdBookingLineItemEditor,
  type AdBookingLineItemDraft,
} from "@/components/ads/AdBookingLineItemEditor";
import { trackAdvertisingEvent } from "@/components/ads/AdvertisingAnalytics";

type AdBookingFormProps = {
  initialCampaignGoal?: string;
  initialDurationWeeks?: 1 | 2 | 4;
  initialPackageKey?: string;
  initialPackageLabel?: string;
  initialPackagePrice?: number;
  initialZoneKey?: string;
  initialZoneKeys?: string[];
  products: AdProduct[];
};

type FormStatus =
  | { kind: "idle"; message: string | null }
  | { kind: "submitting"; message: string }
  | { kind: "success"; message: string }
  | { kind: "error"; message: string };

function firstProduct(products: AdProduct[], initialZoneKey?: string): AdProduct | undefined {
  return products.find((product) => product.zone_key === initialZoneKey) ?? products[0];
}

function firstSize(product: AdProduct | undefined): [number, number] {
  const [width = 0, height = 0] = product?.allowed_sizes[0] ?? [];

  return [width, height];
}

function newLineItem(products: AdProduct[], initialZoneKey?: string, initialDurationWeeks?: 1 | 2 | 4): AdBookingLineItemDraft {
  const product = firstProduct(products, initialZoneKey);
  const [width, height] = firstSize(product);
  const selection = adProductSelectionForChange(product, { duration_weeks: initialDurationWeeks }, "duration");

  return {
    click_url: "",
    creative_type: selection.creative_type,
    duration_weeks: selection.duration_weeks,
    height,
    html: "",
    image_id: "",
    image_token: "",
    image_upload: null,
    is_political_or_sensitive: false,
    start_date: localDateInputValue(),
    width,
    zone_key: product?.zone_key ?? "",
  };
}

function initialLineItems(products: AdProduct[], initialZoneKey?: string, initialZoneKeys?: string[], initialDurationWeeks?: 1 | 2 | 4): AdBookingLineItemDraft[] {
  const validZoneKeys = initialZoneKeys?.filter((zoneKey) => products.some((product) => product.zone_key === zoneKey)) ?? [];
  return validZoneKeys.length ? validZoneKeys.map((zoneKey) => newLineItem(products, zoneKey, initialDurationWeeks)) : [newLineItem(products, initialZoneKey, initialDurationWeeks)];
}

function priceForItem(products: AdProduct[], item: AdBookingLineItemDraft): number {
  const product = products.find((candidate) => candidate.zone_key === item.zone_key);

  return adProductPriceForSelection(product, item) ?? 0;
}

function itemToPayload(item: AdBookingLineItemDraft): AdBookingRequestInput["items"][number] {
  const imageId = item.image_id.trim() === "" ? null : Number(item.image_id);

  return {
    click_url: item.click_url.trim() || null,
    creative_type: item.creative_type,
    duration_weeks: item.duration_weeks,
    height: item.height,
    html: item.creative_type === "image" ? null : item.html.trim(),
    image_id: item.creative_type === "image" ? imageId : null,
    image_token: item.creative_type === "image" ? item.image_token || null : null,
    is_political_or_sensitive: item.is_political_or_sensitive,
    start_date: item.start_date,
    width: item.width,
    zone_key: item.zone_key,
  };
}

export function AdBookingForm({ initialCampaignGoal, initialDurationWeeks, initialPackageKey, initialPackageLabel, initialPackagePrice, initialZoneKey, initialZoneKeys, products }: AdBookingFormProps) {
  const [campaignGoal, setCampaignGoal] = useState(initialCampaignGoal ?? "visibility");
  const [selectedPackage, setSelectedPackage] = useState(initialPackageKey ?? "");
  const [billing, setBilling] = useState({
    address: "",
    city: "",
    company_name: "",
    country: "MK",
    registration_number: "",
    tax_number: "",
  });
  const [contact, setContact] = useState({
    email: "",
    name: "",
    phone: "",
  });
  const [discountCode, setDiscountCode] = useState("");
  const [items, setItems] = useState<AdBookingLineItemDraft[]>(() => initialLineItems(products, initialZoneKey, initialZoneKeys, initialDurationWeeks));
  const [status, setStatus] = useState<FormStatus>({ kind: "idle", message: null });

  const subtotal = useMemo(
    () => items.reduce((total, item) => total + priceForItem(products, item), 0),
    [items, products],
  );

  useEffect(() => {
    trackAdvertisingEvent("ad_reservation_started", {
      campaign_goal: initialCampaignGoal ?? null,
      package_key: initialPackageKey ?? null,
      placement_count: items.length,
    });
  // The start event belongs to the initial reservation context only.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function updateItem(index: number, updates: Partial<AdBookingLineItemDraft>): void {
    setItems((currentItems) =>
      currentItems.map((item, currentIndex) => (currentIndex === index ? { ...item, ...updates } : item)),
    );
  }

  function removeItem(index: number): void {
    setItems((currentItems) => currentItems.filter((_, currentIndex) => currentIndex !== index));
  }

  function choosePackage(packageKey: string): void {
    const packageZones: Record<string, string[]> = {
      starter: ["home_in_feed_1"],
      reach: ["home_top_billboard", "category_in_feed"],
      premium: ["home_top_billboard", "article_after_title", "mobile_sticky_bottom"],
    };
    const availableZones = packageZones[packageKey].filter((zoneKey) => products.some((product) => product.zone_key === zoneKey));
    if (availableZones.length) setItems(availableZones.map((zoneKey) => newLineItem(products, zoneKey, initialDurationWeeks)));
    setSelectedPackage(packageKey);
    trackAdvertisingEvent("booking_package_selected", { package_key: packageKey });
  }

  function validateItems(): string | null {
    if (products.length === 0) {
      return "Во моментов нема активни рекламни позиции за резервација.";
    }

    for (const [index, item] of items.entries()) {
      if (!item.zone_key || !item.start_date || item.width < 1 || item.height < 1) {
      return `Изберете позиција и почетен датум за позиција ${index + 1}.`;
      }

      if (item.creative_type !== "image" && item.html.trim() === "") {
        return `Внесете HTML или script креатив за позиција ${index + 1}.`;
      }
    }

    return null;
  }

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();

    if (status.kind === "success") {
      return;
    }

    const validationMessage = validateItems();

    if (validationMessage) {
      setStatus({ kind: "error", message: validationMessage });
      trackAdvertisingEvent("booking_validation_failed", { placement_count: items.length });
      return;
    }

    setStatus({ kind: "submitting", message: "Ја испраќаме резервацијата..." });

    try {
      const response = await submitAdBookingRequest({
        billing: {
          address: billing.address.trim(),
          city: billing.city.trim(),
          company_name: billing.company_name.trim(),
          country: billing.country.trim().toUpperCase(),
          registration_number: billing.registration_number.trim() || null,
          tax_number: billing.tax_number.trim(),
        },
        contact: {
          email: contact.email.trim(),
          name: contact.name.trim(),
          phone: contact.phone.trim() || null,
        },
        discount_code: discountCode.trim() || null,
        items: items.map(itemToPayload),
      });

      setStatus({
        kind: "success",
        message: `Барањето е примено (${response.data.reference}). Ќе добиете потврда, проверка на креативите и профактура за рачна уплата.`,
      });
      trackAdvertisingEvent("ad_reservation_completed", {
        campaign_goal: initialCampaignGoal ?? null,
        package_key: initialPackageKey ?? null,
        placement_count: items.length,
      });
    } catch (error) {
      setStatus({
        kind: "error",
        message: bookingRequestErrorMessage(error),
      });
    }
  }

  return (
    <form className="ad-booking-form" onSubmit={handleSubmit}>
      <ol className="ad-booking-progress" aria-label="Чекори за резервација"><li>Податоци</li><li>Кампања</li><li>Позиции</li><li>Материјали</li><li>Потврда</li></ol>
      {initialPackageKey && initialPackageLabel ? (
        <aside className="ad-booking-package-context" data-package={initialPackageKey}>
          <span>Избран пакет</span>
          <strong>{initialPackageLabel}</strong>
          <p>{items.length} препорачани {items.length === 1 ? "позиција" : "позиции"} · {(initialDurationWeeks ?? 1) * 7} дена{initialPackagePrice ? ` · ${formatDenars(initialPackagePrice)}` : ""}</p>
        </aside>
      ) : null}
      <article className="ad-booking-step">
        <p className="kicker">01 · ПОДАТОЦИ</p><h2>Компанија и фактурирање</h2>
        <p>Податоците ќе се користат за профактура и рачна потврда на кампањата.</p>
        <div className="search-form search-form-expanded">
          <label>
            Компанија
            <input
              name="billing.company_name"
              value={billing.company_name}
              onChange={(event) => setBilling({ ...billing, company_name: event.target.value })}
              required
            />
          </label>
          <label>
            ЕДБ
            <input
              name="billing.tax_number"
              value={billing.tax_number}
              onChange={(event) => setBilling({ ...billing, tax_number: event.target.value })}
              required
            />
          </label>
          <label>
            Матичен број
            <input
              name="billing.registration_number"
              value={billing.registration_number}
              onChange={(event) => setBilling({ ...billing, registration_number: event.target.value })}
            />
          </label>
        </div>
        <div className="search-form search-form-expanded">
          <label>
            Адреса
            <input
              name="billing.address"
              value={billing.address}
              onChange={(event) => setBilling({ ...billing, address: event.target.value })}
              required
            />
          </label>
          <label>
            Град
            <input
              name="billing.city"
              value={billing.city}
              onChange={(event) => setBilling({ ...billing, city: event.target.value })}
              required
            />
          </label>
          <label>
            Држава
            <input
              maxLength={2}
              name="billing.country"
              value={billing.country}
              onChange={(event) => setBilling({ ...billing, country: event.target.value })}
              required
            />
          </label>
        </div>
      </article>

      <article className="ad-booking-step">
        <h2>Контакт лице</h2>
        <div className="search-form search-form-expanded">
          <label>
            Име и презиме
            <input
              name="contact.name"
              value={contact.name}
              onChange={(event) => setContact({ ...contact, name: event.target.value })}
              required
            />
          </label>
          <label>
            Е-пошта
            <input
              name="contact.email"
              type="email"
              value={contact.email}
              onChange={(event) => setContact({ ...contact, email: event.target.value })}
              required
            />
          </label>
          <label>
            Телефон
            <input
              name="contact.phone"
              value={contact.phone}
              onChange={(event) => setContact({ ...contact, phone: event.target.value })}
            />
          </label>
        </div>
      </article>

      <article className="ad-booking-step">
        <p className="kicker">02 · КАМПАЊА</p><h2>Која е целта на кампањата?</h2>
        <div className="ad-booking-goals" role="radiogroup" aria-label="Цел на кампањата">
          {[["visibility", "Видливост"], ["reach", "Досег"], ["content", "Реклама во содржина"], ["event", "Настан или промоција"], ["combined", "Комбинирана кампања"], ["other", "Друго"]].map(([value, label]) => <label key={value}><input checked={campaignGoal === value} name="campaign_goal" type="radio" value={value} onChange={() => setCampaignGoal(value)} /><span>{label}</span></label>)}
        </div>
        <h3 className="ad-booking-choice-title">Изберете пакет или продолжете со поединечни позиции</h3>
        <div className="ad-booking-packages">
          {[["starter", "Старт", "Една јасна позиција за прво присуство."], ["reach", "Досег", "Комбинација за поширока видливост."], ["premium", "Премиум", "Силно присуство на повеќе места."]].map(([key, label, text]) => <button aria-pressed={selectedPackage === key} key={key} type="button" onClick={() => choosePackage(key)}><span>{label}</span><small>{text}</small></button>)}
        </div>
      </article>

      <article className="ad-booking-step">
        <p className="kicker">03–04 · ПОЗИЦИИ И МАТЕРИЈАЛИ</p><h2>Рекламни позиции</h2>
        <p>
          Точни димензии, стартен датум, траење и Политичка или чувствителна ознака се проверуваат пред
          објава.
        </p>
        {items.map((item, index) => (
          <AdBookingLineItemEditor
            canRemove={items.length > 1}
            index={index}
            item={item}
            key={`${item.zone_key}-${index}`}
            onRemove={() => removeItem(index)}
            onUpdate={(updates) => updateItem(index, updates)}
            products={products}
          />
        ))}
        <button disabled={status.kind === "success"} type="button" onClick={() => setItems([...items, newLineItem(products)])}>
          Додај уште една позиција
        </button>
      </article>

      <article className="ad-booking-step ad-booking-review">
        <p className="kicker">05 · ПОТВРДА</p><h2>Преглед и потврда</h2>
        <p>
          Проценет износ пред данок и можни попусти: <strong>{formatDenars(subtotal)}</strong>. Конечниот износ се потврдува по проверка
          на достапноста и креативите.
        </p>
        <div className="search-form search-form-expanded">
          <label>
            Код за попуст
            <input
              name="discount_code"
              placeholder="discount"
              value={discountCode}
              onChange={(event) => setDiscountCode(event.target.value)}
            />
          </label>
        </div>
        <p>
          Со испраќање барате резервација, не автоматска наплата. Разбуди се ќе испрати потврда,
          профактура и технички коментари ако креативот треба да се коригира.
        </p>
        {status.message ? <p role="status">{status.message}</p> : null}
        <button disabled={status.kind === "submitting" || status.kind === "success" || products.length === 0} type="submit">
          Испрати барање
        </button>
        <small>По проверка ќе добиете потврда, конечна понуда и профактура.</small>
        <p className="form-privacy-notice">Податоците ги користиме за подготовка на понуда, проверка на достапност и управување со рекламната кампања. <Link href="/politika-za-privatnost">Политика за приватност</Link>.</p>
      </article>
    </form>
  );
}
