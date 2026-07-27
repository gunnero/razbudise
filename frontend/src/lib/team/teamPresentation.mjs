export function visibleTeamSections(sections) {
  return sections.filter((section) => section.members.length > 0);
}

export function visibleTeamDepartmentSections(sections) {
  return sections.filter((section) => section.members.length > 0);
}

export function teamMemberBadges(member) {
  const roleNames = member.roles.map((role) => role.name);

  if (!member.is_ai && member.member_type !== "ai") {
    return roleNames;
  }

  return ["AI Crew", ...roleNames.filter((roleName) => roleName !== "AI Crew")];
}

export function teamMemberAuthorHref(member) {
  if (!member.author) {
    return null;
  }

  return member.author.url ?? `/profil/${member.author.slug}`;
}

export function teamMemberInitials(member) {
  const initials = member.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.slice(0, 1).toUpperCase())
    .join("");

  return initials || "?";
}

export function teamMemberCardClassName(member) {
  return member.is_ai || member.member_type === "ai"
    ? "team-member-card team-member-card-ai"
    : "team-member-card";
}

export function teamMemberOrgLine(member) {
  const department = member.primary_department?.name ?? null;
  const manager = member.manager?.name ?? null;

  if (department && manager) {
    return `${department} · одговорен: ${manager}`;
  }

  return department ?? (manager ? `Одговорен: ${manager}` : null);
}

export function visibleOrgChartDepartments(departments) {
  return departments
    .filter((department) => !["hidden", "inactive"].includes(department.status))
    .map((department) => ({
      ...department,
      child_departments: visibleOrgChartDepartments(department.child_departments),
    }));
}

export function orgChartDepartmentSlugs(departments) {
  return departments.flatMap((department) => [
    department.slug,
    ...orgChartDepartmentSlugs(department.child_departments),
  ]);
}

export function directDepartmentMembers(department) {
  return department.members;
}
