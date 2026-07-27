export function archiveCanonicalPath(path, page) {
  const pageNumber = Number(page ?? 1);

  if (!Number.isFinite(pageNumber) || pageNumber <= 1) {
    return path;
  }

  return `${path}?page=${pageNumber}`;
}

export function archiveCanonicalInput(path, page, explicitCanonical = null) {
  const pageNumber = Number(page ?? 1);

  return {
    path: archiveCanonicalPath(path, page),
    explicitCanonical: Number.isFinite(pageNumber) && pageNumber > 1 ? null : explicitCanonical,
  };
}

export function archiveTitleWithPage(title, page) {
  const pageNumber = Number(page ?? 1);

  if (!Number.isFinite(pageNumber) || pageNumber <= 1) {
    return title;
  }

  return `${title} - страница ${pageNumber}`;
}

export function archiveMetadata({ title, description, path, page }) {
  const canonical = archiveCanonicalPath(path, page);
  const titleWithPage = archiveTitleWithPage(title, page);

  return {
    title: titleWithPage,
    description,
    alternates: {
      canonical,
    },
    openGraph: {
      title: titleWithPage,
      description,
      url: canonical,
    },
  };
}

export function archivePageHref(path, page) {
  const pageNumber = Number(page ?? 1);

  if (!Number.isFinite(pageNumber) || pageNumber <= 1) {
    return path;
  }

  return `${path}?page=${pageNumber}`;
}

export function archivePaginationLabel(currentPage, lastPage) {
  return `Страница ${currentPage} од ${lastPage}`;
}

export function archiveEmptyState(kind) {
  if (kind === "tag") {
    return {
      title: "Нема објавени текстови со овој таг.",
      description: "Таг архивата ќе се пополни автоматски кога ќе има поврзани објави.",
    };
  }

  return {
    title: "Нема објавени текстови во оваа рубрика.",
    description: "Кога ќе се објават статии, тие ќе се појават тука со чиста пагинација.",
  };
}
