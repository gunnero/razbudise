import assert from "node:assert/strict";
import test from "node:test";

import {
  archiveResultsSummary,
  articleBadge,
  articleBodyClassName,
  articleHeroMetaItems,
  articleHeroMetaParts,
  articleImagePresentation,
  articleMetaParts,
  formatArticleDate,
  relatedArticlesHeading,
  searchFilterUrl,
  searchResultsHeading,
  sponsoredCampaignRangeLabel,
} from "./articlePresentation.mjs";

test("articleBadge marks sponsored and PR articles with public Macedonian labels", () => {
  assert.equal(
    articleBadge({ is_sponsored: true, sponsored_label: "Платена промоција", type: "sponsored" }),
    "Платена промоција",
  );
  assert.equal(articleBadge({ is_sponsored: true, type: "sponsored" }), "Спонзорирана објава");
  assert.equal(articleBadge({ is_sponsored: false, type: "pr" }), "ПР текст");
});

test("articleBadge returns null for regular editorial content", () => {
  assert.equal(articleBadge({ is_sponsored: false, type: "editorial" }), null);
});

test("articleMetaParts includes date and reading time when available", () => {
  const parts = articleMetaParts({
    published_at: "2026-06-20T08:00:00.000000Z",
    reading_time_minutes: 4,
  });

  assert.deepEqual(parts, ["20 јуни 2026 г.", "4 мин. читање"]);
});

test("formatArticleDate stays Macedonian when browser Intl locale output differs", () => {
  const originalDateTimeFormat = Intl.DateTimeFormat;

  Intl.DateTimeFormat = function DateTimeFormatMock() {
    return {
      format: () => "June 20, 2026",
    };
  };

  try {
    assert.equal(formatArticleDate("2026-06-20T08:00:00.000000Z"), "20 јуни 2026 г.");
  } finally {
    Intl.DateTimeFormat = originalDateTimeFormat;
  }
});

test("articleHeroMetaParts includes author, category, date, and reading time for article headers", () => {
  assert.deepEqual(
    articleHeroMetaParts({
      author: { name: "Разбуди Се Редакција" },
      category: { name: "Вести" },
      published_at: "2026-06-20T08:00:00.000000Z",
      reading_time_minutes: 4,
    }),
    ["Разбуди Се Редакција", "Вести", "20 јуни 2026 г.", "4 мин. читање"],
  );
});

test("articleHeroMetaItems keeps article byline labels unique and linkable", () => {
  assert.deepEqual(
    articleHeroMetaItems({
      author: { name: "Разбуди Се Редакција", url: "/profil/razbudi-se-redakcija" },
      category: { name: "Вести", url: "/category/vesti" },
      published_at: "2026-06-20T08:00:00.000000Z",
      reading_time_minutes: 4,
    }),
    [
      { href: "/profil/razbudi-se-redakcija", key: "author", label: "Разбуди Се Редакција" },
      { href: "/category/vesti", key: "category", label: "Вести" },
      { href: null, key: "published_at", label: "20 јуни 2026 г." },
      { href: null, key: "reading_time", label: "4 мин. читање" },
    ],
  );

  assert.deepEqual(
    articleHeroMetaItems({
      author: null,
      category: { name: "Вести", url: "/category/vesti" },
      published_at: null,
      reading_time_minutes: 4,
    }),
    [
      { href: "/category/vesti", key: "category", label: "Вести" },
      { href: null, key: "reading_time", label: "4 мин. читање" },
    ],
  );
});

test("sponsoredCampaignRangeLabel formats readable sponsor campaign ranges", () => {
  assert.equal(
    sponsoredCampaignRangeLabel("2026-06-20T08:00:00.000000Z", "2026-06-30T08:00:00.000000Z"),
    "Кампања: 20 јуни 2026 г. - 30 јуни 2026 г.",
  );
  assert.equal(
    sponsoredCampaignRangeLabel("2026-06-20T08:00:00.000000Z", null),
    "Кампања од 20 јуни 2026 г.",
  );
  assert.equal(sponsoredCampaignRangeLabel(null, null), null);
});

test("articleImagePresentation returns featured image display data with focal point and caption", () => {
  assert.deepEqual(
    articleImagePresentation(
      {
        title: "Наслов",
        featured_image: {
          url: "/storage/media/cover.jpg",
          responsive_urls: {
            medium: "/storage/media/cover-medium.jpg",
          },
          alt_text: "Алтернативен опис",
          caption: "Фото опис",
          credit: "Фото кредит",
          focal_point: { x: 42, y: 57 },
        },
      },
      { baseUrl: "http://localhost:8001/api" },
    ),
    {
      hasImage: true,
      url: "http://localhost:8001/storage/media/cover-medium.jpg",
      alt: "Алтернативен опис",
      caption: "Фото опис · Фото кредит",
      focalPointStyle: { objectPosition: "42% 57%" },
      placeholderLabel: null,
    },
  );
});

test("articleImagePresentation gives stable placeholder data when imported content has no image", () => {
  assert.deepEqual(
    articleImagePresentation({
      title: "Увезен текст без слика",
      featured_image: null,
    }),
    {
      hasImage: false,
      url: null,
      alt: "Увезен текст без слика",
      caption: null,
      focalPointStyle: undefined,
      placeholderLabel: "Разбуди се",
    },
  );
});

test("articleBodyClassName marks imported WordPress bodies for visual cleanup styles", () => {
  assert.equal(articleBodyClassName({ old_wordpress_id: 55 }), "article-body article-body-imported");
  assert.equal(articleBodyClassName({ old_url: "https://razbudise.mk/old-post/" }), "article-body article-body-imported");
  assert.equal(articleBodyClassName({ body_html: "<p>Legacy HTML</p>", blocks: [] }), "article-body article-body-imported");
  assert.equal(articleBodyClassName({}), "article-body");
});

test("relatedArticlesHeading gives a stable public heading", () => {
  assert.equal(relatedArticlesHeading(0), null);
  assert.equal(relatedArticlesHeading(3), "Продолжи со читање");
});

test("searchResultsHeading explains empty, singular, and plural search states", () => {
  assert.equal(searchResultsHeading("", 0), "Внеси збор за пребарување");
  assert.equal(searchResultsHeading("Битола", 0), "Нема резултати за „Битола“");
  assert.equal(searchResultsHeading("Битола", 1), "1 резултат за „Битола“");
  assert.equal(searchResultsHeading("Битола", 3), "3 резултати за „Битола“");
});

test("archiveResultsSummary explains empty, singular, and plural archive states", () => {
  assert.equal(archiveResultsSummary("Настани", 0), "Нема објавени текстови во Настани.");
  assert.equal(archiveResultsSummary("Настани", 1), "1 објава во Настани.");
  assert.equal(archiveResultsSummary("Настани", 7), "7 објави во Настани.");
});

test("searchFilterUrl preserves query state and removes empty filters", () => {
  assert.equal(
    searchFilterUrl({
      query: "Битола",
      page: 2,
      category: "zivot",
      tag: "",
      author: "razbudi-se-redakcija",
      type: "interview",
      sponsored: true,
      published_from: "2026-06-01",
      published_to: "2026-06-30",
    }),
    "/search?query=%D0%91%D0%B8%D1%82%D0%BE%D0%BB%D0%B0&page=2&category=zivot&author=razbudi-se-redakcija&type=interview&sponsored=true&published_from=2026-06-01&published_to=2026-06-30",
  );
});
