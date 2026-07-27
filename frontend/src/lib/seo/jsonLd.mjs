function absoluteUrl(value, baseUrl) {
  if (!value) {
    return undefined;
  }

  return new URL(value, baseUrl).toString();
}

function cleanObject(input) {
  return Object.fromEntries(Object.entries(input).filter(([, value]) => value !== undefined && value !== null && value !== ""));
}

function imageObjects(image, baseUrl) {
  const imageUrl = absoluteUrl(image?.url, baseUrl);

  if (!imageUrl) {
    return undefined;
  }

  return [
    cleanObject({
      "@type": "ImageObject",
      url: imageUrl,
      width: image?.width ?? undefined,
      height: image?.height ?? undefined,
    }),
  ];
}

function firstImageWithUrl(...images) {
  return images.find((image) => Boolean(image?.url));
}

function namesList(items) {
  const names = (items ?? [])
    .map((item) => item?.name)
    .filter((name) => typeof name === "string" && name.trim() !== "")
    .map((name) => name.trim());

  return names.length > 0 ? names : undefined;
}

function breadcrumbList(items, baseUrl) {
  const breadcrumbs = (items ?? [])
    .filter((item) => item?.name)
    .map((item, index) => cleanObject({
      "@type": "ListItem",
      position: index + 1,
      name: item.name,
      item: absoluteUrl(item.url, baseUrl) ?? baseUrl,
    }));

  if (breadcrumbs.length === 0) {
    return undefined;
  }

  return {
    "@type": "BreadcrumbList",
    itemListElement: breadcrumbs,
  };
}

function publisher(canonicalUrl) {
  return {
    "@type": "Organization",
    name: "Разбуди се",
    url: absoluteUrl("/", canonicalUrl),
  };
}

export function authorProfileJsonLd(author, canonicalUrl) {
  const breadcrumbs = author.seo?.breadcrumbs ?? [
    { name: "Почетна", url: "/" },
    { name: author.name, url: canonicalUrl },
  ];

  return cleanObject({
    "@context": "https://schema.org",
    "@type": "ProfilePage",
    url: canonicalUrl,
    mainEntity: cleanObject({
      "@type": "Person",
      name: author.name,
      jobTitle: author.job_title,
      description: author.short_bio ?? author.full_bio,
      image: absoluteUrl(author.avatar?.url ?? author.seo?.og_image?.url, canonicalUrl),
      url: canonicalUrl,
      sameAs: Object.values(author.social_links ?? {}).filter(Boolean),
    }),
    breadcrumb: breadcrumbList(breadcrumbs, canonicalUrl),
  });
}

export function articleJsonLd(article, canonicalUrl) {
  const image = firstImageWithUrl(article.featured_image, article.seo?.og_image);

  return cleanObject({
    "@context": "https://schema.org",
    "@type": "NewsArticle",
    headline: article.title,
    description: article.seo?.meta_description ?? article.excerpt,
    image: imageObjects(image, canonicalUrl),
    datePublished: article.published_at,
    dateModified: article.updated_at ?? article.published_at,
    author: article.author?.name
      ? cleanObject({
          "@type": "Person",
          name: article.author.name,
          url: absoluteUrl(article.author.url, canonicalUrl),
        })
      : undefined,
    publisher: publisher(canonicalUrl),
    mainEntityOfPage: {
      "@type": "WebPage",
      "@id": canonicalUrl,
    },
    url: canonicalUrl,
    articleSection: article.category?.name,
    keywords: namesList(article.tags),
    isAccessibleForFree: !article.is_sponsored,
    breadcrumb: breadcrumbList(article.seo?.breadcrumbs, canonicalUrl),
  });
}

export function archivePageJsonLd(input) {
  return cleanObject({
    "@context": "https://schema.org",
    "@type": "CollectionPage",
    name: input.name,
    description: input.description,
    url: input.canonicalUrl,
    breadcrumb: breadcrumbList(input.breadcrumbs, input.canonicalUrl),
  });
}
