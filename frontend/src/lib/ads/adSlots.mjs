const slotGroups = {
  article_sidebar_sticky: ["article_sidebar_sticky", "article_sidebar_sticky_slot_2"],
  article_sidebar_top: ["article_sidebar_top", "article_sidebar_top_slot_2", "article_sidebar_top_slot_3"],
  category_in_feed: ["category_in_feed", "category_in_feed_slot_2", "category_in_feed_slot_3", "category_in_feed_slot_4"],
  home_in_feed_1: ["home_in_feed_1", "home_in_feed_1_slot_2", "home_in_feed_1_slot_3", "home_in_feed_1_slot_4"],
  home_in_feed_2: ["home_in_feed_2", "home_in_feed_2_slot_2", "home_in_feed_2_slot_3", "home_in_feed_2_slot_4"],
  home_in_feed_3: ["home_in_feed_3", "home_in_feed_3_slot_2", "home_in_feed_3_slot_3", "home_in_feed_3_slot_4"],
  newsletter_inline: ["newsletter_inline", "newsletter_inline_slot_2"],
};

export function adZoneSlotKeys(zoneKey) {
  if (!zoneKey) {
    return [];
  }

  return slotGroups[zoneKey] ?? [zoneKey];
}
