import type { Metadata } from "next";
import Link from "next/link";
import { Fragment } from "react";

import { AdZone } from "@/components/AdZone";
import { AdZoneGroup } from "@/components/AdZoneGroup";
import { ArticleCard } from "@/components/ArticleCard";
import { EditorialPageHero, PublicPageShell } from "@/components/public/PublicPage";
import { adZoneSlotKeys } from "@/lib/ads/adSlots";
import { getCategories, searchArticles } from "@/lib/api/client";
import { searchFilterUrl, searchResultsHeading } from "@/lib/articles/articlePresentation";
import { activeSearchFilterChips, searchEmptyState } from "@/lib/search/searchPresentation";

export const dynamic = "force-dynamic";
export const revalidate = 0;

type SearchPageProps = { searchParams: Promise<Record<string, string | undefined>> };

const articleTypes = [
  ["Вести", "news"], ["Интервју", "interview"], ["Колумни", "column"], ["Настани", "event"], ["Водичи", "guide"], ["ПР", "pr"], ["Спонзорирано", "sponsored"],
] as const;

export const metadata: Metadata = {
  title: "Пребарување",
  description: "Пребарајте по тема, автор, рубрика или збор низ објавите на Разбуди се.",
  alternates: { canonical: "/search" },
};

export default async function SearchPage({ searchParams }: SearchPageProps) {
  const params = await searchParams;
  const query = params.query?.trim() ?? "";
  const page = Math.max(1, Number(params.page) || 1);
  const requestFilters = { author: params.author ?? "", category: params.category ?? "", published_from: params.published_from ?? "", published_to: params.published_to ?? "", sponsored: params.sponsored ?? "", tag: params.tag ?? "", type: params.type ?? "" };
  const [results, categoryResponse] = await Promise.all([
    query ? searchArticles(query, page, requestFilters).catch(() => null) : Promise.resolve(null),
    getCategories(1, 12).catch(() => null),
  ]);
  const categories = results?.data.filters.categories ?? categoryResponse?.data ?? [];
  const selected = results?.data.filters.selected;
  const articles = results?.data.articles;
  const hasFilters = Boolean(selected && (selected.category || selected.author || selected.type || selected.sponsored || selected.published_from || selected.published_to || selected.tag));
  const filterState = selected ? { ...selected, sponsored: selected.sponsored ? "1" : null, query } : { query };
  const activeChips = results ? activeSearchFilterChips({ selected: results.data.filters.selected, categories: results.data.filters.categories, tags: results.data.filters.tags, authors: results.data.filters.authors }) : [];
  const feedSlots = adZoneSlotKeys("category_in_feed");

  return (
    <PublicPageShell className="public-search-page">
      <EditorialPageHero eyebrow="ПРЕБАРУВАЊЕ" title="Што сакате да прочитате?" description="Пребарајте низ приказните, интервјуата, настаните и корисните теми на Разбуди се." actions={
        <form className="public-search-form" action="/search">
          <label htmlFor="public-search-query">Пребарај содржина</label>
          <div><input id="public-search-query" name="query" defaultValue={query} placeholder="Пребарај по тема, автор, рубрика или збор..." required /><button data-public-event="search_submitted" type="submit">Пребарај</button></div>
        </form>
      } />
      <AdZone zoneKey="search_results_top" />

      {!query ? <section className="public-search-results public-search-start"><p className="kicker">ИСТРАЖИ РУБРИКИ</p><h2>Започнете со тема</h2>{categories.length ? <div className="public-search-categories">{categories.map((category) => <Link href={`/category/${category.slug}`} key={category.slug}>{category.name}</Link>)}</div> : <p>Внесете збор или фраза во полето погоре.</p>}</section> : null}

      {query && !results ? <section className="public-search-results public-search-state" role="alert"><p className="kicker">ПРЕБАРУВАЊЕ</p><h2>Пребарувањето моментално не е достапно</h2><p>Обидете се повторно. Вашиот поим останува во адресата и во полето за пребарување.</p></section> : null}

      {query && results && articles ? <section className="public-search-results" aria-labelledby="search-results-heading">
        <header className="public-search-results-header"><div><p className="kicker">РЕЗУЛТАТИ</p><h2 id="search-results-heading">{searchResultsHeading(query, articles.meta.total)}</h2></div><span>{articles.meta.total} {articles.meta.total === 1 ? "резултат" : "резултати"}</span></header>
        <div className="public-search-filters" aria-label="Филтри">
          {activeChips.map((chip) => <span className="filter-chip filter-chip-active" key={chip.key}>{chip.label}</span>)}
          {articleTypes.map(([label, value]) => <Link data-public-event="search_filter_applied" data-content-key={value} className={selected?.type === value ? "filter-chip filter-chip-active" : "filter-chip"} href={searchFilterUrl({ ...filterState, page: 1, type: value })} key={value}>{label}</Link>)}
          {categories.slice(0, 8).map((category) => <Link data-public-event="search_filter_applied" data-content-key={category.slug} className={selected?.category === category.slug ? "filter-chip filter-chip-active" : "filter-chip"} href={searchFilterUrl({ ...filterState, category: category.slug, page: 1 })} key={category.slug}>{category.name}</Link>)}
          {hasFilters ? <Link className="filter-chip filter-chip-reset" href={searchFilterUrl({ query, page: 1 })}>Исчисти</Link> : null}
        </div>
        {articles.data.length ? <div className="article-list archive-list archive-feed">{articles.data.map((article, index) => <Fragment key={article.slug}>{index === 3 ? <AdZoneGroup className="ad-zone-group-feed" slotKeys={feedSlots} /> : null}<div data-public-event="search_result_clicked" data-content-key={article.slug}><ArticleCard article={article} variant="list" /></div></Fragment>)}</div> : <div className="public-search-state" data-public-event="search_empty_result"><h2>{searchEmptyState(query, hasFilters).title}</h2><p>{searchEmptyState(query, hasFilters).description} Проверете го правописот, употребете помалку зборови или разгледајте ги рубриките.</p></div>}
        <nav className="pagination" aria-label="Пагинација">{articles.links.prev ? <Link href={searchFilterUrl({ ...filterState, page: articles.meta.current_page - 1 })}>Претходна</Link> : null}<span>Страница {articles.meta.current_page} од {articles.meta.last_page}</span>{articles.links.next ? <Link href={searchFilterUrl({ ...filterState, page: articles.meta.current_page + 1 })}>Следна</Link> : null}</nav>
      </section> : null}
    </PublicPageShell>
  );
}
