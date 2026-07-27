import { AdZone } from "@/components/AdZone";
import { ArticleHtmlChunk } from "@/components/ArticleHtmlChunk";
import type { ArticleBlock, ArticleDetail } from "@/lib/api/client";
import { articleBodyClassName } from "@/lib/articles/articlePresentation";
import {
  articleBodyMode,
  articleBodySequence,
  articleGalleryImages,
  articleHtmlSequence,
} from "@/lib/articles/renderArticleBlocks";
import { normalizeMediaUrl } from "@/lib/media/mediaUrls";

type ArticleBodyProps = {
  article: ArticleDetail;
};

type ArticleGalleryViewProps = {
  images: ReturnType<typeof articleGalleryImages>;
  isImported?: boolean;
};

function stringData(block: ArticleBlock, key: string): string | null {
  const value = block.data[key];

  return typeof value === "string" && value.trim() !== "" ? value : null;
}

function numberData(block: ArticleBlock, key: string): number | null {
  const value = block.data[key];

  return typeof value === "number" ? value : null;
}

function ArticleGalleryView({ images, isImported = false }: ArticleGalleryViewProps) {
  return images.length > 0 ? (
    <div className={isImported ? "article-gallery article-gallery--compact" : "article-gallery"}>
      {images.map((image) => (
        <figure key={image.url}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={image.url} alt={image.alt} />
          {image.caption ? <figcaption>{image.caption}</figcaption> : null}
        </figure>
      ))}
    </div>
  ) : null;
}

function ArticleBlockView({ article, block }: { article: ArticleDetail; block: ArticleBlock }) {
  if (block.type === "paragraph") {
    return <ArticleHtmlChunk html={stringData(block, "html") ?? ""} />;
  }

  if (block.type === "heading") {
    const level = Math.min(3, Math.max(2, numberData(block, "level") ?? 2));
    const text = stringData(block, "text");
    const HeadingTag = `h${level}` as "h2" | "h3";

    return text ? <HeadingTag>{text}</HeadingTag> : null;
  }

  if (block.type === "image") {
    const url = normalizeMediaUrl(stringData(block, "url"));
    const alt = stringData(block, "alt") ?? "";
    const caption = stringData(block, "caption");

    if (!url) {
      return null;
    }

    return (
      <figure>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={url} alt={alt} />
        {caption ? <figcaption>{caption}</figcaption> : null}
      </figure>
    );
  }

  if (block.type === "quote") {
    const text = stringData(block, "text");
    const attribution = stringData(block, "attribution");

    return text ? (
      <blockquote>
        <p>{text}</p>
        {attribution ? <cite>{attribution}</cite> : null}
      </blockquote>
    ) : null;
  }

  if (block.type === "embed") {
    return <ArticleHtmlChunk html={stringData(block, "html") ?? ""} />;
  }

  if (block.type === "ad") {
    const zoneKey = stringData(block, "zone_key");

    return zoneKey ? (
      <AdZone
        articleId={article.id}
        categoryId={article.category?.id}
        categorySlug={article.category?.slug}
        zoneKey={zoneKey}
      />
    ) : null;
  }

  if (block.type === "gallery") {
    const images = articleGalleryImages(block, { fallbackAlt: article.title });

    return <ArticleGalleryView images={images} />;
  }

  return null;
}

function shouldSuppressLegacySourceParagraph(article: ArticleDetail, html: string): boolean {
  return article.presentation_type === "event_guide"
    && Boolean(article.guide?.sources.length)
    && /<strong>\s*Извори?:\s*<\/strong>/iu.test(html);
}

export async function ArticleBody({ article }: ArticleBodyProps) {
  const className = articleBodyClassName(article);

  if (articleBodyMode(article) === "html") {
    const sequence = articleHtmlSequence(article)
      .filter((item) => item.kind !== "html" || !shouldSuppressLegacySourceParagraph(article, item.html));

    return (
      <div className={className}>
        {sequence.map((item, index) => {
          if (item.kind === "ad") {
            return (
              <AdZone
                articleId={item.articleId}
                categoryId={article.category?.id}
                categorySlug={article.category?.slug}
                key={`${item.zoneKey}-${index}`}
                zoneKey={item.zoneKey}
              />
            );
          }

          if (item.kind === "gallery") {
            return <ArticleGalleryView images={item.images} isImported key={`gallery-${index}`} />;
          }

          return <ArticleHtmlChunk html={item.html} key={`html-${index}`} />;
        })}
      </div>
    );
  }

  return (
    <div className={className}>
      {articleBodySequence(article).map((item, index) => {
        if (item.kind === "ad") {
          return (
            <AdZone
              articleId={item.articleId}
              categoryId={article.category?.id}
              categorySlug={article.category?.slug}
              key={`${item.zoneKey}-${index}`}
              zoneKey={item.zoneKey}
            />
          );
        }

        return (
          <ArticleBlockView
            article={article}
            block={item.block}
            key={item.block.id ?? `${item.block.type}-${index}`}
          />
        );
      })}
    </div>
  );
}
