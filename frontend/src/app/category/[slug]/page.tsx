import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Fragment } from "react";
import { AdZone } from "@/components/AdZone";
import { AdZoneGroup } from "@/components/AdZoneGroup";
import { ArticleCard } from "@/components/ArticleCard";
import { SiteHeader } from "@/components/SiteHeader";
import { getCategoryArticles, getPublicSiteSettings } from "@/lib/api/client";
import { archiveAdZones, archiveIntroCopy, archivePaginationSummary, archiveSectionTitle } from "@/lib/archive/archivePresentation";
import { archiveResultsSummary } from "@/lib/articles/articlePresentation";
import { canonicalUrlForPath } from "@/lib/config/runtime";
import { archivePageJsonLd } from "@/lib/seo/jsonLd";
import { openGraphImages } from "@/lib/seo/metadataImages";
import { archiveCanonicalInput, archiveEmptyState, archivePageHref, archivePaginationLabel, archiveTitleWithPage } from "@/lib/seo/pagination";
import { metadataTitleWithoutBrand, publicSiteSettingsDefaults } from "@/lib/settings/siteSettings";

export const dynamic = "force-dynamic";
export const revalidate = 0;

type CategoryPageProps = {
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

export async function generateMetadata({ params, searchParams }: CategoryPageProps): Promise<Metadata> {
  const [{ slug }, { page: pageParam }] = await Promise.all([params, searchParams]);
  const [archive, publicSettings] = await Promise.all([
    getCategoryArticles(slug, pageNumber(pageParam)),
    getPublicSiteSettings(),
  ]);

  if (!archive) {
    return {};
  }

  const settings = publicSiteSettingsDefaults(publicSettings);
  const { category } = archive.data;
  const canonicalInput = archiveCanonicalInput(category.url, pageParam, category.seo.canonical_url);
  const canonical = canonicalUrlForPath(canonicalInput.path, canonicalInput.explicitCanonical);
  const title = archiveTitleWithPage(metadataTitleWithoutBrand(category.seo.title ?? category.name, settings.siteName), pageParam);

  return {
    title,
    description: category.seo.meta_description ?? category.description ?? undefined,
    alternates: {
      canonical,
    },
    openGraph: {
      title: archiveTitleWithPage(metadataTitleWithoutBrand(category.seo.og_title ?? category.seo.title ?? category.name, settings.siteName), pageParam),
      description: category.seo.og_description ?? category.seo.meta_description ?? category.description ?? undefined,
      url: canonical,
      images: openGraphImages(category.seo.og_image?.url, settings.defaultOgImage, settings.siteUrl),
    },
  };
}

export default async function CategoryPage({ params, searchParams }: CategoryPageProps) {
  const [{ slug }, { page: pageParam }] = await Promise.all([params, searchParams]);
  const archive = await getCategoryArticles(slug, pageNumber(pageParam));

  if (!archive) {
    notFound();
  }

  const { category, articles } = archive.data;
  const canonicalInput = archiveCanonicalInput(category.url, pageParam, category.seo.canonical_url);
  const canonical = canonicalUrlForPath(canonicalInput.path, canonicalInput.explicitCanonical);
  const breadcrumbs = archiveBreadcrumbItems(category.seo.breadcrumbs, [
    { name: "Почетна", url: "/" },
    { name: category.name, url: category.url },
  ]);
  const description = category.seo.meta_description ?? category.description;
  const intro = archiveIntroCopy("category", category.name, category.description, articles.meta.total);
  const emptyState = archiveEmptyState("category");
  const adZones = archiveAdZones("category");

  return (
    <main className="site-shell">
      <SiteHeader />

      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{
          __html: JSON.stringify(
            archivePageJsonLd({
              name: category.name,
              description,
              canonicalUrl: canonical,
              breadcrumbs,
            }),
          ),
        }}
      />

      <AdZone categoryId={category.id} categorySlug={category.slug} zoneKey={adZones.top} />

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
        <p className="kicker">Рубрика</p>
        <h1>{category.name}</h1>
        <p>{intro}</p>
        <strong className="archive-summary">{archiveResultsSummary(category.name, articles.meta.total)}</strong>
      </section>

      <section className="article-list archive-list archive-feed" aria-label={archiveSectionTitle("category", category.name)}>
        {articles.data.length > 0 ? (
          articles.data.map((article, index) => (
            <Fragment key={article.slug}>
              {index === 3 ? (
                <AdZoneGroup
                  categoryId={category.id}
                  categorySlug={category.slug}
                  className="ad-zone-group-feed"
                  slotKeys={adZones.inFeedSlots}
                />
              ) : null}
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
          <Link href={archivePageHref(category.url, articles.meta.current_page - 1)}>Претходна</Link>
        ) : null}
        <span>{archivePaginationSummary(articles.meta) || archivePaginationLabel(articles.meta.current_page, articles.meta.last_page)}</span>
        {articles.links.next ? (
          <Link href={archivePageHref(category.url, articles.meta.current_page + 1)}>Следна</Link>
        ) : null}
      </nav>
    </main>
  );
}
