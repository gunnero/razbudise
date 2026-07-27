import { adZoneSlotKeys } from "../ads/adSlots.mjs";

export function homepageLeadLayout(sections) {
  const featured = sections.find((section) => section.layout === "hero" && section.articles?.length > 0)
    ?? sections.find((section) => section.articles?.length > 0)
    ?? null;
  const featuredArticles = featured?.articles ?? [];
  const leadArticle = featuredArticles.find((article) => Boolean(article?.featured_image?.url))
    ?? featuredArticles[0]
    ?? null;
  const featuredIndex = featured ? sections.indexOf(featured) : -1;
  const identity = (article) => article?.id ?? article?.slug;
  const seen = new Set(leadArticle ? [identity(leadArticle)] : []);
  const supportingArticles = [];
  const candidates = [
    ...featuredArticles.filter((article) => article !== leadArticle),
    ...sections
      .slice(featuredIndex + 1)
      .filter((section) => section !== featured && section.type !== "promo_cards")
      .flatMap((section) => section.articles ?? []),
  ];

  for (const article of candidates) {
    const articleIdentity = identity(article);

    if (!articleIdentity || seen.has(articleIdentity)) {
      continue;
    }

    seen.add(articleIdentity);
    supportingArticles.push(article);
  }

  const sideArticles = supportingArticles.slice(0, 8);
  const sideIdentities = new Set(sideArticles.map(identity));
  const remainingFeatured = featuredArticles
    .filter((article) => article !== leadArticle && !sideIdentities.has(identity(article)))
    .slice(0, 3);

  return {
    featured,
    leadArticle,
    sideArticles,
    remainingFeatured,
  };
}

export function visibleHomepageSections(sections, featuredKey, leadPackageArticles = []) {
  const featured = sections.find((section) => section.key === featuredKey);
  const seen = new Set([
    ...(featured?.articles ?? []),
    ...leadPackageArticles,
  ].map((article) => article.id ?? article.slug));

  return sections
    .filter((section) => section.key !== featuredKey && section.type !== "promo_cards" && section.articles?.length > 0)
    .map((section) => ({
      ...section,
      articles: section.articles.filter((article) => {
        const identity = article.id ?? article.slug;

        if (seen.has(identity)) {
          return false;
        }

        seen.add(identity);
        return true;
      }).slice(0, 6),
    }))
    .filter((section) => section.articles.length > 0)
    .slice(0, 5);
}

export function homepagePromoSections(sections) {
  return sections.filter((section) => section.type === "promo_cards" && section.promo_cards?.length > 0);
}

export function homepageAdZoneAfter(section, index) {
  if (section?.ad_zone_after) {
    return section.ad_zone_after;
  }

  if (index === 0) {
    return "home_in_feed_1";
  }

  if (index === 1) {
    return "home_in_feed_2";
  }

  if (index === 2) {
    return "home_in_feed_3";
  }

  return null;
}

export function homepageAdSlotsAfter(section, index) {
  return adZoneSlotKeys(homepageAdZoneAfter(section, index));
}

export function homepageSectionGridClassName(section) {
  if (section?.layout === "list") {
    return "article-list";
  }

  const classes = ["article-grid"];

  if (section?.layout === "compact") {
    classes.push("article-grid-compact");
  }

  const count = Array.isArray(section?.articles) ? section.articles.length : 0;

  if (count === 1) {
    classes.push("article-grid-single");
  } else if (count === 2) {
    classes.push("article-grid-count-2");
  } else if (count === 4) {
    classes.push("article-grid-count-4");
  }

  return classes.join(" ");
}

export function mobileHomepageOrder(sections) {
  const { featured, leadArticle } = homepageLeadLayout(sections);
  const visibleSections = visibleHomepageSections(sections, featured?.key);
  const order = [];

  if (leadArticle) {
    order.push("lead-story");
  }

  order.push("home-top-billboard", "latest-stories");

  for (const section of homepagePromoSections(sections)) {
    order.push(`promo:${section.key}`);
  }

  for (const section of visibleSections) {
    order.push(`section:${section.key}`);
  }

  order.push("newsletter", "footer");

  return order;
}
