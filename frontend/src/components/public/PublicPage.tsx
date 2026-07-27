import Link from "next/link";
import type { ReactNode } from "react";

import { SiteHeader } from "@/components/SiteHeader";
import { SiteContainer } from "@/components/layout/SiteContainer";

export function PublicPageShell({ children, className = "" }: { children: ReactNode; className?: string }) {
  return (
    <SiteContainer as="main" className={`site-shell public-secondary-page ${className}`.trim()}>
      <SiteHeader variant="editorial" />
      {children}
    </SiteContainer>
  );
}

export function EditorialPageHero({ eyebrow, title, description, actions }: { eyebrow: string; title: string; description: string; actions?: ReactNode }) {
  return (
    <header className="public-page-hero">
      <p className="kicker">{eyebrow}</p>
      <h1>{title}</h1>
      <p>{description}</p>
      {actions ? <div className="public-page-actions">{actions}</div> : null}
    </header>
  );
}

export function ContentSection({ eyebrow, title, description, children, id, className = "" }: { eyebrow?: string; title: string; description?: string; children: ReactNode; id?: string; className?: string }) {
  return (
    <section className={`public-content-section ${className}`.trim()} id={id}>
      <header className="public-section-heading">
        {eyebrow ? <p className="kicker">{eyebrow}</p> : null}
        <h2>{title}</h2>
        {description ? <p>{description}</p> : null}
      </header>
      {children}
    </section>
  );
}

type PublicPageCTASurface = "light";

export function PublicPageCTA({ eyebrow, title, description, primary, secondary, surface }: { eyebrow?: string; title: string; description: string; primary: { href: string; label: string }; secondary?: { href: string; label: string }; surface: PublicPageCTASurface }) {
  return (
    <section className="public-page-cta" data-surface={surface}>
      {eyebrow ? <p className="kicker">{eyebrow}</p> : null}
      <h2>{title}</h2>
      <p>{description}</p>
      <div className="public-page-actions">
        <Link className="public-primary-button" href={primary.href}>{primary.label}</Link>
        {secondary ? <Link className="public-text-link" href={secondary.href}>{secondary.label}</Link> : null}
      </div>
    </section>
  );
}

export function SecondaryPageNavigation() {
  return (
    <nav className="secondary-page-nav" aria-label="Информации">
      <Link href="/za-nas">За нас</Link>
      <Link href="/kontakt">Контакт</Link>
      <Link href="/impresum">Импресум</Link>
      <Link href="/uslovi-za-koristenje">Услови за користење</Link>
    </nav>
  );
}
