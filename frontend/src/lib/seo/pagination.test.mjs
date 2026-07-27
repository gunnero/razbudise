import assert from "node:assert/strict";
import { test } from "node:test";

import * as pagination from "./pagination.mjs";
import {
  archiveCanonicalInput,
  archiveCanonicalPath,
  archiveEmptyState,
  archivePageHref,
  archivePaginationLabel,
  archiveTitleWithPage,
} from "./pagination.mjs";

test("archiveCanonicalPath keeps first page clean and paginates later pages", () => {
  assert.equal(archiveCanonicalPath("/category/vesti", undefined), "/category/vesti");
  assert.equal(archiveCanonicalPath("/category/vesti", "1"), "/category/vesti");
  assert.equal(archiveCanonicalPath("/category/vesti", "3"), "/category/vesti?page=3");
});

test("archiveCanonicalInput preserves explicit archive canonicals only on the first page", () => {
  assert.deepEqual(
    archiveCanonicalInput("/category/vesti", "1", "https://razbudise.mk/category/vesti"),
    {
      path: "/category/vesti",
      explicitCanonical: "https://razbudise.mk/category/vesti",
    },
  );
  assert.deepEqual(
    archiveCanonicalInput("/category/vesti", "2", "https://razbudise.mk/category/vesti"),
    {
      path: "/category/vesti?page=2",
      explicitCanonical: null,
    },
  );
});

test("archiveTitleWithPage appends Macedonian page label after page one", () => {
  assert.equal(archiveTitleWithPage("Вести", "1"), "Вести");
  assert.equal(archiveTitleWithPage("Вести", "2"), "Вести - страница 2");
});

test("archiveMetadata creates page-aware archive SEO metadata", () => {
  assert.equal(typeof pagination.archiveMetadata, "function");
  assert.deepEqual(
    pagination.archiveMetadata({
      title: "Вести",
      description: "Најнови вести и актуелни информации.",
      path: "/vesti",
      page: "2",
    }),
    {
      title: "Вести - страница 2",
      description: "Најнови вести и актуелни информации.",
      alternates: {
        canonical: "/vesti?page=2",
      },
      openGraph: {
        title: "Вести - страница 2",
        description: "Најнови вести и актуелни информации.",
        url: "/vesti?page=2",
      },
    },
  );
});

test("archive pagination helpers create Macedonian labels and stable hrefs", () => {
  assert.equal(archivePaginationLabel(2, 5), "Страница 2 од 5");
  assert.equal(archivePageHref("/category/vesti", 1), "/category/vesti");
  assert.equal(archivePageHref("/category/vesti", 4), "/category/vesti?page=4");
});

test("archiveEmptyState returns category and tag specific public copy", () => {
  assert.deepEqual(archiveEmptyState("category"), {
    title: "Нема објавени текстови во оваа рубрика.",
    description: "Кога ќе се објават статии, тие ќе се појават тука со чиста пагинација.",
  });
  assert.deepEqual(archiveEmptyState("tag"), {
    title: "Нема објавени текстови со овој таг.",
    description: "Таг архивата ќе се пополни автоматски кога ќе има поврзани објави.",
  });
});
