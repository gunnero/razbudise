import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Fragment } from "react";
import { AdZone } from "@/components/AdZone";
import { AdZoneGroup } from "@/components/AdZoneGroup";
import { ArticleCard } from "@/components/ArticleCard";
import { SiteHeader } from "@/components/SiteHeader";
import { adZoneSlotKeys } from "@/lib/ads/adSlots";
import { canonicalUrlForPath } from "@/lib/config/runtime";
import { selectMediaUrl } from "@/lib/media/mediaUrls";
import { authorProfileJsonLd } from "@/lib/seo/jsonLd";
import { openGraphImages } from "@/lib/seo/metadataImages";
import { archiveCanonicalInput, archiveTitleWithPage } from "@/lib/seo/pagination";
import { authorInitials } from "@/lib/authors/authorPresentation";
import { getAuthor, getAuthorArticles, getPublicSiteSettings } from "@/lib/api/client";
import { metadataTitleWithoutBrand, publicSiteSettingsDefaults } from "@/lib/settings/siteSettings";

export const dynamic = "force-dynamic";
export const revalidate = 0;

type AuthorPageProps = {
  params: Promise<{ slug: string }>;
  searchParams: Promise<{ page?: string }>;
};

const socialLabels: Record<string, string> = {
  website: "Веб",
  facebook: "Facebook",
  instagram: "Instagram",
  linkedin: "LinkedIn",
  x: "X",
};

function archivePage(pageParam?: string): number {
  const page = Number(pageParam ?? 1);

  return Number.isFinite(page) && page > 1 ? Math.floor(page) : 1;
}

function authorCanonicalOverride(value: string | null | undefined): string | null {
  if (!value) {
    return null;
  }

  return value.includes("/avtor/") ? null : value;
}

function authorMetaDescription(author: {
  article_count: number;
  full_bio: string | null;
  name: string;
  seo: { meta_description: string | null; og_description: string | null };
  short_bio: string | null;
}): string {
  return (
    author.seo.meta_description ??
    author.short_bio ??
    author.full_bio ??
    `Текстови, профил и најнови објави од ${author.name} на Разбуди се.`
  );
}

export async function generateMetadata({ params, searchParams }: AuthorPageProps): Promise<Metadata> {
  const [{ slug }, { page: pageParam }] = await Promise.all([params, searchParams]);
  const page = archivePage(pageParam);
  const [authorResponse, publicSettings] = await Promise.all([
    getAuthor(slug),
    getPublicSiteSettings(),
  ]);

  if (!authorResponse) {
    return {};
  }

  const settings = publicSiteSettingsDefaults(publicSettings);
  const author = authorResponse.data;
  const canonicalInput = archiveCanonicalInput(`/profil/${author.slug}`, page, authorCanonicalOverride(author.seo.canonical_url));
  const canonical = canonicalUrlForPath(canonicalInput.path, canonicalInput.explicitCanonical);
  const image = author.seo.og_image?.url ?? author.avatar?.url ?? undefined;
  const title = archiveTitleWithPage(metadataTitleWithoutBrand(author.seo.title ?? author.name, settings.siteName), page);
  const openGraphTitle = archiveTitleWithPage(metadataTitleWithoutBrand(author.seo.og_title ?? author.seo.title ?? author.name, settings.siteName), page);
  const description = authorMetaDescription(author);

  return {
    title,
    description,
    alternates: {
      canonical,
    },
    openGraph: {
      title: openGraphTitle,
      description: author.seo.og_description ?? description,
      url: canonical,
      images: openGraphImages(image, settings.defaultOgImage, settings.siteUrl),
      type: "profile",
    },
  };
}

export default async function AuthorPage({ params, searchParams }: AuthorPageProps) {
  const { slug } = await params;
  const { page: pageParam } = await searchParams;
  const page = archivePage(pageParam);
  const authorResponse = await getAuthor(slug);

  if (!authorResponse) {
    notFound();
  }

  const articles = await getAuthorArticles(slug, page);
  const author = authorResponse.data;
  const canonicalInput = archiveCanonicalInput(`/profil/${author.slug}`, page, authorCanonicalOverride(author.seo.canonical_url));
  const canonical = canonicalUrlForPath(canonicalInput.path, canonicalInput.explicitCanonical);
  const socialLinks = Object.entries(author.social_links).filter(([, value]) => Boolean(value));
  const avatarUrl = selectMediaUrl(author.avatar, { variant: "thumbnail" });
  const categoryInFeedSlots = adZoneSlotKeys("category_in_feed");

  return (
    <main className="site-shell">
      <SiteHeader />
      <AdZone zoneKey="author_page_top" />

      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{
          __html: JSON.stringify(authorProfileJsonLd(author, canonical)),
        }}
      />

      <section className="author-profile">
        <div className="avatar-frame avatar-frame-large">
          {avatarUrl ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={avatarUrl} alt={author.avatar?.alt_text ?? author.name} />
          ) : (
            <span aria-label={`Иницијали: ${authorInitials(author.name)}`}>{authorInitials(author.name)}</span>
          )}
        </div>
        <div>
          <p className="kicker">{author.job_title ?? "Автор"}</p>
          <h1>{author.name}</h1>
          {author.short_bio ? <p className="profile-lede">{author.short_bio}</p> : null}
          <p className="profile-count">{author.article_count} објави</p>
          {socialLinks.length > 0 ? (
            <nav className="social-links" aria-label="Социјални линкови">
              {socialLinks.map(([network, url]) => (
                <a href={url as string} key={network} rel="noreferrer" target="_blank">
                  {socialLabels[network] ?? network}
                </a>
              ))}
            </nav>
          ) : null}
        </div>
      </section>

      {author.full_bio ? (
        <section className="profile-bio">
          <h2>Биографија</h2>
          <p>{author.full_bio}</p>
        </section>
      ) : null}

      {author.editorial_focus.length ? (
        <section className="author-profile-focus" aria-labelledby="author-focus-heading">
          <p className="kicker">УРЕДНИЧКИ ФОКУС</p>
          <h2 id="author-focus-heading">Теми што ги покрива {author.name}</h2>
          <ul className="author-focus-list">{author.editorial_focus.map((category) => <li key={category.slug}><Link href={category.url}>{category.name}</Link></li>)}</ul>
        </section>
      ) : null}

      <section className="article-section" aria-labelledby="latest-author-articles">
        <div className="section-title-row">
          <div>
            <p className="kicker">Најново</p>
            <h2 id="latest-author-articles">Објави од {author.name}</h2>
          </div>
        </div>
        {articles.data.length > 0 ? (
          <div className="article-list archive-list archive-feed">
            {articles.data.map((article, index) => (
              <Fragment key={article.slug}>
                {index === 3 ? <AdZoneGroup className="ad-zone-group-feed" slotKeys={categoryInFeedSlots} /> : null}
                <ArticleCard article={article} variant="list" />
              </Fragment>
            ))}
          </div>
        ) : (
          <div className="empty-state">
            <strong>Сè уште нема објавени текстови од овој автор.</strong>
            <p>Кога ќе има јавни објави, тие ќе се појават тука.</p>
          </div>
        )}
      </section>

      <nav className="pagination" aria-label="Пагинација">
        {articles.links.prev ? (
          <Link href={`/profil/${author.slug}?page=${articles.meta.current_page - 1}`}>
            Претходна
          </Link>
        ) : null}
        <span>
          Страница {articles.meta.current_page} од {articles.meta.last_page}
        </span>
        {articles.links.next ? (
          <Link href={`/profil/${author.slug}?page=${articles.meta.current_page + 1}`}>Следна</Link>
        ) : null}
      </nav>
    </main>
  );
}
