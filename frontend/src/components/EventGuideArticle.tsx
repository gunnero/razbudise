import type { ArticleDetail } from "@/lib/api/client";
import { guideEventDateLabel, guideLastVerifiedLabel, guideQuickFacts } from "@/lib/articles/eventGuidePresentation";

type EventGuideArticleProps = {
  article: ArticleDetail;
};

type GuideEvent = NonNullable<ArticleDetail["guide"]>["events"][number];

function EventLink({ event }: { event: GuideEvent }) {
  const href = event.booking_url ?? event.source_url;
  const label = event.booking_url ? (event.booking_label ?? "Резервирај") : "Детали";

  return href ? (
    <a
      data-public-event={event.booking_url ? "guide_event_booking_clicked" : "guide_event_source_clicked"}
      data-content-key={event.id}
      href={href}
      rel="nofollow noopener noreferrer"
      target="_blank"
    >
      {label}
    </a>
  ) : null;
}

export function EventGuideQuickFacts({ article }: EventGuideArticleProps) {
  const facts = guideQuickFacts(article);

  return facts.length > 0 ? (
    <div className="event-guide-facts" aria-label="Кратки информации за водичот">
      {facts.map((fact) => (
        <span key={fact}>{fact}</span>
      ))}
    </div>
  ) : null;
}

export function EventGuideHighlights({ article }: EventGuideArticleProps) {
  const events = article.guide?.events.slice(0, 3) ?? [];

  return events.length > 0 ? (
    <section className="event-guide-section" aria-labelledby="event-guide-highlights-heading">
      <div className="event-guide-section-head">
        <p className="kicker">Водич</p>
        <h2 id="event-guide-highlights-heading">Најважно оваа недела</h2>
      </div>
      <div className="event-guide-card-grid">
        {events.map((event) => {
          const dateLabel = guideEventDateLabel(event);
          const details = [event.time, event.location, event.price_label].filter(Boolean);

          return (
            <article className="event-guide-card" key={event.id}>
              {dateLabel ? (
                <time dateTime={event.start_date ?? undefined}>{dateLabel}</time>
              ) : null}
              <h3>{event.title}</h3>
              {details.length > 0 ? <p className="event-guide-card-meta">{details.join(" · ")}</p> : null}
              {event.short_description ? <p>{event.short_description}</p> : null}
              <EventLink event={event} />
            </article>
          );
        })}
      </div>
    </section>
  ) : null;
}

export function EventGuidePracticalNotes({ article }: EventGuideArticleProps) {
  const notes = article.guide?.practical_notes ?? [];

  return notes.length > 0 ? (
    <aside className="event-guide-note-block" aria-labelledby="event-guide-notes-heading">
      <h2 id="event-guide-notes-heading">Пред да тргнете</h2>
      <ul>
        {notes.map((note) => (
          <li key={note}>{note}</li>
        ))}
      </ul>
    </aside>
  ) : null;
}

export function EventGuideSources({ article }: EventGuideArticleProps) {
  const sources = article.guide?.sources ?? [];
  const verified = guideLastVerifiedLabel(article.guide?.meta.last_verified_at);

  return sources.length > 0 ? (
    <section className="event-guide-source-block" aria-labelledby="event-guide-sources-heading">
      <h2 id="event-guide-sources-heading">Проверени извори</h2>
      <ul>
        {sources.map((source) => (
          <li key={source.url}>
            <a
              data-public-event="guide_source_clicked"
              data-content-key={source.label}
              href={source.url}
              rel="nofollow noopener noreferrer"
              target="_blank"
            >
              {source.label}
            </a>
          </li>
        ))}
      </ul>
      {verified ? <p>{verified}</p> : null}
    </section>
  ) : null;
}
