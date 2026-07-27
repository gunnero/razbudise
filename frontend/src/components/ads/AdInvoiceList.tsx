import type { CustomerAdInvoice } from "@/lib/api/client";

type AdInvoiceListProps = {
  invoices: CustomerAdInvoice[];
};

function formatDate(value: string | null): string {
  if (!value) {
    return "Нема датум";
  }

  return new Intl.DateTimeFormat("mk-MK", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).format(new Date(value));
}

export function AdInvoiceList({ invoices }: AdInvoiceListProps) {
  return (
    <section className="ad-price-list" aria-label="Профактури и фактури">
      <article className="ad-price-list-group">
        <h2>Профактури и фактури</h2>
        {invoices.length === 0 ? (
          <p className="ad-price-list-note">Нема издадени профактури или фактури за оваа сметка.</p>
        ) : (
          <div className="ad-price-list-table" role="table" aria-label="Профактури и фактури">
            <div className="ad-price-list-row ad-price-list-head" role="row">
              <span role="columnheader">Број</span>
              <span role="columnheader">Статус</span>
              <span role="columnheader">Износ</span>
            </div>
            {invoices.map((invoice) => (
              <div className="ad-price-list-row" key={invoice.id} role="row">
                <span role="cell">
                  {invoice.number}
                  <br />
                  <small>{invoice.booking_reference ?? "Без референца"} · {formatDate(invoice.issued_at)}</small>
                </span>
                <span role="cell">{invoice.type} · {invoice.status}</span>
                <span role="cell">{invoice.total_denars.toLocaleString("mk-MK")} ден.</span>
              </div>
            ))}
          </div>
        )}
      </article>
    </section>
  );
}
