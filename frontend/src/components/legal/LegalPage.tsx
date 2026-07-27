import type { ReactNode } from "react";
import { EditorialPageHero, PublicPageShell, SecondaryPageNavigation } from "@/components/public/PublicPage";

type LegalPageSection = {
  body: ReactNode[];
  id?: string;
  title: string;
};

export function LegalPage({
  action,
  children,
  description,
  eyebrow,
  sections,
  showTableOfContents = false,
  title,
}: {
  action?: {
    href: string;
    label: string;
  };
  children?: ReactNode;
  description: string;
  eyebrow: string;
  sections: LegalPageSection[];
  showTableOfContents?: boolean;
  title: string;
}) {
  return (
    <PublicPageShell className="legal-document-page">
      <EditorialPageHero
        eyebrow={eyebrow}
        title={title}
        description={description}
        actions={action ? <a className="public-primary-button" href={action.href}>{action.label}</a> : undefined}
      />
      <SecondaryPageNavigation />

      {sections.length > 0 ? (
        <div className={showTableOfContents ? "legal-document-layout" : "legal-document-layout legal-document-layout-simple"}>
          {showTableOfContents ? (
            <aside className="legal-toc">
              <details open>
                <summary>Содржина</summary>
                <nav aria-label={`Содржина за ${title}`}>
                  {sections.map((section, index) => <a href={`#${section.id ?? `section-${index + 1}`}`} key={section.title}>{index + 1}. {section.title}</a>)}
                </nav>
              </details>
            </aside>
          ) : null}
          <section className="legal-page" aria-label={title}>
          {sections.map((section, index) => (
            <article id={section.id ?? `section-${index + 1}`} key={section.title}>
              <h2>{section.title}</h2>
              {section.body.map((paragraph, paragraphIndex) => (
                <p key={typeof paragraph === "string" ? paragraph : `${section.title}-${paragraphIndex}`}>{paragraph}</p>
              ))}
            </article>
          ))}
          </section>
        </div>
      ) : null}

      {children}
    </PublicPageShell>
  );
}
