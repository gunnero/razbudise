import assert from "node:assert/strict";
import test from "node:test";

import {
  activeSearchFilterChips,
  highlightedSearchSnippet,
  searchEmptyState,
  searchPromptState,
} from "./searchPresentation.mjs";

test("activeSearchFilterChips builds readable Macedonian filter chips", () => {
  const chips = activeSearchFilterChips({
    selected: {
      category: "vesti",
      tag: "bitola",
      author: "razbudi-se-redakcija",
      type: "news",
      sponsored: true,
      published_from: "2026-06-01",
      published_to: "2026-06-30",
    },
    categories: [{ slug: "vesti", name: "Вести" }],
    tags: [{ slug: "bitola", name: "Битола" }],
    authors: [{ slug: "razbudi-se-redakcija", name: "Разбуди Се Редакција" }],
  });

  assert.deepEqual(chips.map((chip) => chip.label), [
    "Рубрика: Вести",
    "Таг: Битола",
    "Автор: Разбуди Се Редакција",
    "Тип: Вести",
    "Спонзорирано",
    "Од: 2026-06-01",
    "До: 2026-06-30",
  ]);
});

test("highlightedSearchSnippet marks matching query terms without returning HTML", () => {
  assert.deepEqual(
    highlightedSearchSnippet("Битола има нова културна приказна", "културна"),
    [
      { text: "Битола има нова ", match: false },
      { text: "културна", match: true },
      { text: " приказна", match: false },
    ],
  );
});

test("searchEmptyState changes copy when filters are active", () => {
  assert.deepEqual(searchEmptyState("Битола", true), {
    title: "Нема резултати со избраните филтри.",
    description: "Пробај да отстраниш дел од филтрите или пребарај со пократок збор.",
  });

  assert.deepEqual(searchEmptyState("Битола", false), {
    title: "Нема резултати за „Битола“.",
    description: "Пробај со пократок збор, име на рубрика или тема од текстот.",
  });
});

test("searchPromptState gives a helpful no-query reading prompt", () => {
  assert.deepEqual(searchPromptState(""), {
    description: "Пребарај по тема, автор, рубрика или збор од текстот.",
    title: "Што сакаш да прочиташ денес?",
  });
  assert.equal(searchPromptState("Битола"), null);
});
