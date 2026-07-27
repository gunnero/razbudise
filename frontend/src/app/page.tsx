import type { Metadata } from "next";
import Link from "next/link";
import { AdZone } from "@/components/AdZone";
import { AdZoneGroup } from "@/components/AdZoneGroup";
import { ArticleCard } from "@/components/ArticleCard";
import { SiteHeader } from "@/components/SiteHeader";
import type { HomepageSection } from "@/lib/api/client";
import { getHomepage, getPublicSiteSettings } from "@/lib/api/client";
import { articleBadge, articleImagePresentation } from "@/lib/articles/articlePresentation";
import {
  homepageAdSlotsAfter,
  homepageAdZoneAfter,
  homepageLeadLayout,
  homepagePromoSections,
  homepageSectionGridClassName,
  mobileHomepageOrder,
  visibleHomepageSections,
} from "@/lib/homepage/homepagePresentation";
import { openGraphImages } from "@/lib/seo/metadataImages";
import { publicSiteSettingsDefaults } from "@/lib/settings/siteSettings";

export const dynamic = "force-dynamic";
export const revalidate = 0;

export async function generateMetadata(): Promise<Metadata> {
  const settings = publicSiteSettingsDefaults(await getPublicSiteSettings());

  return {
    alternates: {
      canonical: settings.siteUrl,
    },
    openGraph: {
      title: settings.defaultMetaTitle,
      description: settings.defaultMetaDescription,
      url: settings.siteUrl,
      images: openGraphImages(null, settings.defaultOgImage, settings.siteUrl),
      siteName: settings.siteName,
      locale: "mk_MK",
      type: "website",
    },
  };
}

function homepageSectionArticleVariant(section: HomepageSection): "card" | "list" | "compact" {
  return section.layout === "list" ? "list" : section.layout === "compact" ? "compact" : "card";
}

function leadRailCategory(
  articles: HomepageSection["articles"],
  sections: HomepageSection[],
): HomepageSection["category"] {
  if (articles.length === 0) {
    return null;
  }

  const articleIds = new Set(articles.map((article) => article.id ?? article.slug));
  const source = sections.find((section) => {
    if (!section.category) {
      return false;
    }

    const sectionIds = new Set(section.articles.map((article) => article.id ?? article.slug));
    return [...articleIds].every((id) => sectionIds.has(id));
  });

  return source?.category ?? null;
}

async function HomepageContent({ homepage }: { homepage: Awaited<ReturnType<typeof getHomepage>> }) {
  const allSections = homepage?.data.sections ?? [];
  const { featured, leadArticle, remainingFeatured, sideArticles } = homepageLeadLayout(allSections);
  const promoSections = homepagePromoSections(allSections);
  const mobileOrder = mobileHomepageOrder(allSections);
  const leadImage = articleImagePresentation(leadArticle, { variant: "lead" });
  const sideSplitIndex = Math.ceil(sideArticles.length / 2);
  const leftSideArticles = sideArticles.slice(0, sideSplitIndex);
  const rightSideArticles = sideArticles.slice(sideSplitIndex);
  const leftSideCategory = leadRailCategory(leftSideArticles, allSections);
  const rightSideCategory = leadRailCategory(rightSideArticles, allSections);
  const sections = visibleHomepageSections(
    allSections,
    featured?.key,
    [...(leadArticle ? [leadArticle] : []), ...sideArticles],
  );
  const homepageArticleTotal = allSections.reduce((total, section) => total + section.articles.length, 0);
  const homepageSectionTotal = allSections.filter((section) => section.articles.length > 0).length;

  return (
    <main className="site-shell home-shell" data-mobile-order={mobileOrder.join(" ")}>
      <SiteHeader />
      <AdZone zoneKey="home_top_billboard" />

      {leadArticle ? (
        <section className="lead-grid lead-grid-editorial" aria-labelledby="homepage-heading">
          <article className="lead-story lead-story-primary">
            {leadImage.hasImage && leadImage.url ? (
              <Link aria-label={leadArticle.title} className="lead-image-link" href={leadArticle.url}>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  className="lead-image"
                  src={leadImage.url}
                  alt={leadImage.alt}
                  style={leadImage.focalPointStyle}
                />
              </Link>
            ) : null}
            <div className="lead-story-content">
              <p className="kicker">{featured?.title ?? leadArticle.category?.name ?? "Издвоено"}</p>
              <h1 id="homepage-heading">
                <Link href={leadArticle.url}>{leadArticle.title}</Link>
              </h1>
              {articleBadge(leadArticle) ? <strong className="sponsor-label">{articleBadge(leadArticle)}</strong> : null}
              {leadArticle.excerpt ? <p>{leadArticle.excerpt}</p> : null}
              <small>
                {[leadArticle.category?.name, `${leadArticle.reading_time_minutes} мин. читање`]
                  .filter(Boolean)
                  .join(" · ")}
              </small>
            </div>
          </article>

          {leftSideArticles.length > 0 ? (
            <aside className="lead-side lead-side-left" aria-label="Издвоени објави">
              <div className="lead-side-stories">
                {leftSideArticles.map((article) => (
                  <article key={article.slug}>
                    <p className="kicker">{articleBadge(article) ?? article.category?.name ?? "Објава"}</p>
                    <h2>
                      <Link href={article.url}>{article.title}</Link>
                    </h2>
                    {article.excerpt ? <p>{article.excerpt}</p> : null}
                  </article>
                ))}
              </div>
              {leftSideCategory ? (
                <Link className="lead-side-more" href={leftSideCategory.url}>
                  Повеќе од {leftSideCategory.name} →
                </Link>
              ) : null}
            </aside>
          ) : null}

          {rightSideArticles.length > 0 ? (
            <aside className="lead-side lead-side-right" aria-label="Уште издвоени објави">
              <div className="lead-side-stories">
                {rightSideArticles.map((article) => (
                  <article key={article.slug}>
                    <p className="kicker">{articleBadge(article) ?? article.category?.name ?? "Објава"}</p>
                    <h2>
                      <Link href={article.url}>{article.title}</Link>
                    </h2>
                    {article.excerpt ? <p>{article.excerpt}</p> : null}
                  </article>
                ))}
              </div>
              {rightSideCategory ? (
                <Link className="lead-side-more" href={rightSideCategory.url}>
                  Повеќе од {rightSideCategory.name} →
                </Link>
              ) : null}
            </aside>
          ) : null}
        </section>
      ) : (
        <section className="page-heading page-heading-wide">
          <p className="kicker">Разбуди се</p>
          <h1>Приказните наскоро пристигнуваат.</h1>
          <p>Додај објавени статии и активни homepage секции во админ панелот.</p>
        </section>
      )}

      {leadArticle ? (
        <div className="home-ledger" aria-label="Редакциски преглед">
          <span>Редакциски избор</span>
          <span>{homepageArticleTotal} објави во активните секции</span>
          <span>{homepageSectionTotal} секции за читање</span>
        </div>
      ) : null}

      {promoSections.map((section) => (
        <section className="promo-card-strip" aria-labelledby={`promo-section-${section.key}`} key={section.key}>
          <div className="section-title-row promo-card-strip-heading">
            <div>
              <p className="kicker">{section.name}</p>
              <h2 id={`promo-section-${section.key}`}>{section.title}</h2>
              {section.description ? <p>{section.description}</p> : null}
            </div>
          </div>

          <div className="promo-card-grid">
            {section.promo_cards.map((card) => (
              <Link className="promo-card" href={card.href} key={`${section.key}-${card.href}`}>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img alt={card.alt} src={card.image_url} />
                <span>{card.title}</span>
              </Link>
            ))}
          </div>
        </section>
      ))}

      <AdZone zoneKey={featured?.ad_zone_after ?? "home_after_featured"} />

      {remainingFeatured.length > 0 ? (
        <section className="article-section" aria-labelledby="featured-more-heading">
          <div className="section-title-row">
            <div>
              <p className="kicker">Издвоено</p>
              <h2 id="featured-more-heading">Повеќе од редакцијата</h2>
            </div>
          </div>
          <div className="article-grid">
            {remainingFeatured.map((article) => (
              <ArticleCard article={article} key={article.slug} variant="card" />
            ))}
          </div>
        </section>
      ) : null}

      {sections.map((section, index) => {
        const adZoneAfter = homepageAdZoneAfter(section, index);
        const adZoneAfterSlots = homepageAdSlotsAfter(section, index);
        const sectionArticleVariant = homepageSectionArticleVariant(section);

        return (
        <section className="article-section" aria-labelledby={`home-section-${section.key}`} key={section.key}>
          <div className="section-title-row">
            <div>
              <p className="kicker">{section.category?.name ?? section.name}</p>
              <h2 id={`home-section-${section.key}`}>{section.title}</h2>
              {section.description ? <p>{section.description}</p> : null}
            </div>
            {section.category ? <Link href={section.category.url}>Сите објави</Link> : null}
          </div>

          <div className={homepageSectionGridClassName(section)}>
            {section.articles.map((article) => (
              <ArticleCard article={article} key={article.slug} variant={sectionArticleVariant} />
            ))}
          </div>
          {adZoneAfter ? <AdZoneGroup className="ad-zone-group-feed" slotKeys={adZoneAfterSlots} /> : null}
        </section>
        );
      })}
    </main>
  );
}

export default async function Home() {
  const homepage = await getHomepage();

  return <HomepageContent homepage={homepage} />;
}
