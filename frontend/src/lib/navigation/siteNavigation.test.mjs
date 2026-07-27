import assert from "node:assert/strict";
import test from "node:test";

import { footerNavigationItems, headerNavigationItems, menuNavigationItems } from "./siteNavigation.mjs";

test("headerNavigationItems returns API menu items when available", () => {
  const items = headerNavigationItems({
    items: [
      {
        title: "Живот",
        url: "/category/zivot",
        opens_in_new_tab: false,
        children: [
          { title: "Мими Југо", url: "/category/mimi-yugo", opens_in_new_tab: false },
          { title: "Тим", url: "/tim", opens_in_new_tab: false },
        ],
      },
      { title: "Тим", url: "/tim", opens_in_new_tab: false },
    ],
  });

  assert.deepEqual(items, [
    {
      href: "/category/zivot",
      label: "Живот",
      opensInNewTab: false,
      children: [{ href: "/category/mimi-yugo", label: "Мими Југо", opensInNewTab: false }],
    },
  ]);
});

test("headerNavigationItems falls back to grouped original-style sections when menu is empty", () => {
  const items = headerNavigationItems({ items: [] });

  assert.deepEqual(items.map((item) => item.label), [
    "Почетна",
    "Живот",
    "Урбано",
    "Битолски Муабети",
    "Колумни",
    "Во деловен кодекс",
    "Вести",
  ]);
  assert.deepEqual(items.find((item) => item.label === "Живот")?.children?.map((item) => item.label), [
    "Хедонизам и Гастрономија",
    "Опуштено",
    "Видеата на Марјан",
    "Мими Југо",
    "Нена Колумни",
    "Психологија",
    "Модна Соба",
    "Холивудски Трачарлуци",
  ]);
  assert.deepEqual(items.find((item) => item.label === "Урбано")?.children?.map((item) => item.label), [
    "Интервју",
    "Дом и Дизајн",
    "Книга",
    "Art Time",
    "Настани",
  ]);
  assert.deepEqual(items.find((item) => item.label === "Во деловен кодекс")?.children?.map((item) => item.label), [
    "Бизнис",
    "Инвестиции и финансии",
  ]);
  assert.equal(items.find((item) => item.label === "Вести")?.href, "/category/vesti");
  assert.equal(items.find((item) => item.label === "Настани"), undefined);
  assert.equal(items.find((item) => item.label === "Тим"), undefined);
});

test("footerNavigationItems returns API menu items when available", () => {
  const items = footerNavigationItems({
    items: [
      { title: "Маркетинг", url: "/marketing", opens_in_new_tab: false },
      { title: "Настани", url: "/nastani", opens_in_new_tab: false },
      { title: "Вести", url: "/category/vesti", opens_in_new_tab: false },
      { title: "Instagram", url: "https://instagram.com/razbudise", opens_in_new_tab: true },
    ],
  });

  assert.deepEqual(items, [
    { href: "/marketing", label: "Маркетинг", opensInNewTab: false },
    { href: "https://instagram.com/razbudise", label: "Instagram", opensInNewTab: true },
    { href: "/za-nas", label: "За нас", opensInNewTab: false },
    { href: "/kontakt", label: "Контакт", opensInNewTab: false },
    { href: "/cenovnik-za-reklamiranje", label: "Ценовник за рекламирање", opensInNewTab: false },
    { href: "/impresum", label: "Импресум", opensInNewTab: false },
    { href: "/uslovi-za-koristenje", label: "Услови за користење", opensInNewTab: false },
    { href: "/politika-za-privatnost", label: "Политика за приватност", opensInNewTab: false },
  ]);
  assert.equal(items.find((item) => item.label === "Настани"), undefined);
  assert.equal(items.find((item) => item.label === "Вести"), undefined);
});

test("footerNavigationItems falls back to reader utility links", () => {
  const items = footerNavigationItems({ items: [] });

  assert.deepEqual(items.map((item) => item.label), [
    "Почетна",
    "Пребарување",
    "Тим",
    "За нас",
    "Контакт",
    "Ценовник за рекламирање",
    "Импресум",
    "Услови за користење",
    "Политика за приватност",
  ]);
  assert.equal(items.find((item) => item.label === "Настани"), undefined);
  assert.equal(items.find((item) => item.label === "Вести"), undefined);
});

test("menuNavigationItems returns an empty list when menu has no usable URLs", () => {
  assert.deepEqual(menuNavigationItems({ items: [{ title: "Missing URL", url: null }] }), []);
});

test("menuNavigationItems preserves child link groups", () => {
  assert.deepEqual(
    menuNavigationItems({
      items: [
        {
          title: "Култура",
          url: "/category/kultura",
          opens_in_new_tab: false,
          children: [
            { title: "Книга", url: "/category/kniga", opens_in_new_tab: false },
            { title: "Без URL", url: null, opens_in_new_tab: false },
          ],
        },
      ],
    }),
    [
      {
        href: "/category/kultura",
        label: "Култура",
        opensInNewTab: false,
        children: [{ href: "/category/kniga", label: "Книга", opensInNewTab: false }],
      },
    ],
  );
});

test("menuNavigationItems preserves external URLs and new-tab flags", () => {
  assert.deepEqual(
    menuNavigationItems({
      items: [{ title: "Instagram", url: "https://instagram.com/razbudise", opens_in_new_tab: true }],
    }),
    [{ href: "https://instagram.com/razbudise", label: "Instagram", opensInNewTab: true }],
  );
});
