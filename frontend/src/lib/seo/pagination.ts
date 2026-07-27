import {
  archiveCanonicalInput as archiveCanonicalInputBase,
  archiveCanonicalPath as archiveCanonicalPathBase,
  archiveEmptyState as archiveEmptyStateBase,
  archiveMetadata as archiveMetadataBase,
  archivePageHref as archivePageHrefBase,
  archivePaginationLabel as archivePaginationLabelBase,
  archiveTitleWithPage as archiveTitleWithPageBase,
} from "./pagination.mjs";

const archiveCanonicalInputTyped = archiveCanonicalInputBase as (
  path: string,
  page?: number | string | null,
  explicitCanonical?: string | null,
) => {
  path: string;
  explicitCanonical: string | null;
};
const archiveCanonicalPathTyped = archiveCanonicalPathBase as (path: string, page?: number | string | null) => string;
const archiveTitleWithPageTyped = archiveTitleWithPageBase as (title: string, page?: number | string | null) => string;
const archivePageHrefTyped = archivePageHrefBase as (path: string, page?: number | string | null) => string;
const archivePaginationLabelTyped = archivePaginationLabelBase as (
  currentPage: number | string,
  lastPage: number | string,
) => string;
const archiveEmptyStateTyped = archiveEmptyStateBase as (kind: "category" | "tag") => {
  title: string;
  description: string;
};
const archiveMetadataTyped = archiveMetadataBase as (input: {
  title: string;
  description: string;
  path: string;
  page?: number | string | null;
}) => {
  title: string;
  description: string;
  alternates: {
    canonical: string;
  };
  openGraph: {
    title: string;
    description: string;
    url: string;
  };
};

export function archiveCanonicalPath(path: string, page?: number | string | null) {
  return archiveCanonicalPathTyped(path, page);
}

export function archiveCanonicalInput(
  path: string,
  page?: number | string | null,
  explicitCanonical?: string | null,
) {
  return archiveCanonicalInputTyped(path, page, explicitCanonical);
}

export function archiveTitleWithPage(title: string, page?: number | string | null) {
  return archiveTitleWithPageTyped(title, page);
}

export function archiveMetadata(input: {
  title: string;
  description: string;
  path: string;
  page?: number | string | null;
}) {
  return archiveMetadataTyped(input);
}

export function archivePageHref(path: string, page?: number | string | null) {
  return archivePageHrefTyped(path, page);
}

export function archivePaginationLabel(currentPage: number | string, lastPage: number | string) {
  return archivePaginationLabelTyped(currentPage, lastPage);
}

export function archiveEmptyState(kind: "category" | "tag") {
  return archiveEmptyStateTyped(kind);
}
