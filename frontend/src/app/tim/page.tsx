import type { Metadata } from "next";
import { SiteHeader } from "@/components/SiteHeader";
import { TeamMemberCard } from "@/components/TeamMemberCard";
import { getTeamMembers } from "@/lib/api/client";
import { visibleTeamDepartmentSections, visibleTeamSections } from "@/lib/team/teamPresentation";

export const metadata: Metadata = {
  title: "Разбуди се тим",
  description: "Луѓето, авторите, уредниците и соработниците зад Разбуди се.",
  alternates: {
    canonical: "/tim",
  },
  openGraph: {
    title: "Разбуди се тим",
    description: "Луѓето, авторите, уредниците и соработниците зад Разбуди се.",
    type: "website",
    url: "/tim",
  },
};

export default async function RazbudiseTeamDirectoryPage() {
  const response = await getTeamMembers();
  const departmentSections = visibleTeamDepartmentSections(response.data.department_sections ?? []);
  const sections = visibleTeamSections(response.data.sections);
  const peopleCount = new Set(sections.flatMap((section) => section.members.map((member) => member.id))).size;
  const departmentCount = departmentSections.length;

  return (
    <main className="site-shell">
      <SiteHeader />

      <section className="page-heading page-heading-wide team-directory-hero">
        <p className="kicker">Разбуди се тим</p>
        <h1>Луѓето зад Разбуди се</h1>
        <p>
          Тимот, авторите, уредниците и соработниците што ја градат редакцијата,
          локалните приказни и платформата.
        </p>
        {peopleCount || departmentCount ? (
          <dl className="author-directory-stats team-directory-stats">
            {peopleCount ? <div><dt>Членови</dt><dd>{peopleCount.toLocaleString("mk-MK")}</dd></div> : null}
            {departmentCount ? <div><dt>Оддели</dt><dd>{departmentCount.toLocaleString("mk-MK")}</dd></div> : null}
          </dl>
        ) : null}
      </section>

      {sections.length > 0 ? (
        <div className="team-section-stack">
          {sections.map((section) => (
            <section className="team-section" id={section.key} key={section.key} aria-labelledby={`team-${section.key}`}>
              <div className="section-title-row">
                <div>
                  <p className="kicker">Разбуди се тим</p>
                  <h2 id={`team-${section.key}`}>{section.title}</h2>
                </div>
              </div>

              <div className="team-member-grid">
                {section.members.map((member) => (
                  <TeamMemberCard member={member} key={`${section.key}-${member.slug}`} />
                ))}
              </div>
            </section>
          ))}
        </div>
      ) : (
        <section className="empty-state">
          <strong>Тимот наскоро ќе биде објавен.</strong>
          <p>Кога профилите ќе бидат активни, ќе се појават тука.</p>
        </section>
      )}
    </main>
  );
}
