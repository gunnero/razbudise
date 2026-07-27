import type { CustomerAdBooking } from "@/lib/api/client";

type AdDashboardSummaryProps = {
  bookings: CustomerAdBooking[];
};

function sumItems(bookings: CustomerAdBooking[], field: "clicks" | "impressions"): number {
  return bookings.reduce(
    (total, booking) => total + booking.items.reduce((itemTotal, item) => itemTotal + item[field], 0),
    0,
  );
}

export function AdDashboardSummary({ bookings }: AdDashboardSummaryProps) {
  const activeItems = bookings.flatMap((booking) =>
    booking.items.filter((item) => ["active", "published", "approved", "paid"].includes(item.status)),
  ).length;
  const scheduledItems = bookings.flatMap((booking) =>
    booking.items.filter((item) => ["scheduled", "pending_review"].includes(item.status)),
  ).length;
  const pendingRevisions = bookings.flatMap((booking) =>
    booking.items.flatMap((item) => item.revisions.filter((revision) => revision.status === "pending_review")),
  ).length;
  const impressions = sumItems(bookings, "impressions");
  const clicks = sumItems(bookings, "clicks");
  const ctr = impressions > 0 ? ((clicks / impressions) * 100).toFixed(2) : "0.00";

  return (
    <section className="ad-price-list" aria-label="Краток преглед на реклами">
      <article className="ad-price-list-group">
        <h2>Преглед</h2>
        <div className="ad-price-list-table" role="table" aria-label="Краток преглед">
          <div className="ad-price-list-row ad-price-list-head" role="row">
            <span role="columnheader">Метрика</span>
            <span role="columnheader">Вредност</span>
            <span role="columnheader">Напомена</span>
          </div>
          <div className="ad-price-list-row" role="row">
            <span role="cell">Активни позиции</span>
            <span role="cell">{activeItems}</span>
            <span role="cell">Одобрени или тековни кампањи</span>
          </div>
          <div className="ad-price-list-row" role="row">
            <span role="cell">Закажани / на проверка</span>
            <span role="cell">{scheduledItems}</span>
            <span role="cell">Позиции што чекаат старт или проверка</span>
          </div>
          <div className="ad-price-list-row" role="row">
            <span role="cell">Креативи на проверка</span>
            <span role="cell">{pendingRevisions}</span>
            <span role="cell">Испратени измени пред админ одобрување</span>
          </div>
          <div className="ad-price-list-row" role="row">
            <span role="cell">Импресии</span>
            <span role="cell">{impressions.toLocaleString("mk-MK")}</span>
            <span role="cell">{clicks.toLocaleString("mk-MK")} кликови, CTR {ctr}%</span>
          </div>
        </div>
      </article>
    </section>
  );
}
