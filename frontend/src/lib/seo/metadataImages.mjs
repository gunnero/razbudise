function normalizedImageUrl(value, baseUrl) {
  if (typeof value !== "string" || value.trim() === "") {
    return null;
  }

  const image = value.trim();

  if (!baseUrl || /^https?:\/\//i.test(image)) {
    return image;
  }

  try {
    return new URL(image, `${baseUrl.replace(/\/+$/, "")}/`).toString();
  } catch {
    return image;
  }
}

export function openGraphImages(pageImage, defaultImage, baseUrl = null) {
  const image = [pageImage, defaultImage].find(
    (value) => typeof value === "string" && value.trim() !== "",
  );

  const url = normalizedImageUrl(image, baseUrl);

  return url ? [{ url }] : undefined;
}
