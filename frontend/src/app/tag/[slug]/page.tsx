import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Fragment } from "react";
import { AdZone } from "@/components/AdZone";
import { AdZoneGroup } from "@/components/AdZoneGroup";
import { ArticleCard } from "@/components/ArticleCard";
import { SiteHeader } from "@/components/SiteHeader";
import { getPublicSiteSettings, getTagArticles } from "@/lib/api/client";
import { archiveAdZones, archiveIntroCopy, archivePaginationSummary, archiveSectionTitle } from "@/lib/archive/archivePresentation";
import { archiveResultsSummary } from "@/lib/articles/articlePresentation";
import { canonicalUrlForPath } from "@/lib/config/runtime";
import { archivePageJsonLd } from "@/lib/seo/jsonLd";
import { openGraphImages } from "@/lib/seo/metadataImages";
import { archiveCanonicalInput, archiveEmptyState, archivePageHref, archivePaginationLabel, archiveTitleWithPage } from "@/lib/seo/pagination";
import { metadataTitleWithoutBrand, publicSiteSettingsDefaults } from "@/lib/settings/siteSettings";

export const dynamic = "force-dynamic";
export const revalidate = 0;

type TagPageProps = {
  params: Promise<{ slug: string }>;
  searchParams: Promise<{ page?: string }>;
};

function pageNumber(value?: string) {
  const page = Number(value ?? 1);

  return Number.isFinite(page) && page > 0 ? page : 1;
}

function archiveBreadcrumbItems(
  items: Array<Record<string, unknown>> | null | undefined,
  fallback: Array<{ name: string; url: string | null }>,
) {
  const breadcrumbs = (items ?? [])
    .map((item) => ({
      name: typeof item.name === "string" ? item.name : null,
      url: typeof item.url === "string" ? item.url : null,
    }))
    .filter((item): item is { name: string; url: string | null } => Boolean(item.name));

  return breadcrumbs.length > 0 ? breadcrumbs : fallback;
}

export async function generateMetadata({ params, searchParams }: TagPageProps): Promise<Metadata> {
  const [{ slug }, { page: pageParam }] = await Promise.all([params, searchParams]);
  const [archive, publicSettings] = await Promise.all([
    getTagArticles(slug, pageNumber(pageParam)),
    getPublicSiteSettings(),
  ]);

  if (!archive) {
    return {};
  }

  const settings = publicSiteSettingsDefaults(publicSettings);
  const { tag } = archive.data;
  const canonicalInput = archiveCanonicalInput(tag.url, pageParam, tag.seo.canonical_url);
  const canonical = canonicalUrlForPath(canonicalInput.path, canonicalInput.explicitCanonical);
  const title = archiveTitleWithPage(metadataTitleWithoutBrand(tag.seo.title ?? `#${tag.name}`, settings.siteName), pageParam);

  return {
    title,
    description: tag.seo.meta_description ?? `Објави означени со ${tag.name}.`,
    alternates: {
      canonical,
    },
    openGraph: {
      title: archiveTitleWithPage(metadataTitleWithoutBrand(tag.seo.og_title ?? tag.seo.title ?? `#${tag.name}`, settings.siteName), pageParam),
      description: tag.seo.og_description ?? tag.seo.meta_description ?? `Објави означени со ${tag.name}.`,
      url: canonical,
      images: openGraphImages(tag.seo.og_image?.url, settings.defaultOgImage, settings.siteUrl),
    },
  };
}

export default async function TagPage({ params, searchParams }: TagPageProps) {
  const [{ slug }, { page: pageParam }] = await Promise.all([params, searchParams]);
  const archive = await getTagArticles(slug, pageNumber(pageParam));

  if (!archive) {
    notFound();
  }

  const { tag, articles } = archive.data;
  const canonicalInput = archiveCanonicalInput(tag.url, pageParam, tag.seo.canonical_url);
  const canonical = canonicalUrlForPath(canonicalInput.path, canonicalInput.explicitCanonical);
  const breadcrumbs = archiveBreadcrumbItems(tag.seo.breadcrumbs, [
    { name: "Почетна", url: "/" },
    { name: `#${tag.name}`, url: tag.url },
  ]);
  const description = tag.seo.meta_description ?? `Објави означени со ${tag.name}.`;
  const intro = archiveIntroCopy("tag", tag.name, tag.seo.meta_description, articles.meta.total);
  const emptyState = archiveEmptyState("tag");
  const adZones = archiveAdZones("tag");

  return (
    <main className="site-shell">
      <SiteHeader />

      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{
          __html: JSON.stringify(
            archivePageJsonLd({
              name: `#${tag.name}`,
              description,
              canonicalUrl: canonical,
              breadcrumbs,
            }),
          ),
        }}
      />

      <AdZone zoneKey={adZones.top} />

      <section className="page-heading page-heading-wide">
        {breadcrumbs.length > 0 ? (
          <nav className="breadcrumb-list" aria-label="Breadcrumb">
            {breadcrumbs.map((item, index) => (
              <span key={`${item.name}-${index}`}>
                {item.url && index < breadcrumbs.length - 1 ? <Link href={item.url}>{item.name}</Link> : item.name}
              </span>
            ))}
          </nav>
        ) : null}
        <p className="kicker">Таг</p>
        <h1>#{tag.name}</h1>
        <p>{intro}</p>
        <strong className="archive-summary">{archiveResultsSummary(`#${tag.name}`, articles.meta.total)}</strong>
      </section>

      <section className="article-list archive-list archive-feed" aria-label={archiveSectionTitle("tag", tag.name)}>
        {articles.data.length > 0 ? (
          articles.data.map((article, index) => (
            <Fragment key={article.slug}>
              {index === 3 ? <AdZoneGroup className="ad-zone-group-feed" slotKeys={adZones.inFeedSlots} /> : null}
              <ArticleCard article={article} variant="list" />
            </Fragment>
          ))
        ) : (
          <div className="empty-state">
            <strong>{emptyState.title}</strong>
            <p>{emptyState.description}</p>
          </div>
        )}
      </section>

      <nav className="pagination" aria-label="Пагинација">
        {articles.links.prev ? (
          <Link href={archivePageHref(tag.url, articles.meta.current_page - 1)}>Претходна</Link>
        ) : null}
        <span>{archivePaginationSummary(articles.meta) || archivePaginationLabel(articles.meta.current_page, articles.meta.last_page)}</span>
        {articles.links.next ? (
          <Link href={archivePageHref(tag.url, articles.meta.current_page + 1)}>Следна</Link>
        ) : null}
      </nav>
    </main>
  );
}
