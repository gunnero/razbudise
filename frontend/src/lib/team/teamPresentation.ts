import type { TeamDepartmentItem, TeamDepartmentSection, TeamMemberItem, TeamSection } from "@/lib/api/client";

type TeamMemberPresentation = Pick<
  TeamMemberItem,
  "name" | "is_ai" | "member_type" | "roles" | "author" | "primary_department" | "manager"
>;

export function visibleTeamSections(sections: TeamSection[]): TeamSection[] {
  return sections.filter((section) => section.members.length > 0);
}

export function visibleTeamDepartmentSections(sections: TeamDepartmentSection[]): TeamDepartmentSection[] {
  return sections.filter((section) => section.members.length > 0);
}

export function teamMemberBadges(member: TeamMemberPresentation): string[] {
  const roleNames = member.roles.map((role) => role.name);

  if (!member.is_ai && member.member_type !== "ai") {
    return roleNames;
  }

  return ["AI Crew", ...roleNames.filter((roleName) => roleName !== "AI Crew")];
}

export function teamMemberAuthorHref(member: Pick<TeamMemberItem, "author">): string | null {
  if (!member.author) {
    return null;
  }

  return member.author.url ?? `/profil/${member.author.slug}`;
}

export function teamMemberInitials(member: Pick<TeamMemberItem, "name">): string {
  const initials = member.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.slice(0, 1).toUpperCase())
    .join("");

  return initials || "?";
}

export function teamMemberCardClassName(member: Pick<TeamMemberItem, "is_ai" | "member_type">): string {
  return member.is_ai || member.member_type === "ai"
    ? "team-member-card team-member-card-ai"
    : "team-member-card";
}

export function teamMemberOrgLine(member: Pick<TeamMemberItem, "primary_department" | "manager">): string | null {
  const department = member.primary_department?.name ?? null;
  const manager = member.manager?.name ?? null;

  if (department && manager) {
    return `${department} · одговорен: ${manager}`;
  }

  return department ?? (manager ? `Одговорен: ${manager}` : null);
}

export function visibleOrgChartDepartments(departments: TeamDepartmentItem[]): TeamDepartmentItem[] {
  return departments
    .filter((department) => !["hidden", "inactive"].includes(department.status))
    .map((department) => ({
      ...department,
      child_departments: visibleOrgChartDepartments(department.child_departments),
    }));
}

export function orgChartDepartmentSlugs(departments: Pick<TeamDepartmentItem, "slug" | "child_departments">[]): string[] {
  return departments.flatMap((department) => [
    department.slug,
    ...orgChartDepartmentSlugs(department.child_departments),
  ]);
}

export function directDepartmentMembers(department: Pick<TeamDepartmentItem, "members">): TeamMemberItem[] {
  return department.members;
}
