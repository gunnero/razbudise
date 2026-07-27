export function openGraphImages(
  pageImage: string | null | undefined,
  defaultImage: string | null | undefined,
  baseUrl: string | null = null,
): Array<{ url: string }> | undefined {
  const image = [pageImage, defaultImage].find((value) => typeof value === "string" && value.trim() !== "");

  if (!image) {
    return undefined;
  }

  let url = image.trim();

  if (baseUrl && !/^https?:\/\//i.test(url)) {
    try {
      url = new URL(url, `${baseUrl.replace(/\/+$/, "")}/`).toString();
    } catch {
      url = image.trim();
    }
  }

  return [{ url }];
}
