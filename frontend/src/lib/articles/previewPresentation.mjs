export function previewBanner(isPreview) {
  if (!isPreview) {
    return null;
  }

  return {
    label: "Уреднички преглед",
    message: "Ова е преглед на необјавена содржина. Линкот е само за редакциски преглед.",
  };
}

export function previewMetadata(isPreview) {
  if (!isPreview) {
    return {};
  }

  return {
    robots: {
      index: false,
      follow: false,
      googleBot: {
        index: false,
        follow: false,
      },
    },
  };
}

export function mobileArticleFlow({ hasFeaturedImage = false, hasRelated = false } = {}) {
  const flow = ["preview-banner", "hero", "ad-after-title"];

  if (hasFeaturedImage) {
    flow.push("featured-image");
  }

  flow.push("body", "tags", "ad-before-related");

  if (hasRelated) {
    flow.push("related");
  }

  return flow;
}
