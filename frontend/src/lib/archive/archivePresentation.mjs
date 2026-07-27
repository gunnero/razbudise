import { adZoneSlotKeys } from "../ads/adSlots.mjs";

export function archiveAdZones(kind) {
  if (kind === "tag") {
    return {
      top: "search_results_top",
      inFeed: "category_in_feed",
      inFeedSlots: adZoneSlotKeys("category_in_feed"),
    };
  }

  return {
    top: "category_top",
    inFeed: "category_in_feed",
    inFeedSlots: adZoneSlotKeys("category_in_feed"),
  };
}

export function archivePaginationSummary(meta) {
  if (meta.total === 1) {
    return "1 објава";
  }

  if (meta.last_page <= 1) {
    return `${meta.total} објави`;
  }

  return `Страница ${meta.current_page} од ${meta.last_page} · ${meta.total} објави`;
}

export function archiveSectionTitle(kind, label) {
  if (kind === "tag") {
    return `Објави со таг #${label}`;
  }

  return `Објави во ${label}`;
}

export function archiveIntroCopy(kind, label, description, total) {
  if (description) {
    return description;
  }

  if (kind === "tag") {
    return `Текстови поврзани со #${label}, собрани на едно место.`;
  }

  return `Избор од ${total} објави во рубриката ${label}, подредени за лесно читање.`;
}
