import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArticleBody } from "@/components/ArticleBody";
import { ArticleViewTracker } from "@/components/ArticleViewTracker";
import { AdZone } from "@/components/AdZone";
import { AdZoneGroup } from "@/components/AdZoneGroup";
import { ArticleCard } from "@/components/ArticleCard";
import { ArticleAuthorCard, ArticleReadingSummary } from "@/components/ArticleEnhancements";
import {
  EventGuideHighlights,
  EventGuidePracticalNotes,
  EventGuideQuickFacts,
  EventGuideSources,
} from "@/components/EventGuideArticle";
import { SiteHeader } from "@/components/SiteHeader";
import { adZoneSlotKeys } from "@/lib/ads/adSlots";
import { getArticle, getArticleSignedPreview, getPublicSiteSettings, getRelatedArticles } from "@/lib/api/client";
import { articleBadge, articleHeroMetaItems, articleImagePresentation, relatedArticlesHeading, sponsoredCampaignRangeLabel } from "@/lib/articles/articlePresentation";
import { eventSchemaItems, isEventGuideArticle } from "@/lib/articles/eventGuidePresentation";
import { mobileArticleFlow, previewBanner, previewMetadata } from "@/lib/articles/previewPresentation";
import { canonicalUrlForPath } from "@/lib/config/runtime";
import { articleJsonLd } from "@/lib/seo/jsonLd";
import { openGraphImages } from "@/lib/seo/metadataImages";
import { publicSiteSettingsDefaults } from "@/lib/settings/siteSettings";

export const dynamic = "force-dynamic";
export const revalidate = 0;

type ArticlePageProps = {
  params: Promise<{ slug: string }>;
  searchParams?: Promise<{ preview_path?: string }>;
};

async function getArticleResponse(slug: string, previewPath?: string) {
  return previewPath ? getArticleSignedPreview(previewPath) : getArticle(slug);
}

function breadcrumbItems(article: { seo: { breadcrumbs: Array<Record<string, unknown>> | null } }) {
  return (article.seo.breadcrumbs ?? [])
    .map((item) => ({
      name: typeof item.name === "string" ? item.name : null,
      url: typeof item.url === "string" ? item.url : null,
    }))
    .filter((item) => item.name);
}

export async function generateMetadata({ params, searchParams }: ArticlePageProps): Promise<Metadata> {
  const { slug } = await params;
  const { preview_path: previewPath } = searchParams ? await searchParams : {};
  const [articleResponse, publicSettings] = await Promise.all([
    getArticleResponse(slug, previewPath),
    getPublicSiteSettings(),
  ]);

  if (!articleResponse) {
    return {};
  }

  const settings = publicSiteSettingsDefaults(publicSettings);
  const article = articleResponse.data;
  const canonical = canonicalUrlForPath(`/${article.slug}`, article.seo.canonical_url);
  const image = article.seo.og_image?.url ?? article.featured_image?.url ?? undefined;

  return {
    title: article.seo.title ?? article.title,
    description: article.seo.meta_description ?? article.excerpt ?? undefined,
    alternates: {
      canonical,
    },
    openGraph: {
      title: article.seo.og_title ?? article.seo.title ?? article.title,
      description: article.seo.og_description ?? article.seo.meta_description ?? article.excerpt ?? undefined,
      url: canonical,
      images: openGraphImages(image, settings.defaultOgImage, settings.siteUrl),
      type: "article",
      publishedTime: article.published_at ?? undefined,
    },
    ...previewMetadata(Boolean(previewPath)),
  };
}

export default async function ArticlePage({ params, searchParams }: ArticlePageProps) {
  const { slug } = await params;
  const { preview_path: previewPath } = searchParams ? await searchParams : {};
  const isPreview = Boolean(previewPath);
  const articleResponse = await getArticleResponse(slug, previewPath);

  if (!articleResponse) {
    notFound();
  }

  const article = articleResponse.data;
  const relatedArticles = isPreview ? [] : await getRelatedArticles(article.slug, 3);
  const canonical = canonicalUrlForPath(`/${article.slug}`, article.seo.canonical_url);
  const eventGuide = isEventGuideArticle(article);
  const eventSchemas = eventSchemaItems(article, canonical);
  const heroMetaItems = articleHeroMetaItems(article);
  const disclosure = article.sponsored_content?.disclosure_label ?? articleBadge(article);
  const breadcrumbs = breadcrumbItems(article);
  const campaignRange = sponsoredCampaignRangeLabel(
    article.sponsored_content?.campaign_start_at,
    article.sponsored_content?.campaign_end_at,
  );
  const featuredImage = articleImagePresentation(article);
  const preview = previewBanner(isPreview);
  const articleFlow = mobileArticleFlow({
    hasFeaturedImage: Boolean(article.featured_image?.url),
    hasRelated: relatedArticles.length > 0,
  });
  const articleSidebarTopSlots = adZoneSlotKeys("article_sidebar_top");
  const articleSidebarStickySlots = adZoneSlotKeys("article_sidebar_sticky");

  return (
    <main className="site-shell">
      <SiteHeader />

      {!isPreview ? <ArticleViewTracker slug={article.slug} /> : null}

      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{
          __html: JSON.stringify(articleJsonLd(article, canonical)),
        }}
      />
      {eventSchemas.map((schema, index) => (
        <script
          dangerouslySetInnerHTML={{ __html: JSON.stringify(schema) }}
          key={`event-schema-${index}`}
          type="application/ld+json"
        />
      ))}

      <article className={eventGuide ? "article-detail article-detail-event-guide" : "article-detail"} data-mobile-flow={articleFlow.join(" ")}>
        {breadcrumbs.length > 0 ? (
          <nav className="breadcrumb-list" aria-label="Breadcrumb">
            {breadcrumbs.map((item, index) => (
              <span key={`${item.name}-${index}`}>
                {item.url && index < breadcrumbs.length - 1 ? <Link href={item.url}>{item.name}</Link> : item.name}
              </span>
            ))}
          </nav>
        ) : null}

        <div className="article-content-layout">
          <div className="article-main-column">
            <header className="article-hero">
              <p className="kicker">{article.category?.name ?? "Објава"}</p>
              <h1>{article.title}</h1>
              {article.excerpt ? <p className="article-excerpt">{article.excerpt}</p> : null}
              <div className="article-meta">
                {heroMetaItems.map((item) =>
                  item.href ? (
                    <Link href={item.href} key={item.key}>
                      {item.label}
                    </Link>
                  ) : (
                    <span key={item.key}>{item.label}</span>
                  ),
                )}
              </div>
              {eventGuide ? <EventGuideQuickFacts article={article} /> : null}
              {preview ? (
                <div className="sponsor-label preview-label" role="status">
                  <strong>{preview.label}</strong>
                  <span>{preview.message}</span>
                </div>
              ) : null}
              {disclosure ? <strong className="sponsor-label">{disclosure}</strong> : null}
            </header>

            <div className="article-reading-frame">
              <AdZone
                articleId={article.id}
                categoryId={article.category?.id}
                categorySlug={article.category?.slug}
                zoneKey="article_after_title"
              />

              {article.sponsored_content ? (
                <aside className="sponsor-panel" aria-label="Спонзорирана содржина">
                  <div>
                    <p className="kicker">{article.sponsored_content.disclosure_label}</p>
                    <strong>{article.sponsored_content.sponsor_name}</strong>
                  </div>
                  {article.sponsored_content.sponsor_url ? (
                    <a href={article.sponsored_content.sponsor_url} rel="nofollow sponsored noreferrer" target="_blank">
                      Посети партнер
                    </a>
                  ) : null}
                  {campaignRange ? <small>{campaignRange}</small> : null}
                </aside>
              ) : null}

              {featuredImage.hasImage && featuredImage.url ? (
                <figure className="article-featured-media">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    className="article-featured-image"
                    src={featuredImage.url}
                    alt={featuredImage.alt}
                    style={featuredImage.focalPointStyle}
                  />
                  {featuredImage.caption ? <figcaption>{featuredImage.caption}</figcaption> : null}
                </figure>
              ) : null}

              {eventGuide ? <EventGuideHighlights article={article} /> : null}
              {!eventGuide ? <ArticleReadingSummary article={article} /> : null}
              <ArticleBody article={article} />
              {eventGuide ? (
                <>
                  <EventGuidePracticalNotes article={article} />
                  <EventGuideSources article={article} />
                </>
              ) : null}
              <ArticleAuthorCard article={article} />
            </div>

            {article.tags.length > 0 ? (
              <nav className="tag-list" aria-label="Тагови">
                {article.tags.map((tag) => (
                  <Link href={tag.url} key={tag.slug}>
                    #{tag.name}
                  </Link>
                ))}
              </nav>
            ) : null}

            <AdZone
              articleId={article.id}
              categoryId={article.category?.id}
              categorySlug={article.category?.slug}
              zoneKey="article_before_related"
            />
          </div>

          <aside className="article-ad-rail" aria-label="Реклами во статија">
            <AdZoneGroup
              articleId={article.id}
              categoryId={article.category?.id}
              categorySlug={article.category?.slug}
              slotKeys={articleSidebarTopSlots}
            />
            <div className="article-ad-rail-sticky">
              <AdZoneGroup
                articleId={article.id}
                categoryId={article.category?.id}
                categorySlug={article.category?.slug}
                slotKeys={articleSidebarStickySlots}
              />
            </div>
          </aside>
        </div>

        {relatedArticles.length > 0 ? (
          <section className="article-related-section article-section" aria-labelledby="related-articles-heading">
            <div className="section-title-row">
              <div>
                <p className="kicker">Поврзано</p>
                <h2 id="related-articles-heading">{relatedArticlesHeading(relatedArticles.length)}</h2>
              </div>
            </div>
            <div className="article-grid article-grid-compact">
              {relatedArticles.map((relatedArticle) => (
                <ArticleCard article={relatedArticle} key={relatedArticle.slug} variant="compact" />
              ))}
            </div>
          </section>
        ) : null}
      </article>
    </main>
  );
}
