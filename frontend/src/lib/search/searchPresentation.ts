import {
  activeSearchFilterChips as activeSearchFilterChipsBase,
  highlightedSearchSnippet as highlightedSearchSnippetBase,
  searchEmptyState as searchEmptyStateBase,
  searchPromptState as searchPromptStateBase,
} from "./searchPresentation.mjs";
import type { AuthorListItem, Category, Tag } from "@/lib/api/client";

export const activeSearchFilterChips = activeSearchFilterChipsBase as (input: {
  selected: {
    author?: string | null;
    category?: string | null;
    published_from?: string | null;
    published_to?: string | null;
    sponsored?: boolean | null;
    tag?: string | null;
    type?: string | null;
  };
  categories?: Array<Pick<Category, "name" | "slug">>;
  tags?: Array<Pick<Tag, "name" | "slug">>;
  authors?: Array<Pick<AuthorListItem, "name" | "slug">>;
}) => Array<{ key: string; label: string }>;

export const highlightedSearchSnippet = highlightedSearchSnippetBase as (
  text: string | null | undefined,
  query: string | null | undefined,
) => Array<{ text: string; match: boolean }>;

export const searchEmptyState = searchEmptyStateBase as (
  query: string,
  hasActiveFilters: boolean,
) => {
  title: string;
  description: string;
};

export const searchPromptState = searchPromptStateBase as (query: string) => {
  title: string;
  description: string;
} | null;
