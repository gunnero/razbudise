import { normalizeMediaUrl } from "../media/mediaUrls.mjs";

const ARTICLE_PARAGRAPH_ADS = new Map([
  [3, "article_after_paragraph_3"],
  [7, "article_after_paragraph_7"],
]);

const ARTICLE_HTML_TOKEN_PATTERN =
  /<p\b[^>]*>\s*(?:<a\b[^>]*>\s*)?<img\b[^>]*>\s*(?:<\/a>\s*)?<\/p>|<figure\b[\s\S]*?<\/figure>|<div\b[^>]*(?:wp-caption|wp-block-image)[^>]*>[\s\S]*?<img\b[\s\S]*?<\/div>|<p\b[\s\S]*?<\/p>|<img\b[^>]*>/gi;

export function articleBodyMode(article) {
  return article.blocks?.length ? "blocks" : "html";
}

export function articleBodySequence(article) {
  const sequence = [];
  let paragraphCount = 0;

  for (const block of article.blocks ?? []) {
    sequence.push({
      kind: "block",
      block,
    });

    if (block.type !== "paragraph") {
      continue;
    }

    paragraphCount += 1;
    const zoneKey = ARTICLE_PARAGRAPH_ADS.get(paragraphCount);

    if (zoneKey) {
      sequence.push({
        articleId: article.id,
        kind: "ad",
        zoneKey,
      });
    }
  }

  return sequence;
}

export function articleHtmlSequence(article) {
  const html = article.body_html ?? "";
  const sequence = [];
  const pendingImages = [];
  let paragraphCount = 0;
  let cursor = 0;
  let match;

  while ((match = ARTICLE_HTML_TOKEN_PATTERN.exec(html)) !== null) {
    const prefix = html.slice(cursor, match.index);

    if (hasMeaningfulHtml(prefix)) {
      flushImportedImages(sequence, pendingImages);
      pushHtmlFragment(sequence, prefix);
    }

    const token = match[0];
    const importedImage = importedImageFromHtml(token);

    if (importedImage && isImportedImageBlock(token)) {
      pendingImages.push({
        html: token,
        image: importedImage,
      });
    } else {
      flushImportedImages(sequence, pendingImages);
      pushHtmlFragment(sequence, token);

      if (isParagraphHtml(token)) {
        paragraphCount += 1;
        const zoneKey = ARTICLE_PARAGRAPH_ADS.get(paragraphCount);

        if (zoneKey) {
          sequence.push({
            articleId: article.id,
            kind: "ad",
            zoneKey,
          });
        }
      }
    }

    cursor = ARTICLE_HTML_TOKEN_PATTERN.lastIndex;
  }

  const remainder = html.slice(cursor);

  if (hasMeaningfulHtml(remainder)) {
    flushImportedImages(sequence, pendingImages);
    pushHtmlFragment(sequence, remainder);
  }

  flushImportedImages(sequence, pendingImages);

  if (sequence.length === 0 && html !== "") {
    sequence.push({
      kind: "html",
      html,
    });
  }

  return sequence;
}

function pushHtmlFragment(sequence, html) {
  if (!hasMeaningfulHtml(html)) {
    return;
  }

  sequence.push({
    kind: "html",
    html,
  });
}

function flushImportedImages(sequence, pendingImages) {
  if (pendingImages.length === 0) {
    return;
  }

  if (pendingImages.length === 1) {
    pushHtmlFragment(sequence, pendingImages[0].html);
  } else {
    sequence.push({
      kind: "gallery",
      images: pendingImages.map((item) => item.image),
    });
  }

  pendingImages.length = 0;
}

function hasMeaningfulHtml(html) {
  if (html.trim() === "") {
    return false;
  }

  return html.replace(/<\/?figure\b[^>]*>/gi, "").trim() !== "";
}

function isParagraphHtml(html) {
  return /^<p\b/i.test(html.trim());
}

function isImportedImageBlock(html) {
  const trimmed = html.trim();

  if (!/<img\b/i.test(trimmed)) {
    return false;
  }

  if (/^<img\b/i.test(trimmed) || /^<figure\b/i.test(trimmed)) {
    return true;
  }

  if (/^<div\b/i.test(trimmed)) {
    return /\b(?:wp-caption|wp-block-image)\b/i.test(trimmed);
  }

  return /^<p\b[^>]*>\s*(?:<a\b[^>]*>\s*)?<img\b[^>]*>\s*(?:<\/a>\s*)?<\/p>$/i.test(trimmed);
}

function importedImageFromHtml(html) {
  const imageMatch = html.match(/<img\b([^>]*)>/i);

  if (!imageMatch) {
    return null;
  }

  const url = normalizeMediaUrl(htmlAttribute(imageMatch[1], "src"));

  if (!url) {
    return null;
  }

  const caption = htmlCaption(html);
  const alt = htmlAttribute(imageMatch[1], "alt") ?? caption ?? "";

  return {
    alt,
    caption,
    url,
  };
}

function htmlAttribute(attributes, name) {
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const pattern = new RegExp(`${escapedName}\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s>]+))`, "i");
  const match = attributes.match(pattern);
  const value = match?.[1] ?? match?.[2] ?? match?.[3] ?? null;

  return value ? decodeHtmlText(value.trim()) : null;
}

function htmlCaption(html) {
  const captionMatch =
    html.match(/<figcaption\b[^>]*>([\s\S]*?)<\/figcaption>/i) ??
    html.match(/<p\b[^>]*class=(?:"[^"]*\bwp-caption-text\b[^"]*"|'[^']*\bwp-caption-text\b[^']*')[^>]*>([\s\S]*?)<\/p>/i);

  if (!captionMatch) {
    return null;
  }

  const caption = decodeHtmlText(captionMatch[1].replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim());

  return caption === "" ? null : caption;
}

function decodeHtmlText(value) {
  return value
    .replace(/&#(\d+);/g, (_, codePoint) => String.fromCodePoint(Number(codePoint)))
    .replace(/&#x([\da-f]+);/gi, (_, codePoint) => String.fromCodePoint(Number.parseInt(codePoint, 16)))
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&apos;/g, "'")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&amp;/g, "&");
}

export function articleGalleryImages(block, options = {}) {
  const rawImages = block.data.images;
  const images = Array.isArray(rawImages) ? rawImages : parseGalleryJson(rawImages);

  return images
    .map((image) => {
      if (!image || typeof image !== "object" || Array.isArray(image)) {
        return null;
      }

      const url = normalizeMediaUrl(galleryString(image, "url"));

      if (!url) {
        return null;
      }

      const caption = galleryString(image, "caption");

      return {
        url,
        alt: galleryString(image, "alt") ?? caption ?? options.fallbackAlt ?? "",
        caption,
      };
    })
    .filter((image) => image !== null);
}

function parseGalleryJson(value) {
  if (typeof value !== "string" || value.trim() === "") {
    return [];
  }

  try {
    const parsed = JSON.parse(value);

    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function galleryString(image, key) {
  const value = image[key];

  return typeof value === "string" && value.trim() !== "" ? value : null;
}
