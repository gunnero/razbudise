import Link from "next/link";
import { Fragment } from "react";
import { AdZone } from "@/components/AdZone";
import { AdZoneGroup } from "@/components/AdZoneGroup";
import { ArticleCard } from "@/components/ArticleCard";
import { SiteHeader } from "@/components/SiteHeader";
import { adZoneSlotKeys } from "@/lib/ads/adSlots";
import { getArticles } from "@/lib/api/client";
import { archivePaginationSummary } from "@/lib/archive/archivePresentation";
import { archiveResultsSummary } from "@/lib/articles/articlePresentation";
import { archivePageHref, archivePaginationLabel } from "@/lib/seo/pagination";

type TypeArchivePageProps = {
  adZoneKey?: string;
  archiveLabel: string;
  categoryArchiveHref?: string;
  description: string;
  emptyDescription: string;
  emptyTitle: string;
  heading: string;
  kicker: string;
  latestHeading: string;
  page?: string;
  path: string;
  type: string;
};

function pageNumber(value?: string) {
  const page = Number(value ?? 1);

  return Number.isFinite(page) && page > 0 ? page : 1;
}

export async function TypeArchivePage({
  adZoneKey = "category_top",
  archiveLabel,
  categoryArchiveHref,
  description,
  emptyDescription,
  emptyTitle,
  heading,
  kicker,
  latestHeading,
  page,
  path,
  type,
}: TypeArchivePageProps) {
  const articles = await getArticles(pageNumber(page), 12, { type });
  const categoryInFeedSlots = adZoneSlotKeys("category_in_feed");

  return (
    <main className="site-shell">
      <SiteHeader />
      <AdZone zoneKey={adZoneKey} />

      <section className="page-heading page-heading-wide">
        <p className="kicker">{kicker}</p>
        <h1>{heading}</h1>
        <p>{description}</p>
        <strong className="archive-summary">{archiveResultsSummary(archiveLabel, articles.meta.total)}</strong>
      </section>

      <section className="article-section" aria-labelledby={`${type}-archive-heading`}>
        <div className="section-title-row">
          <div>
            <p className="kicker">Најново</p>
            <h2 id={`${type}-archive-heading`}>{latestHeading}</h2>
          </div>
          {categoryArchiveHref ? <Link href={categoryArchiveHref}>Архива на рубрика</Link> : null}
        </div>

        {articles.data.length > 0 ? (
          <div className="article-list archive-list archive-feed">
            {articles.data.map((article, index) => (
              <Fragment key={article.slug}>
                {index === 3 ? (
                  <AdZoneGroup
                    categoryId={article.category?.id}
                    categorySlug={article.category?.slug}
                    className="ad-zone-group-feed"
                    slotKeys={categoryInFeedSlots}
                  />
                ) : null}
                <ArticleCard article={article} variant="list" />
              </Fragment>
            ))}
          </div>
        ) : (
          <div className="empty-state">
            <strong>{emptyTitle}</strong>
            <p>{emptyDescription}</p>
          </div>
        )}
      </section>

      <nav className="pagination" aria-label="Пагинација">
        {articles.links.prev ? <Link href={archivePageHref(path, articles.meta.current_page - 1)}>Претходна</Link> : null}
        <span>
          {archivePaginationSummary(articles.meta) ||
            archivePaginationLabel(articles.meta.current_page, articles.meta.last_page)}
        </span>
        {articles.links.next ? <Link href={archivePageHref(path, articles.meta.current_page + 1)}>Следна</Link> : null}
      </nav>
    </main>
  );
}
