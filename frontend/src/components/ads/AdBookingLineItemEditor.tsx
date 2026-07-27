import type { AdProduct } from "@/lib/api/client";
import {
  adProductCreativeTypeOptions,
  adProductDurationOptions,
  adProductPriceForSelection,
  adProductSelectionForChange,
  bookingEndDate,
  formatDenars,
} from "@/lib/ads/bookingPresentation";
import { AdCreativeGuidance } from "@/components/ads/AdCreativeGuidance";
import { AdCreativeUpload, type SelectedAdCreative } from "@/components/ads/AdCreativeUpload";
import { publicAdProducts } from "@/lib/ads/mediaKitPresentation";

export type AdBookingLineItemDraft = {
  zone_key: string;
  duration_weeks: 1 | 2 | 4;
  start_date: string;
  creative_type: "image" | "html" | "script";
  click_url: string;
  width: number;
  height: number;
  html: string;
  image_id: string;
  image_token: string;
  image_upload: SelectedAdCreative | null;
  is_political_or_sensitive: boolean;
};

type AdBookingLineItemEditorProps = {
  canRemove: boolean;
  index: number;
  item: AdBookingLineItemDraft;
  onRemove: () => void;
  onUpdate: (updates: Partial<AdBookingLineItemDraft>) => void;
  products: AdProduct[];
};

function productSize(product: AdProduct | undefined): [number, number] {
  const [width = 0, height = 0] = product?.allowed_sizes[0] ?? [];

  return [width, height];
}

export function AdBookingLineItemEditor({
  canRemove,
  index,
  item,
  onRemove,
  onUpdate,
  products,
}: AdBookingLineItemEditorProps) {
  const product = products.find((candidate) => candidate.zone_key === item.zone_key);
  const publicProducts = publicAdProducts(products);
  const durationOptions = adProductDurationOptions(product);
  const creativeTypes = adProductCreativeTypeOptions(product, item.duration_weeks);
  const price = adProductPriceForSelection(product, item);
  const endDate = item.start_date ? bookingEndDate(item.start_date, item.duration_weeks) : "";

  return (
    <section className="ad-booking-position-card">
      <div className="ad-booking-position-head"><div><span>Позиција {index + 1}</span><h3>{publicProducts.find((candidate) => candidate.internalZoneKeys.includes(item.zone_key))?.name ?? "Изберете позиција"}</h3></div>{canRemove ? <button className="ad-booking-remove" type="button" onClick={onRemove}>Отстрани</button> : null}</div>
      <div className="search-form search-form-expanded">
        <label>
          Рекламна позиција
          <select
            name={`items.${index}.zone_key`}
            value={item.zone_key}
            onChange={(event) => {
              const nextProduct = products.find((candidate) => candidate.zone_key === event.target.value);
              const [width, height] = productSize(nextProduct);
              const selection = adProductSelectionForChange(nextProduct);

              onUpdate({
                creative_type: selection.creative_type,
                duration_weeks: selection.duration_weeks,
                height,
                image_id: "",
                image_token: "",
                image_upload: null,
                width,
                zone_key: event.target.value,
              });
            }}
            required
          >
            {publicProducts.map((candidate) => (
              <option key={candidate.id} value={candidate.bookingZoneKey}>
                {candidate.name}
              </option>
            ))}
          </select>
        </label>
        <label>
          Период
          <select
            name={`items.${index}.duration_weeks`}
            value={item.duration_weeks}
            onChange={(event) => {
              const selection = adProductSelectionForChange(
                product,
                {
                  creative_type: item.creative_type,
                  duration_weeks: Number(event.target.value) as 1 | 2 | 4,
                },
                "duration",
              );

              onUpdate(selection);
            }}
          >
            {durationOptions.map((durationWeeks) => (
              <option key={durationWeeks} value={durationWeeks}>
                {durationWeeks === 1 ? "7 дена" : durationWeeks === 2 ? "14 дена" : "28 дена"}
              </option>
            ))}
          </select>
        </label>
        <label>
          Почеток
          <input
            name={`items.${index}.start_date`}
            type="date"
            value={item.start_date}
            onChange={(event) => onUpdate({ start_date: event.target.value })}
            required
          />
        </label>
        <p>Проценет крај: {endDate || "изберете почетен датум"}</p>
      </div>

      <AdCreativeGuidance allowedSizes={product?.allowed_sizes ?? []} height={item.height} width={item.width} />

      <div className="search-form search-form-expanded">
        <label>
          Начин на доставување
          <select
            name={`items.${index}.creative_type`}
            value={item.creative_type}
            onChange={(event) => {
              const selection = adProductSelectionForChange(
                product,
                {
                  creative_type: event.target.value as AdBookingLineItemDraft["creative_type"],
                  duration_weeks: item.duration_weeks,
                },
                "creative",
              );

              onUpdate(selection);
            }}
          >
            {creativeTypes.map((creativeType) => (
              <option key={creativeType} value={creativeType}>
                {creativeType === "image" ? "Ќе доставам слика" : "Ќе доставам рекламен код"}
              </option>
            ))}
          </select>
        </label>
        <p>{price === null ? "Цена ќе се потврди рачно." : `Цена: ${formatDenars(price)}`}</p>
      </div>

      {item.creative_type === "image" ? (
        <AdCreativeUpload
          allowedSizes={product?.allowed_sizes ?? []}
          value={item.image_upload}
          onChange={(creative) => onUpdate({
            image_id: creative?.media_id ? String(creative.media_id) : "",
            image_token: creative?.upload_token ?? "",
            image_upload: creative,
          })}
        />
      ) : null}

      <div className="search-form search-form-expanded">
        <label>
          Линк на рекламата
          <input
            name={`items.${index}.click_url`}
            placeholder="https://example.com"
            type="url"
            value={item.click_url}
            onChange={(event) => onUpdate({ click_url: event.target.value })}
          />
        </label>
        <label>
          Политичка или чувствителна кампања
          <input
            checked={item.is_political_or_sensitive}
            name={`items.${index}.is_political_or_sensitive`}
            type="checkbox"
            onChange={(event) => onUpdate({ is_political_or_sensitive: event.target.checked })}
          />
        </label>
      </div>

      {item.creative_type !== "image" ? <label>
        Рекламен код (за техничка проверка)
        <textarea
          name={`items.${index}.html`}
          placeholder="<a href=&quot;https://example.com&quot;>...</a>"
          rows={6}
          value={item.html}
          onChange={(event) => onUpdate({ html: event.target.value })}
        />
      </label> : null}

    </section>
  );
}
