import assert from "node:assert/strict";
import test from "node:test";

import { archivePageJsonLd, articleJsonLd, authorProfileJsonLd } from "./jsonLd.mjs";

test("articleJsonLd includes hardened Article schema fields", () => {
  const schema = articleJsonLd(
    {
      title: "Голема приказна",
      excerpt: "Краток опис.",
      published_at: "2026-06-20T08:00:00.000000Z",
      updated_at: "2026-06-21T09:00:00.000000Z",
      is_sponsored: true,
      featured_image: {
        url: "https://razbudise.mk/storage/cover.jpg",
        width: 1200,
        height: 800,
      },
      seo: {
        meta_description: "SEO опис.",
        og_image: null,
        breadcrumbs: [
          { name: "Почетна", url: "/" },
          { name: "Вести", url: "/category/vesti" },
          { name: "Голема приказна", url: "/golema-prikazna" },
        ],
      },
      author: {
        name: "Разбуди Се Редакција",
        url: "/profil/razbudi-se-redakcija",
      },
      category: {
        name: "Вести",
        url: "/category/vesti",
      },
      tags: [
        { name: "Култура", url: "/tag/kultura" },
        { name: "Битола", url: "/tag/bitola" },
      ],
    },
    "https://razbudise.mk/golema-prikazna",
  );

  assert.equal(schema["@type"], "NewsArticle");
  assert.equal(schema.headline, "Голема приказна");
  assert.equal(schema.datePublished, "2026-06-20T08:00:00.000000Z");
  assert.equal(schema.dateModified, "2026-06-21T09:00:00.000000Z");
  assert.deepEqual(schema.image, [{
    "@type": "ImageObject",
    url: "https://razbudise.mk/storage/cover.jpg",
    width: 1200,
    height: 800,
  }]);
  assert.equal(schema.author.name, "Разбуди Се Редакција");
  assert.equal(schema.author.url, "https://razbudise.mk/profil/razbudi-se-redakcija");
  assert.equal(schema.isAccessibleForFree, false);
  assert.equal(schema.publisher.name, "Разбуди се");
  assert.equal(schema.articleSection, "Вести");
  assert.deepEqual(schema.keywords, ["Култура", "Битола"]);
  assert.equal(schema.breadcrumb.itemListElement[1].item, "https://razbudise.mk/category/vesti");
});

test("articleJsonLd falls back to SEO Open Graph image when featured image has no URL", () => {
  const schema = articleJsonLd(
    {
      title: "Приказна без главна фотографија",
      excerpt: "Краток опис.",
      published_at: "2026-06-22T08:00:00.000000Z",
      updated_at: null,
      is_sponsored: false,
      featured_image: {
        url: null,
        width: null,
        height: null,
      },
      seo: {
        meta_description: "SEO опис.",
        og_image: {
          url: "/storage/og-fallback.jpg",
          width: 1200,
          height: 630,
        },
        breadcrumbs: [],
      },
      author: null,
    },
    "https://razbudise.mk/prikazna-bez-glavna-fotografija",
  );

  assert.deepEqual(schema.image, [{
    "@type": "ImageObject",
    url: "https://razbudise.mk/storage/og-fallback.jpg",
    width: 1200,
    height: 630,
  }]);
});

test("authorProfileJsonLd includes ProfilePage, Person, and breadcrumb schema", () => {
  const schema = authorProfileJsonLd(
    {
      name: "Марија Петрова",
      slug: "marija-petrova",
      short_bio: "Авторка за култура.",
      full_bio: null,
      job_title: "Колумнист",
      avatar: { url: "/storage/authors/marija.jpg" },
      social_links: {
        website: "https://example.test/marija",
        instagram: "https://instagram.example/marija",
        facebook: null,
        linkedin: null,
        x: null,
      },
      seo: {
        og_image: null,
      },
    },
    "https://razbudise.mk/profil/marija-petrova",
  );

  assert.equal(schema["@type"], "ProfilePage");
  assert.equal(schema.mainEntity["@type"], "Person");
  assert.equal(schema.mainEntity.image, "https://razbudise.mk/storage/authors/marija.jpg");
  assert.deepEqual(schema.mainEntity.sameAs, [
    "https://example.test/marija",
    "https://instagram.example/marija",
  ]);
  assert.deepEqual(
    schema.breadcrumb.itemListElement.map((item) => [item.position, item.name, item.item]),
    [
      [1, "Почетна", "https://razbudise.mk/"],
      [2, "Марија Петрова", "https://razbudise.mk/profil/marija-petrova"],
    ],
  );
});

test("archivePageJsonLd emits category breadcrumb schema", () => {
  const schema = archivePageJsonLd({
    name: "Култура",
    description: "Културни вести и приказни.",
    canonicalUrl: "https://razbudise.mk/category/kultura",
    breadcrumbs: [
      { name: "Почетна", url: "/" },
      { name: "Култура", url: "/category/kultura" },
    ],
  });

  assert.equal(schema["@type"], "CollectionPage");
  assert.equal(schema.breadcrumb["@type"], "BreadcrumbList");
  assert.deepEqual(
    schema.breadcrumb.itemListElement.map((item) => [item.position, item.name, item.item]),
    [
      [1, "Почетна", "https://razbudise.mk/"],
      [2, "Култура", "https://razbudise.mk/category/kultura"],
    ],
  );
});
