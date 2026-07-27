import type { Metadata } from "next";
import Link from "next/link";
import { SiteHeader } from "@/components/SiteHeader";
import type { TeamDepartmentItem, TeamMemberItem } from "@/lib/api/client";
import { getTeamOrgChart } from "@/lib/api/client";
import {
  directDepartmentMembers,
  teamMemberBadges,
  teamMemberOrgLine,
  visibleOrgChartDepartments,
} from "@/lib/team/teamPresentation";

export const metadata: Metadata = {
  title: "Организација",
  description: "Организациска структура, оддели, менаџери и Team/Crew членови на Разбуди се.",
  alternates: {
    canonical: "/tim/organizacija",
  },
};

export default async function TeamOrganizationPage() {
  const response = await getTeamOrgChart();
  const departments = visibleOrgChartDepartments(response.data.departments);

  return (
    <main className="site-shell">
      <SiteHeader />

      <section className="page-heading page-heading-wide">
        <p className="kicker">Организација</p>
        <h1>Како е поставен тимот</h1>
        <p>
          Преглед на лидерство, оддели, менаџери и Team/Crew членови кои ја поддржуваат редакцијата
          и јавните профили на RazbudiSe.
        </p>
      </section>

      {departments.length > 0 ? (
        <div className="org-chart-stack">
          {departments.map((department) => (
            <DepartmentNode department={department} key={department.slug} level={0} />
          ))}
        </div>
      ) : (
        <section className="empty-state">
          <strong>Организациската структура наскоро ќе биде објавена.</strong>
          <p>Кога одделите ќе бидат активни, ќе се појават тука.</p>
        </section>
      )}
    </main>
  );
}

function DepartmentNode({ department, level }: { department: TeamDepartmentItem; level: number }) {
  const members = directDepartmentMembers(department);

  return (
    <section
      className={level === 0 ? "org-department" : "org-department org-department-nested"}
      id={department.slug}
      style={{ "--org-level": level } as React.CSSProperties}
    >
      <div className="org-department-header">
        <div>
          <p className="kicker">{level === 0 ? "Leadership" : "Department"}</p>
          <h2>{department.name}</h2>
          {department.description ? <p>{department.description}</p> : null}
        </div>
        {department.manager ? (
          <div className="org-manager">
            <span>Manager</span>
            <MemberLink member={department.manager} />
          </div>
        ) : null}
      </div>

      {members.length > 0 ? (
        <div className="org-member-list">
          {members.map((member) => (
            <OrgMember member={member} key={member.slug} />
          ))}
        </div>
      ) : null}

      {department.child_departments.length > 0 ? (
        <div className="org-child-departments">
          {department.child_departments.map((child) => (
            <DepartmentNode department={child} key={child.slug} level={level + 1} />
          ))}
        </div>
      ) : null}
    </section>
  );
}

function OrgMember({ member }: { member: TeamMemberItem }) {
  const badges = teamMemberBadges(member);
  const orgLine = teamMemberOrgLine(member);

  return (
    <article className={member.is_ai ? "org-member org-member-ai" : "org-member"}>
      <div>
        <MemberLink member={member} />
        {orgLine ? <p>{orgLine}</p> : null}
      </div>
      <div className="team-role-badges" aria-label="Team positions">
        {badges.map((badge) => (
          <span className={badge === "AI Crew" ? "team-role-badge team-role-badge-ai" : "team-role-badge"} key={badge}>
            {badge}
          </span>
        ))}
      </div>
    </article>
  );
}

function MemberLink({ member }: { member: TeamMemberItem }) {
  return (
    <span className="org-member-name">
      <Link href={member.url}>{member.name}</Link>
      {member.author ? <Link href={member.author.url}>Автор</Link> : null}
    </span>
  );
}
