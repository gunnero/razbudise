import Link from "next/link";
import type { ArticleDetail } from "@/lib/api/client";
import { articleHeroMetaItems, articleImagePresentation } from "@/lib/articles/articlePresentation";

type ArticleEnhancementProps = {
  article: ArticleDetail;
};

export function ArticleReadingSummary({ article }: ArticleEnhancementProps) {
  const metaItems = articleHeroMetaItems(article).filter((item) => ["category", "reading_time"].includes(item.key));

  if (!article.excerpt && metaItems.length === 0) {
    return null;
  }

  return (
    <aside className="article-reading-summary" aria-labelledby="article-reading-summary-heading">
      <p className="kicker">Во кратко</p>
      <h2 id="article-reading-summary-heading">Што да очекувате</h2>
      {article.excerpt ? <p>{article.excerpt}</p> : null}
      {metaItems.length > 0 ? (
        <dl>
          {metaItems.map((item) => (
            <div key={item.key}>
              <dt>{item.key === "reading_time" ? "Читање" : "Рубрика"}</dt>
              <dd>
                {item.href ? (
                  <Link href={item.href}>
                    {item.label}
                  </Link>
                ) : (
                  item.label
                )}
              </dd>
            </div>
          ))}
        </dl>
      ) : null}
    </aside>
  );
}

export function ArticleAuthorCard({ article }: ArticleEnhancementProps) {
  if (!article.author?.name || !article.author.url) {
    return null;
  }

  const avatar = articleImagePresentation({
    title: article.author.name,
    featured_image: article.author.avatar ?? null,
  });
  const focus = article.author.editorial_focus ?? [];

  return (
    <aside className="article-author-card" aria-label="Автор на објавата">
      <div className="article-author-avatar" aria-hidden={!avatar.hasImage}>
        {avatar.hasImage && avatar.url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={avatar.url} alt="" />
        ) : (
          <span>{article.author.name.slice(0, 1)}</span>
        )}
      </div>
      <div>
        <p className="kicker">Автор</p>
        <h2>{article.author.name}</h2>
        {article.author.short_bio ? <p>{article.author.short_bio}</p> : null}
        {focus.length > 0 ? (
          <div className="article-author-focus" aria-label="Уреднички фокус">
            {focus.map((category) => (
              <Link href={category.url} key={category.slug}>
                {category.name}
              </Link>
            ))}
          </div>
        ) : null}
        <Link data-public-event="article_author_profile_clicked" data-content-key={article.author.slug ?? article.author.name} href={article.author.url}>
          Повеќе од авторот
        </Link>
      </div>
    </aside>
  );
}
