import type { Metadata } from "next";
import Link from "next/link";

import { ContentSection, EditorialPageHero, PublicPageCTA, PublicPageShell, SecondaryPageNavigation } from "@/components/public/PublicPage";
import { getCategories } from "@/lib/api/client";

export const metadata: Metadata = {
  title: "За нас",
  description: "Мисијата, темите и редакциските принципи на Разбуди се.",
  alternates: { canonical: "/za-nas" },
};

const coverageNames = ["Живот", "Култура", "Урбано", "Интервјуа", "Настани", "Бизнис", "Локални приказни", "Колумни"];
const principles = [
  ["Јасен и човечки јазик", "Пишуваме разбирливо, блиску и без непотребна дистанца."],
  ["Почит кон читателот", "Содржината треба да биде корисна, чесно претставена и лесна за читање."],
  ["Одговорно означување", "ПР и спонзорираните содржини ги одвојуваме од редакциските текстови."],
  ["Простор за локални гласови", "Им даваме место на луѓето, настаните и приказните што ја градат локалната средина."],
];
const missionPrinciples = [
  ["Локални приказни", "Луѓе, места и настани што заслужуваат внимание."],
  ["Корисна содржина", "Теми што му помагаат на читателот да открие, разбере или одлучи."],
  ["Човечки пристап", "Јасен јазик, почит и без непотребна дистанца."],
];

export default async function AboutPage() {
  const categories = await getCategories(1, 100).then((response) => response.data).catch(() => []);
  const coverage = coverageNames.map((name) => categories.find((category) => category.name.toLocaleLowerCase("mk") === name.toLocaleLowerCase("mk"))).filter(Boolean);

  return (
    <PublicPageShell>
      <EditorialPageHero eyebrow="РАЗБУДИ СЕ" title="Приказни што вреди да се забележат" description="Разбуди се пишува за животот, културата, луѓето, локалните приказни, бизнисот, настаните и корисните теми од секојдневието." />
      <SecondaryPageNavigation />
      <section className="public-mission-section" aria-labelledby="mission-heading">
        <div className="public-mission-statement">
          <p className="kicker">НАШАТА МИСИЈА</p>
          <h2 id="mission-heading">Да им дадеме простор на приказните што нè поврзуваат со луѓето и местата околу нас.</h2>
          <div className="public-mission-copy">
            <p>Разбуди се постои за да собира приказни кои им даваат простор на луѓето, местата, настаните и малите градски моменти што вреди да се забележат.</p>
            <p>Пишуваме со љубопитност, јасен јазик и почит кон публиката која сака нешто блиско, корисно и човечко.</p>
          </div>
        </div>
        <ol className="public-mission-principles" aria-label="Принципи на мисијата">
          {missionPrinciples.map(([title, text], index) => (
            <li key={title}>
              <span aria-hidden="true">0{index + 1}</span>
              <div>
                <h3>{title}</h3>
                <p>{text}</p>
              </div>
            </li>
          ))}
        </ol>
      </section>
      {coverage.length ? <ContentSection eyebrow="ШТО ПОКРИВАМЕ" title="Теми за секојдневието и градот"><div className="public-topic-grid">{coverage.map((category) => category ? <Link data-public-event="about_category_clicked" data-content-key={category.slug} href={`/category/${category.slug}`} key={category.slug}>{category.name}<span>Прочитај повеќе</span></Link> : null)}</div></ContentSection> : null}
      <ContentSection eyebrow="УРЕДНИЧКИ ПРИНЦИПИ" title="Како пристапуваме кон содржината"><div className="public-card-grid public-card-grid-four">{principles.map(([title, text], index) => <article className="public-info-card" key={title}><span>0{index + 1}</span><h3>{title}</h3><p>{text}</p></article>)}</div></ContentSection>
      <ContentSection eyebrow="ТРАНСПАРЕНТНОСТ" title="Јасно кажуваме каква содржина читате"><div className="public-transparency"><p>Содржината може да биде редакциска, авторска, ПР или спонзорирана. Платената содржина се означува јасно за да се разликува од редакциските текстови.</p><div><Link href="/uslovi-za-koristenje">Услови за користење</Link><Link href="/cenovnik-za-reklamiranje">Рекламирање</Link></div></div></ContentSection>
      <PublicPageCTA surface="light" title="Луѓето зад Разбуди се" description="Погледнете ги профилите на тимот, авторите и соработниците што ја градат редакцијата." primary={{ href: "/tim", label: "Запознајте го тимот" }} secondary={{ href: "/kontakt", label: "Предложете приказна" }} />
    </PublicPageShell>
  );
}
