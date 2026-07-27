"use client";

import Link from "next/link";
import { useState } from "react";
import type { AuthorArticle } from "@/lib/api/client";
import { articleBadge, articleImagePresentation, articleMetaParts } from "@/lib/articles/articlePresentation";
import { mediaFallbackLabel } from "@/lib/media/mediaUrls";

type ArticleCardVariant = "card" | "list" | "compact" | "feature";

const articleCardVariantClasses: Record<ArticleCardVariant, string> = {
  card: "article-card-card",
  compact: "article-card-compact",
  feature: "article-card-feature",
  list: "article-card-list",
};

export function ArticleCard({
  article,
  variant = "card",
}: {
  article: AuthorArticle;
  variant?: ArticleCardVariant;
}) {
  const badge = articleBadge(article);
  const meta = articleMetaParts(article);
  const image = articleImagePresentation(article);
  const [imageFailed, setImageFailed] = useState(false);
  const classNames = ["article-card", articleCardVariantClasses[variant]];

  if (badge) {
    classNames.push("article-card-highlighted");
  }

  return (
    <article className={classNames.join(" ")}>
      {image.hasImage && image.url ? (
        <Link aria-label={article.canonical_title ?? article.title} className="article-card-image-link" href={article.url}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            className={imageFailed ? "article-card-image-failed" : undefined}
            src={image.url}
            alt={image.alt}
            style={image.focalPointStyle}
            onError={() => setImageFailed(true)}
          />
          <span className="article-card-placeholder article-card-placeholder-overlay" hidden={!imageFailed}>
            {mediaFallbackLabel}
          </span>
        </Link>
      ) : (
        <Link aria-label={article.title} className="article-card-placeholder" href={article.url}>
          <span>{image.placeholderLabel}</span>
        </Link>
      )}
      <div className="article-card-content">
        <p className="kicker">{badge ?? article.category?.name ?? "Објава"}</p>
        <h3>
          <Link href={article.url} title={article.canonical_title ?? article.title}>{article.title}</Link>
        </h3>
        {article.excerpt ? <p>{article.excerpt}</p> : null}
        <small>{meta.join(" · ")}</small>
      </div>
    </article>
  );
}
