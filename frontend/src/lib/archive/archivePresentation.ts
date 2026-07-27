import {
  archiveAdZones as archiveAdZonesBase,
  archiveIntroCopy as archiveIntroCopyBase,
  archivePaginationSummary as archivePaginationSummaryBase,
  archiveSectionTitle as archiveSectionTitleBase,
} from "./archivePresentation.mjs";

export const archiveAdZones = archiveAdZonesBase as (kind: "category" | "tag") => {
  top: string;
  inFeed: string;
  inFeedSlots: string[];
};

export const archivePaginationSummary = archivePaginationSummaryBase as (meta: {
  current_page: number;
  last_page: number;
  total: number;
}) => string;

export const archiveSectionTitle = archiveSectionTitleBase as (
  kind: "category" | "tag",
  label: string,
) => string;

export const archiveIntroCopy = archiveIntroCopyBase as (
  kind: "category" | "tag",
  label: string,
  description: string | null | undefined,
  total: number,
) => string;
