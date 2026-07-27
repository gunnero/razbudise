import Link from "next/link";
import type { AuthorListItem } from "@/lib/api/client";
import { authorBiographyPreview, authorInitials, authorLatestDateLabel } from "@/lib/authors/authorPresentation";
import { selectMediaUrl } from "@/lib/media/mediaUrls";

export function AuthorCard({ author }: { author: AuthorListItem }) {
  const avatarUrl = selectMediaUrl(author.avatar, { variant: "thumbnail" });
  const biography = authorBiographyPreview(author);
  const latestDate = authorLatestDateLabel(author.latest_article?.published_at);

  return (
    <article className="author-card author-directory-card">
      <div className="avatar-frame author-directory-avatar">
        {avatarUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={avatarUrl} alt={author.avatar?.alt_text ?? author.name} />
        ) : (
          <span aria-label={`Иницијали: ${authorInitials(author.name)}`}>{authorInitials(author.name)}</span>
        )}
      </div>
      <div className="author-directory-card-content">
        <p className="kicker">{author.job_title ?? "Автор"}</p>
        <h2>
          <Link href={`/profil/${author.slug}`}>{author.name}</Link>
        </h2>
        {biography ? <p className="author-directory-bio">{biography}</p> : null}
        {author.editorial_focus.length ? (
          <ul className="author-focus-list" aria-label={`Теми на ${author.name}`}>
            {author.editorial_focus.slice(0, 3).map((category) => <li key={category.slug}><Link href={category.url}>{category.name}</Link></li>)}
          </ul>
        ) : null}
        <dl className="author-directory-meta">
          <div><dt>Објави</dt><dd>{author.article_count}</dd></div>
          {latestDate ? <div><dt>Последна објава</dt><dd>{latestDate}</dd></div> : null}
        </dl>
        {author.latest_article ? <p className="author-latest"><span>Најново</span><Link href={author.latest_article.url}>{author.latest_article.title}</Link></p> : null}
        <Link className="author-profile-link" href={`/profil/${author.slug}`}>Погледнете го профилот <span aria-hidden="true">→</span></Link>
      </div>
    </article>
  );
}
