import assert from "node:assert/strict";
import test from "node:test";

import {
  directDepartmentMembers,
  orgChartDepartmentSlugs,
  teamMemberAuthorHref,
  teamMemberBadges,
  teamMemberCardClassName,
  teamMemberInitials,
  teamMemberOrgLine,
  visibleOrgChartDepartments,
  visibleTeamDepartmentSections,
  visibleTeamSections,
} from "./teamPresentation.mjs";

const humanMember = {
  name: "Elena",
  member_type: "human",
  is_ai: false,
  roles: [
    { name: "Co-owner", slug: "co-owner" },
    { name: "Editor", slug: "editor" },
  ],
  author: {
    slug: "elena-author",
    url: "/profil/elena-author",
  },
};

const aiMember = {
  name: "Mila Andonova",
  member_type: "ai",
  is_ai: true,
  roles: [
    { name: "AI Employee", slug: "ai-employee" },
    { name: "Author", slug: "author" },
  ],
  author: null,
};

test("visibleTeamSections keeps grouped public sections that have members", () => {
  const sections = visibleTeamSections([
    { key: "founders", title: "Раководство", members: [humanMember] },
    { key: "editors", title: "Уредници", members: [] },
    { key: "authors", title: "Автори", members: [aiMember] },
  ]);

  assert.deepEqual(sections.map((section) => section.key), ["founders", "authors"]);
});

test("public team hierarchy keeps authors after leadership and editors", () => {
  const sections = visibleTeamSections([
    { key: "founders", title: "Раководство", members: [humanMember] },
    { key: "editor_in_chief", title: "Главен и одговорен уредник", members: [] },
    { key: "editors", title: "Уредници", members: [humanMember] },
    { key: "authors", title: "Автори", members: [{ ...humanMember, name: "Romina Markovska", roles: [{ name: "Автор", slug: "avtor" }] }] },
  ]);

  assert.deepEqual(sections.map((section) => section.key), ["founders", "editors", "authors"]);
  assert.deepEqual(sections.at(-1).members.map((member) => member.name), ["Romina Markovska"]);
});

test("teamMemberBadges includes role badges and a separate AI badge", () => {
  assert.deepEqual(teamMemberBadges(humanMember), ["Co-owner", "Editor"]);
  assert.deepEqual(teamMemberBadges(aiMember), ["AI Crew", "AI Employee", "Author"]);
});

test("teamMemberAuthorHref returns author profile links only for linked authors", () => {
  assert.equal(teamMemberAuthorHref(humanMember), "/profil/elena-author");
  assert.equal(teamMemberAuthorHref(aiMember), null);
});

test("team member card helpers support public Team page rendering", () => {
  assert.equal(teamMemberInitials({ name: "Mila Andonova" }), "MA");
  assert.equal(teamMemberInitials({ name: "Martin" }), "M");
  assert.equal(teamMemberCardClassName(aiMember), "team-member-card team-member-card-ai");
  assert.equal(teamMemberCardClassName(humanMember), "team-member-card");
});

test("team page can prefer department sections when the API provides them", () => {
  const departments = visibleTeamDepartmentSections([
    { key: "leadership", title: "Leadership", members: [humanMember] },
    { key: "operations", title: "Operations", members: [] },
    { key: "writing", title: "Writing", members: [aiMember] },
  ]);

  assert.deepEqual(departments.map((department) => department.key), ["leadership", "writing"]);
});

test("teamMemberOrgLine adds department and manager details when available", () => {
  assert.equal(
    teamMemberOrgLine({
      primary_department: { name: "Writing" },
      manager: { name: "Elena", slug: "elena", url: "/tim#elena" },
    }),
    "Writing · одговорен: Elena",
  );

  assert.equal(teamMemberOrgLine({ primary_department: { name: "Leadership" }, manager: null }), "Leadership");
});

test("org chart helpers keep department nesting readable", () => {
  const orgChart = [
    {
      slug: "leadership",
      members: [],
      child_departments: [
        {
          slug: "editorial",
          members: [humanMember],
          child_departments: [],
        },
      ],
    },
    {
      slug: "hidden",
      members: [],
      child_departments: [],
      status: "hidden",
    },
  ];

  assert.deepEqual(orgChartDepartmentSlugs(orgChart), ["leadership", "editorial", "hidden"]);
  assert.deepEqual(visibleOrgChartDepartments(orgChart).map((department) => department.slug), ["leadership"]);
  assert.deepEqual(directDepartmentMembers(orgChart[0]).map((member) => member.name), []);
  assert.deepEqual(directDepartmentMembers(orgChart[0].child_departments[0]).map((member) => member.name), ["Elena"]);
});
