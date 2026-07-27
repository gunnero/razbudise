import Link from "next/link";

import type { AdProduct } from "@/lib/api/client";
import { formatDenars, packageLabel } from "@/lib/ads/bookingPresentation";

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

export function AdProductCard({ product }: { product: AdProduct }) {
  const prices = [...product.prices].sort((a, b) => {
    if (a.duration_weeks !== b.duration_weeks) {
      return a.duration_weeks - b.duration_weeks;
    }

    return a.creative_type.localeCompare(b.creative_type);
  });

  return (
    <article className="ad-price-list-group">
      <h2>{product.name}</h2>
      {product.description ? <p className="ad-price-list-note">{product.description}</p> : null}
      <div className="ad-price-list-table" role="table" aria-label={product.name}>
        <div className="ad-price-list-row ad-price-list-head" role="row">
          <span role="columnheader">Позиција</span>
          <span role="columnheader">Димензија</span>
          <span role="columnheader">Цена</span>
        </div>
        <div className="ad-price-list-row" role="row">
          <span role="cell">{product.page_context}</span>
          <span role="cell">{formatSizes(product.allowed_sizes)}</span>
          <span role="cell">{lowestPrice(product)}</span>
        </div>
        {prices.map((price) => (
          <div
            className="ad-price-list-row"
            role="row"
            key={`${product.zone_key}-${price.duration_weeks}-${price.creative_type}`}
          >
            <span role="cell">
              {price.creative_type === "image" ? "Слика" : price.creative_type.toUpperCase()}
            </span>
            <span role="cell">{packageLabel(price.duration_weeks)}</span>
            <span role="cell">{formatDenars(price.price_denars)}</span>
          </div>
        ))}
      </div>
      <Link className="legal-page-action" href={`/reklami/zakazi?zone=${product.zone_key}`}>
        Резервирај позиција
      </Link>
    </article>
  );
}
