import type { Menu } from "@/lib/api/client";

export type NavigationItem = {
  href: string;
  label: string;
  opensInNewTab: boolean;
  children?: NavigationItem[];
};

export const defaultHeaderNavigationItems: NavigationItem[] = [
  { href: "/", label: "Почетна", opensInNewTab: false },
  {
    href: "/category/zivot",
    label: "Живот",
    opensInNewTab: false,
    children: [
      { href: "/category/hedonizam-i-gastronomija", label: "Хедонизам и Гастрономија", opensInNewTab: false },
      { href: "/category/opusteno", label: "Опуштено", opensInNewTab: false },
      { href: "/category/videata-na-marjan", label: "Видеата на Марјан", opensInNewTab: false },
      { href: "/category/mimi-yugo", label: "Мими Југо", opensInNewTab: false },
      { href: "/category/nena-kolumni", label: "Нена Колумни", opensInNewTab: false },
      { href: "/category/psihologija", label: "Психологија", opensInNewTab: false },
      { href: "/category/modna-soba", label: "Модна Соба", opensInNewTab: false },
      { href: "/category/xolivudski-tracarluci", label: "Холивудски Трачарлуци", opensInNewTab: false },
    ],
  },
  {
    href: "/category/urbano",
    label: "Урбано",
    opensInNewTab: false,
    children: [
      { href: "/category/intervju", label: "Интервју", opensInNewTab: false },
      { href: "/category/dom-i-dizajn", label: "Дом и Дизајн", opensInNewTab: false },
      { href: "/category/kniga", label: "Книга", opensInNewTab: false },
      { href: "/category/art-time", label: "Art Time", opensInNewTab: false },
      { href: "/nastani", label: "Настани", opensInNewTab: false },
    ],
  },
  { href: "/category/bitolski-muabeti", label: "Битолски Муабети", opensInNewTab: false },
  { href: "/category/kolumni", label: "Колумни", opensInNewTab: false },
  {
    href: "/category/vo-deloven-kodeks",
    label: "Во деловен кодекс",
    opensInNewTab: false,
    children: [
      { href: "/category/biznis", label: "Бизнис", opensInNewTab: false },
      { href: "/category/finansii", label: "Инвестиции и финансии", opensInNewTab: false },
    ],
  },
  { href: "/category/vesti", label: "Вести", opensInNewTab: false },
];

export const defaultFooterNavigationItems: NavigationItem[] = [
  { href: "/", label: "Почетна", opensInNewTab: false },
  { href: "/search", label: "Пребарување", opensInNewTab: false },
  { href: "/tim", label: "Тим", opensInNewTab: false },
  { href: "/za-nas", label: "За нас", opensInNewTab: false },
  { href: "/kontakt", label: "Контакт", opensInNewTab: false },
  { href: "/cenovnik-za-reklamiranje", label: "Ценовник за рекламирање", opensInNewTab: false },
  { href: "/impresum", label: "Импресум", opensInNewTab: false },
  { href: "/uslovi-za-koristenje", label: "Услови за користење", opensInNewTab: false },
  { href: "/politika-za-privatnost", label: "Политика за приватност", opensInNewTab: false },
];

export const requiredFooterNavigationItems: NavigationItem[] = [
  { href: "/za-nas", label: "За нас", opensInNewTab: false },
  { href: "/kontakt", label: "Контакт", opensInNewTab: false },
  { href: "/cenovnik-za-reklamiranje", label: "Ценовник за рекламирање", opensInNewTab: false },
  { href: "/impresum", label: "Импресум", opensInNewTab: false },
  { href: "/uslovi-za-koristenje", label: "Услови за користење", opensInNewTab: false },
  { href: "/politika-za-privatnost", label: "Политика за приватност", opensInNewTab: false },
];

const hiddenFooterNavigationLabels = new Set(["Настани", "Вести"]);
const hiddenFooterNavigationHrefs = new Set([
  "/nastani",
  "/vesti",
  "/category/vesti",
]);
const hiddenHeaderNavigationHrefs = new Set(["/tim", "/search"]);

function visibleFooterNavigationItems(items: NavigationItem[]): NavigationItem[] {
  return items.filter((item) => !hiddenFooterNavigationLabels.has(item.label) && !hiddenFooterNavigationHrefs.has(item.href));
}

export function headerNavigationItems(menu: Menu | null): NavigationItem[] {
  const items = menuNavigationItems(menu);
  const headerItems = items.map(withoutHeaderUtilityItems).filter((item): item is NavigationItem => Boolean(item));

  return headerItems.length ? headerItems : defaultHeaderNavigationItems;
}

export function footerNavigationItems(menu: Menu | null): NavigationItem[] {
  const items = visibleFooterNavigationItems(flattenNavigationItems(menuNavigationItems(menu)));

  if (!items.length) {
    return defaultFooterNavigationItems;
  }

  const existingHrefs = new Set(items.map((item) => item.href));
  const missingRequiredItems = requiredFooterNavigationItems.filter((item) => !existingHrefs.has(item.href));

  return [...items, ...missingRequiredItems];
}

export function menuNavigationItems(menu: Menu | null): NavigationItem[] {
  const items = (menu?.items ?? [])
    .map(menuItemToNavigationItem)
    .filter((item): item is NavigationItem => Boolean(item));

  return items;
}

function menuItemToNavigationItem(menuItem: Menu["items"][number]): NavigationItem | null {
  const children = (menuItem.children ?? [])
    .map(menuItemToNavigationItem)
    .filter((item): item is NavigationItem => Boolean(item));

  if (!menuItem.url && !children.length) {
    return null;
  }

  if (!menuItem.url) {
    return null;
  }

  return {
    href: menuItem.url,
    label: menuItem.title,
    opensInNewTab: menuItem.opens_in_new_tab,
    ...(children.length ? { children } : {}),
  };
}

function withoutHeaderUtilityItems(item: NavigationItem): NavigationItem | null {
  const children = (item.children ?? [])
    .map(withoutHeaderUtilityItems)
    .filter((child): child is NavigationItem => Boolean(child));

  if (hiddenHeaderNavigationHrefs.has(item.href)) {
    return null;
  }

  return {
    href: item.href,
    label: item.label,
    opensInNewTab: item.opensInNewTab,
    ...(children.length ? { children } : {}),
  };
}

function flattenNavigationItems(items: NavigationItem[]): NavigationItem[] {
  return items.flatMap(({ children, ...item }) => [item, ...flattenNavigationItems(children ?? [])]);
}
