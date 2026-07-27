import type { Metadata } from "next";
import Link from "next/link";
import { Fragment } from "react";
import { AdZone } from "@/components/AdZone";
import { AdZoneGroup } from "@/components/AdZoneGroup";
import { ArticleCard } from "@/components/ArticleCard";
import { SiteHeader } from "@/components/SiteHeader";
import { adZoneSlotKeys } from "@/lib/ads/adSlots";
import { getEvents } from "@/lib/api/client";
import { archivePaginationSummary } from "@/lib/archive/archivePresentation";
import { archiveResultsSummary } from "@/lib/articles/articlePresentation";
import { archiveMetadata, archivePageHref, archivePaginationLabel } from "@/lib/seo/pagination";

export const dynamic = "force-dynamic";
export const revalidate = 0;

type EventsPageProps = {
  searchParams: Promise<{ page?: string }>;
};

const eventsArchive = {
  title: "Настани",
  description: "Културни, урбани и локални настани што вреди да се следат.",
  path: "/nastani",
};

export async function generateMetadata({ searchParams }: EventsPageProps): Promise<Metadata> {
  const { page } = await searchParams;

  return archiveMetadata({ ...eventsArchive, page });
}

function pageNumber(value?: string) {
  const page = Number(value ?? 1);

  return Number.isFinite(page) && page > 0 ? page : 1;
}

export default async function EventsPage({ searchParams }: EventsPageProps) {
  const { page: pageParam } = await searchParams;
  const events = await getEvents(pageNumber(pageParam));
  const categoryInFeedSlots = adZoneSlotKeys("category_in_feed");

  return (
    <main className="site-shell">
      <SiteHeader />
      <AdZone zoneKey="event_page_top" />

      <section className="page-heading page-heading-wide">
        <p className="kicker">Календар</p>
        <h1>Настани што го движат градот</h1>
        <p>
          Избор од културни, урбани, книжевни и локални случувања. Оваа листа
          ги прикажува објавените настани од редакцијата.
        </p>
        <strong className="archive-summary">{archiveResultsSummary("Настани", events.meta.total)}</strong>
      </section>

      <section className="article-section" aria-labelledby="events-list-heading">
        <div className="section-title-row">
          <div>
            <p className="kicker">Најново</p>
            <h2 id="events-list-heading">Последни настани</h2>
          </div>
          <Link href="/category/nastani">Архива на рубрика</Link>
        </div>

        {events.data.length > 0 ? (
          <div className="article-list archive-list archive-feed event-grid">
            {events.data.map((event, index) => (
              <Fragment key={event.slug}>
                {index === 3 ? (
                  <AdZoneGroup
                    categoryId={event.category?.id}
                    categorySlug={event.category?.slug}
                    className="ad-zone-group-feed"
                    slotKeys={categoryInFeedSlots}
                  />
                ) : null}
                <ArticleCard article={event} variant="list" />
              </Fragment>
            ))}
          </div>
        ) : (
          <div className="empty-state">
            <strong>Нема објавени настани.</strong>
            <p>Кога редакцијата ќе објави event текстови, тие ќе се појават тука.</p>
          </div>
        )}
      </section>

      <nav className="pagination" aria-label="Пагинација">
        {events.links.prev ? <Link href={archivePageHref(eventsArchive.path, events.meta.current_page - 1)}>Претходна</Link> : null}
        <span>
          {archivePaginationSummary(events.meta) || archivePaginationLabel(events.meta.current_page, events.meta.last_page)}
        </span>
        {events.links.next ? <Link href={archivePageHref(eventsArchive.path, events.meta.current_page + 1)}>Следна</Link> : null}
      </nav>
    </main>
  );
}
