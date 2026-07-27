const TYPE_LABELS = {
  column: "Колумни",
  editorial: "Редакциски",
  event: "Настани",
  guide: "Водичи",
  interview: "Интервју",
  news: "Вести",
  pr: "ПР",
  sponsored: "Спонзорирано",
};

function findName(items, slug, fallback) {
  return items?.find((item) => item.slug === slug)?.name ?? fallback;
}

export function activeSearchFilterChips({ selected, categories = [], tags = [], authors = [] }) {
  const chips = [];

  if (selected.category) {
    chips.push({ key: "category", label: `Рубрика: ${findName(categories, selected.category, selected.category)}` });
  }

  if (selected.tag) {
    chips.push({ key: "tag", label: `Таг: ${findName(tags, selected.tag, selected.tag)}` });
  }

  if (selected.author) {
    chips.push({ key: "author", label: `Автор: ${findName(authors, selected.author, selected.author)}` });
  }

  if (selected.type) {
    chips.push({ key: "type", label: `Тип: ${TYPE_LABELS[selected.type] ?? selected.type}` });
  }

  if (selected.sponsored) {
    chips.push({ key: "sponsored", label: "Спонзорирано" });
  }

  if (selected.published_from) {
    chips.push({ key: "published_from", label: `Од: ${selected.published_from}` });
  }

  if (selected.published_to) {
    chips.push({ key: "published_to", label: `До: ${selected.published_to}` });
  }

  return chips;
}

export function highlightedSearchSnippet(text, query) {
  if (!text || !query) {
    return [{ text: text ?? "", match: false }];
  }

  const index = text.toLocaleLowerCase("mk-MK").indexOf(query.toLocaleLowerCase("mk-MK"));

  if (index < 0) {
    return [{ text, match: false }];
  }

  return [
    { text: text.slice(0, index), match: false },
    { text: text.slice(index, index + query.length), match: true },
    { text: text.slice(index + query.length), match: false },
  ].filter((part) => part.text !== "");
}

export function searchEmptyState(query, hasActiveFilters) {
  if (hasActiveFilters) {
    return {
      title: "Нема резултати со избраните филтри.",
      description: "Пробај да отстраниш дел од филтрите или пребарај со пократок збор.",
    };
  }

  return {
    title: `Нема резултати за „${query}“.`,
    description: "Пробај со пократок збор, име на рубрика или тема од текстот.",
  };
}

export function searchPromptState(query) {
  if (query) {
    return null;
  }

  return {
    description: "Пребарај по тема, автор, рубрика или збор од текстот.",
    title: "Што сакаш да прочиташ денес?",
  };
}
