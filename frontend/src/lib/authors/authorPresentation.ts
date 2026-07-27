import type { AuthorListItem } from "@/lib/api/client";

export const authorSortOptions = [
  { value: "recent", label: "Најнови" },
  { value: "active", label: "Најактивни" },
  { value: "articles", label: "Најмногу објави" },
  { value: "alphabetical", label: "Азбучно" },
] as const;

export type AuthorSort = (typeof authorSortOptions)[number]["value"];

export function normalizedAuthorSort(value?: string): AuthorSort {
  return authorSortOptions.some((option) => option.value === value) ? value as AuthorSort : "recent";
}

export function authorInitials(name: string): string {
  return name.trim().split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toLocaleUpperCase("mk") ?? "").join("");
}

export function authorBiographyPreview(author: Pick<AuthorListItem, "bio_preview" | "short_bio">): string | null {
  return author.bio_preview?.trim() || author.short_bio?.trim() || null;
}

export function authorLatestDateLabel(value?: string | null): string | null {
  if (!value) return null;

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;

  return new Intl.DateTimeFormat("mk-MK", { day: "numeric", month: "long", year: "numeric" }).format(date);
}

export function authorDirectoryHref({ page = 1, search = "", sort = "recent" }: { page?: number; search?: string; sort?: AuthorSort }): string {
  const params = new URLSearchParams();
  if (search.trim()) params.set("search", search.trim());
  if (sort !== "recent") params.set("sort", sort);
  if (page > 1) params.set("page", String(page));
  const query = params.toString();
  return query ? `/tim?${query}` : "/tim";
}
