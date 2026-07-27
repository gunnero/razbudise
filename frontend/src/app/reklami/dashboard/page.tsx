"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useMemo, useState } from "react";

import { HeaderDateTime } from "@/components/HeaderDateTime";
import { AdCreativeRevisionForm } from "@/components/ads/AdCreativeRevisionForm";
import { AdDashboardSummary } from "@/components/ads/AdDashboardSummary";
import { AdInvoiceList } from "@/components/ads/AdInvoiceList";
import { AdRenewalPanel } from "@/components/ads/AdRenewalPanel";
import type { CustomerAdBooking, CustomerAdBookingItem, CustomerAdDashboard } from "@/lib/api/client";
import { getCustomerAdDashboard, logoutCustomer } from "@/lib/api/client";

type DashboardState =
  | { kind: "loading"; data: null; message: string }
  | { kind: "ready"; data: CustomerAdDashboard; message: string | null }
  | { kind: "anonymous"; data: null; message: string }
  | { kind: "error"; data: null; message: string };

function CustomerAdHeader({ onLogout }: { onLogout: () => void }) {
  return (
    <header className="site-header" aria-label="Разбуди се реклами">
      <div className="site-header-inner">
        <div className="site-header-edition">
          <HeaderDateTime />
          <span className="site-header-description">Рекламен dashboard за клиенти</span>
          <button className="legal-page-action" onClick={onLogout} type="button">
            Одјави се
          </button>
        </div>
        <Link className="brand site-masthead" href="/">
          <Image
            alt="Разбуди се"
            className="brand-logo"
            height={624}
            priority
            src="/brand/razbudise-logo.png"
            width={2386}
          />
        </Link>
      </div>
    </header>
  );
}

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

function statusLabel(status: string): string {
  const labels: Record<string, string> = {
    active: "Активно",
    approved: "Одобрено",
    archived: "Архивирано",
    needs_payment: "Чека уплата",
    paid: "Платено",
    pending_review: "На проверка",
    published: "Објавено",
    rejected: "Одбиено",
    scheduled: "Закажано",
  };

  return labels[status] ?? status;
}

function itemRange(item: CustomerAdBookingItem): string {
  return `${formatDate(item.start_at)} - ${formatDate(item.end_at)}`;
}

function bookingItems(bookings: CustomerAdBooking[], statuses: string[]): Array<{
  booking: CustomerAdBooking;
  item: CustomerAdBookingItem;
}> {
  return bookings.flatMap((booking) =>
    booking.items
      .filter((item) => statuses.includes(item.status))
      .map((item) => ({
        booking,
        item,
      })),
  );
}

function needsAttentionItems(bookings: CustomerAdBooking[]): Array<{
  booking: CustomerAdBooking;
  item: CustomerAdBookingItem;
}> {
  return bookings.flatMap((booking) =>
    booking.items
      .filter((item) => booking.status === "rejected" || item.status === "rejected")
      .map((item) => ({
        booking,
        item,
      })),
  );
}

function BookingTable({
  emptyText,
  items,
  onRenewed,
  onRevisionSubmitted,
  title,
}: {
  emptyText: string;
  items: Array<{ booking: CustomerAdBooking; item: CustomerAdBookingItem }>;
  onRenewed: () => void;
  onRevisionSubmitted: () => void;
  title: string;
}) {
  return (
    <section className="ad-price-list" aria-label={title}>
      <article className="ad-price-list-group">
        <h2>{title}</h2>
        {items.length === 0 ? <p className="ad-price-list-note">{emptyText}</p> : null}
      </article>
      {items.map(({ booking, item }) => (
        <article className="ad-price-list-group" key={`${booking.id}-${item.id}`}>
          <h2>{item.zone_name ?? item.zone_key ?? "Рекламна позиција"}</h2>
          <p>
            {booking.reference} · {statusLabel(item.status)} · {item.creative_type} {item.width}x{item.height}
          </p>
          <p>
            {itemRange(item)} · {item.impressions.toLocaleString("mk-MK")} импресии ·{" "}
            {item.clicks.toLocaleString("mk-MK")} кликови · CTR {item.ctr.toFixed(2)}%
          </p>
          <AdRenewalPanel item={item} onRenewed={onRenewed} />
          <AdCreativeRevisionForm item={item} onSubmitted={onRevisionSubmitted} />
        </article>
      ))}
    </section>
  );
}

function NeedsAttentionSection({
  items,
  onRevisionSubmitted,
}: {
  items: Array<{ booking: CustomerAdBooking; item: CustomerAdBookingItem }>;
  onRevisionSubmitted: () => void;
}) {
  return (
    <section className="ad-price-list" aria-label="Потребно внимание">
      <article className="ad-price-list-group">
        <h2>Потребно внимание</h2>
        {items.length === 0 ? (
          <p className="ad-price-list-note">Нема одбиени кампањи или креативи што бараат корекција.</p>
        ) : null}
      </article>
      {items.map(({ booking, item }) => (
        <article className="ad-price-list-group" key={`attention-${booking.id}-${item.id}`}>
          <h2>{item.zone_name ?? item.zone_key ?? "Рекламна позиција"}</h2>
          <p>
            {booking.reference} · кампања: {statusLabel(booking.status)} · позиција: {statusLabel(item.status)}
          </p>
          <p className="ad-price-list-note">
            Причина: {item.rejection_reason ?? booking.rejection_reason ?? "Потребна е корекција пред одобрување."}
          </p>
          <AdCreativeRevisionForm item={item} onSubmitted={onRevisionSubmitted} />
        </article>
      ))}
    </section>
  );
}

function RevisionAlerts({ bookings }: { bookings: CustomerAdBooking[] }) {
  const revisions = bookings.flatMap((booking) =>
    booking.items.flatMap((item) =>
      item.revisions
        .filter((revision) => ["pending_review", "rejected"].includes(revision.status))
        .map((revision) => ({
          booking,
          item,
          revision,
        })),
    ),
  );

  return (
    <section className="ad-price-list" aria-label="Измени на креативи">
      <article className="ad-price-list-group">
        <h2>Измени на креативи</h2>
        {revisions.length === 0 ? (
          <p className="ad-price-list-note">Нема креативи што чекаат проверка или се одбиени.</p>
        ) : (
          <div className="ad-price-list-table" role="table" aria-label="Измени на креативи">
            <div className="ad-price-list-row ad-price-list-head" role="row">
              <span role="columnheader">Позиција</span>
              <span role="columnheader">Статус</span>
              <span role="columnheader">Причина</span>
            </div>
            {revisions.map(({ booking, item, revision }) => (
              <div className="ad-price-list-row" key={revision.id} role="row">
                <span role="cell">
                  {item.zone_name ?? item.zone_key ?? "Рекламна позиција"}
                  <br />
                  <small>{booking.reference}</small>
                </span>
                <span role="cell">
                  {statusLabel(revision.status)}
                  {revision.technical_review_required ? " · техничка проверка" : ""}
                </span>
                <span role="cell">{revision.rejection_reason ?? "Чека проверка од администратор."}</span>
              </div>
            ))}
          </div>
        )}
      </article>
    </section>
  );
}

export default function CustomerAdDashboardPage() {
  const [state, setState] = useState<DashboardState>({
    kind: "loading",
    data: null,
    message: "Го вчитуваме dashboard-от...",
  });

  async function fetchDashboard(successMessage: string | null = null): Promise<void> {
    try {
      const dashboard = await getCustomerAdDashboard();

      if (!dashboard) {
        setState({
          kind: "anonymous",
          data: null,
          message: "Најавете се за да го видите рекламниот dashboard.",
        });
        return;
      }

      setState({ kind: "ready", data: dashboard, message: successMessage });
    } catch {
      setState({
        kind: "error",
        data: null,
        message: "Не можеме да го вчитаме dashboard-от во моментов.",
      });
    }
  }

  async function loadDashboard(successMessage: string | null = null): Promise<void> {
    setState({
      kind: "loading",
      data: null,
      message: "Го вчитуваме dashboard-от...",
    });
    await fetchDashboard(successMessage);
  }

  useEffect(() => {
    let cancelled = false;

    getCustomerAdDashboard()
      .then((dashboard) => {
        if (cancelled) {
          return;
        }

        if (!dashboard) {
          setState({
            kind: "anonymous",
            data: null,
            message: "Најавете се за да го видите рекламниот dashboard.",
          });
          return;
        }

        setState({ kind: "ready", data: dashboard, message: null });
      })
      .catch(() => {
        if (!cancelled) {
          setState({
            kind: "error",
            data: null,
            message: "Не можеме да го вчитаме dashboard-от во моментов.",
          });
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  async function handleLogout(): Promise<void> {
    await logoutCustomer().catch(() => undefined);
    window.location.href = "/reklami/najava";
  }

  const dashboard = state.kind === "ready" ? state.data : null;
  const activeItems = useMemo(
    () => (dashboard ? bookingItems(dashboard.bookings, ["active", "published", "approved", "paid"]) : []),
    [dashboard],
  );
  const scheduledItems = useMemo(
    () => (dashboard ? bookingItems(dashboard.bookings, ["scheduled", "pending_review"]) : []),
    [dashboard],
  );
  const attentionItems = useMemo(
    () => (dashboard ? needsAttentionItems(dashboard.bookings) : []),
    [dashboard],
  );

  return (
    <main className="site-shell">
      <CustomerAdHeader onLogout={handleLogout} />

      <section className="page-heading page-heading-wide legal-page-heading">
        <p className="kicker">Рекламирање</p>
        <h1>Рекламен dashboard</h1>
        <p>
          Следете активни и закажани позиции, профактури, креативи на проверка и барања за продолжување.
        </p>
      </section>

      {state.kind === "loading" ? <p className="ad-price-list-note">{state.message}</p> : null}
      {state.kind === "anonymous" ? (
        <section className="ad-price-list" aria-label="Потребна е најава">
          <article className="ad-price-list-group">
            <h2>Потребна е најава</h2>
            <p className="ad-price-list-note">{state.message}</p>
            <Link className="legal-page-action" href="/reklami/najava">
              Најави се
            </Link>
          </article>
        </section>
      ) : null}
      {state.kind === "error" ? <p className="ad-price-list-note">{state.message}</p> : null}

      {dashboard ? (
        <>
          <section className="legal-page" aria-label="Клиент">
            <article>
              <h2>{dashboard.customer.company_name}</h2>
              <p>
                Контакт: {dashboard.customer.contact_name ?? "Нема контакт"} · {dashboard.customer.contact_email}
              </p>
              {state.message ? <p className="ad-price-list-note">{state.message}</p> : null}
            </article>
          </section>
          <AdDashboardSummary bookings={dashboard.bookings} />
          <BookingTable
            emptyText="Нема активни рекламни позиции."
            items={activeItems}
            onRenewed={() => void loadDashboard("Барањето за продолжување е примено.")}
            onRevisionSubmitted={() => void loadDashboard("Измената на креативот е испратена на проверка.")}
            title="Активни реклами"
          />
          <BookingTable
            emptyText="Нема закажани позиции или позиции на проверка."
            items={scheduledItems}
            onRenewed={() => void loadDashboard("Барањето за продолжување е примено.")}
            onRevisionSubmitted={() => void loadDashboard("Измената на креативот е испратена на проверка.")}
            title="Закажани и на проверка"
          />
          <NeedsAttentionSection
            items={attentionItems}
            onRevisionSubmitted={() => void loadDashboard("Измената на креативот е испратена на проверка.")}
          />
          <RevisionAlerts bookings={dashboard.bookings} />
          <AdInvoiceList invoices={dashboard.invoices} />
        </>
      ) : null}
    </main>
  );
}
