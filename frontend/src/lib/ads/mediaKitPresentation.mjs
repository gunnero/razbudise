const catalog = [
  { match: /^home_top_billboard/, id: "home-billboard", category: "home", name: "Премиум билборд на почетна", description: "Највидлива позиција над главната содржина.", device: "Компјутер и таблет" },
  { match: /^home_after_featured/, id: "home-featured", category: "home", name: "После главната приказна", description: "Висока видливост веднаш по најважната уредничка содржина.", device: "Сите уреди" },
  { match: /^home_in_feed_/, id: "home-feed", category: "home", name: "Во почетниот фид", description: "Природно поставување меѓу објавите на почетната страница.", device: "Сите уреди" },
  { match: /^category_top/, id: "category-top", category: "archives", name: "Горна позиција во рубрика", description: "Брендот се појавува над објавите во избраната рубрика.", device: "Компјутер и таблет" },
  { match: /^category_in_feed/, id: "category-feed", category: "archives", name: "Во фид на рубрика", description: "Реклама меѓу објавите во категории и архиви.", device: "Сите уреди" },
  { match: /^search_results_top/, id: "search-top", category: "archives", name: "Над резултати од пребарување", description: "Контекстуална видливост пред листата со резултати.", device: "Компјутер и таблет" },
  { match: /^author_page_top/, id: "author-top", category: "archives", name: "На авторска страница", description: "Горна позиција покрај содржините на избран автор.", device: "Компјутер и таблет" },
  { match: /^event_page_top/, id: "event-top", category: "archives", name: "На страница за настани", description: "Горна позиција за публика што бара што се случува во градот.", device: "Компјутер и таблет" },
  { match: /^article_after_title/, id: "article-title", category: "article", name: "Под наслов на објава", description: "Премиум контакт со читателот пред почетокот на текстот.", device: "Сите уреди" },
  { match: /^article_after_paragraph_/, id: "article-inline", category: "article", name: "Во текст на објава", description: "Природно вметната позиција додека читателот е ангажиран.", device: "Сите уреди" },
  { match: /^article_before_related/, id: "article-related", category: "article", name: "Пред поврзани објави", description: "Видливост на крајот од читањето, пред следната содржина.", device: "Сите уреди" },
  { match: /^article_sidebar_top/, id: "article-sidebar", category: "article", name: "Странична позиција во објава", description: "Стабилна позиција покрај текстот.", device: "Компјутер" },
  { match: /^article_sidebar_sticky/, id: "article-sticky", category: "article", name: "Лебдечка странична позиција", description: "Подолга видливост додека читателот се движи низ текстот.", device: "Компјутер" },
  { match: /^mobile_sticky_bottom/, id: "mobile-sticky", category: "mobile", name: "Мобилна фиксна позиција", description: "Фиксна долна позиција создадена за мобилно читање.", device: "Мобилни уреди" },
  { match: /^newsletter_inline/, id: "newsletter", category: "other", name: "Позиција покрај билтен", description: "Промотивен простор во близина на пријавувањето за билтен.", device: "Сите уреди" },
];

export function publicAdProducts(products = []) {
  const groups = new Map();

  for (const product of products) {
    const meta = catalog.find((item) => item.match.test(product.zone_key)) ?? {
      id: product.zone_key.replace(/_slot_\d+$/, ""), category: "other", name: "Дополнителна рекламна позиција",
      description: product.description || "Рекламна позиција на Разбуди се.", device: "Сите уреди",
    };
    const existing = groups.get(meta.id);
    if (!existing) {
      groups.set(meta.id, {
        id: meta.id,
        category: meta.category,
        name: meta.name,
        description: meta.description,
        device: meta.device,
        bookingZoneKey: product.zone_key,
        internalZoneKeys: [product.zone_key],
        sizes: product.allowed_sizes ?? [],
        prices: product.prices ?? [],
        availabilityUrl: product.availability_url,
      });
      continue;
    }
    existing.internalZoneKeys.push(product.zone_key);
    existing.sizes = uniqueSizes([...existing.sizes, ...(product.allowed_sizes ?? [])]);
    existing.prices = uniquePrices([...existing.prices, ...(product.prices ?? [])]);
  }

  return [...groups.values()].map((group) => ({
    ...group,
    sizes: uniqueSizes(group.sizes),
    prices: uniquePrices(group.prices).sort((a, b) => a.duration_weeks - b.duration_weeks || a.price_denars - b.price_denars),
    startingPrice: startingWeeklyPrice(group.prices),
  }));
}

export function priceForDuration(product, durationWeeks) {
  const prices = product?.prices?.filter((price) => price.duration_weeks === durationWeeks) ?? [];
  return prices.length ? Math.min(...prices.map((price) => price.price_denars)) : null;
}

export function startingWeeklyPrice(prices = []) {
  const weekly = prices.filter((price) => price.duration_weeks === 1);
  const source = weekly.length ? weekly : prices;
  return source.length ? Math.min(...source.map((price) => price.price_denars)) : null;
}

export function bookingUrl(zoneKey, durationWeeks) {
  if (!zoneKey) return "/reklami/zakazi";
  const params = new URLSearchParams({ zone: zoneKey });
  if (durationWeeks) params.set("period", String(durationWeeks));
  return `/reklami/zakazi?${params.toString()}`;
}

function uniqueSizes(sizes) {
  return [...new Map(sizes.map((size) => [size.join("x"), size])).values()];
}

function uniquePrices(prices) {
  return [...new Map(prices.map((price) => [`${price.duration_weeks}:${price.creative_type}`, price])).values()];
}
