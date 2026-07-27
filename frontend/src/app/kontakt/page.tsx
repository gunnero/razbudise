import type { Metadata } from "next";
import Link from "next/link";

import { ContentSection, EditorialPageHero, PublicPageCTA, PublicPageShell, SecondaryPageNavigation } from "@/components/public/PublicPage";

export const metadata: Metadata = {
  title: "Контакт",
  description: "Контактирајте ја редакцијата, маркетингот или техничката поддршка на Разбуди се.",
  alternates: { canonical: "/kontakt" },
};

const purposes = [
  { key: "editorial", title: "Редакција", text: "Предлог за тема, локална приказна, интервју или настан.", subject: "Редакциско барање" },
  { key: "marketing", title: "Маркетинг", text: "Рекламирање, спонзорирана содржина, партнерство или кампања.", subject: "Маркетинг и соработка" },
  { key: "correction", title: "Исправка на објава", text: "Фактичка исправка, авторски права, наведување извор или барање за отстранување.", subject: "Исправка на објава" },
  { key: "support", title: "Техничка поддршка", text: "Проблем со профил, веб-страница, форма или пристапност.", subject: "Техничка поддршка" },
];

export default function ContactPage() {
  return (
    <PublicPageShell>
      <EditorialPageHero eyebrow="КОНТАКТ" title="Да разговараме" description="Изберете го типот на вашето барање за да стигне до вистинскиот тим." />
      <SecondaryPageNavigation />
      <ContentSection eyebrow="ИЗБЕРЕТЕ ТЕМА" title="Како можеме да помогнеме?" description="Секоја порака треба да содржи јасен наслов, релевантни линкови и контакт за одговор.">
        <div className="public-card-grid public-card-grid-four">
          {purposes.map((purpose) => (
            <article className="public-info-card" key={purpose.key}>
              <span>Барање</span><h3>{purpose.title}</h3><p>{purpose.text}</p>
              <a data-public-event="contact_purpose_selected" data-purpose={purpose.key} href={`mailto:info@razbudise.mk?subject=${encodeURIComponent(purpose.subject)}`}>Испрати порака</a>
            </article>
          ))}
        </div>
      </ContentSection>
      <section className="public-contact-note">
        <div><p className="kicker">ПРЕД ДА ИСПРАТИТЕ</p><h2>Помогнете ни побрзо да го разбереме барањето</h2></div>
        <p>Барањата се разгледуваат во работни денови. Итните редакциски исправки означете ги јасно во насловот. Редакцијата ги разгледува предлозите, но не гарантира објава.</p>
      </section>
      <p className="form-privacy-notice public-contact-privacy">Податоците ги користиме за да одговориме на вашето барање. <Link href="/politika-za-privatnost">Политика за приватност</Link>.</p>
      <PublicPageCTA surface="light" eyebrow="РЕКЛАМИРАЊЕ" title="Планирате рекламна кампања?" description="Погледнете ги јавните позиции и пакетите или испратете барање за проверка на достапноста." primary={{ href: "/cenovnik-za-reklamiranje", label: "Погледни го ценовникот" }} secondary={{ href: "/reklami/zakazi", label: "Закажи кампања" }} />
    </PublicPageShell>
  );
}
