import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const root = new URL("../../../", import.meta.url);
const read = (path) => readFileSync(new URL(path, root), "utf8");

test("public /tim directory is the RazbudiSe team directory", () => {
  const page = read("src/app/tim/page.tsx");

  assert.ok(page.includes('title: "Разбуди се тим"'));
  assert.ok(page.includes("getTeamMembers"));
  assert.ok(page.includes("visibleTeamDepartmentSections"));
  assert.ok(page.includes("TeamMemberCard"));
  assert.ok(page.includes("Луѓето зад Разбуди се"));
  assert.equal(page.includes("Пребарај автор"), false);
  assert.equal(page.includes("authorDirectoryHref"), false);
  assert.equal(page.includes('action="/tim"'), false);
});

test("author cards expose real editorial metadata and an initials fallback", () => {
  const card = read("src/components/AuthorCard.tsx");
  const presentation = read("src/lib/authors/authorPresentation.ts");

  assert.ok(card.includes("authorInitials(author.name)"));
  assert.ok(card.includes("authorBiographyPreview(author)"));
  assert.ok(card.includes("author.editorial_focus"));
  assert.ok(card.includes("author.article_count"));
  assert.ok(card.includes("author.latest_article"));
  assert.ok(card.includes("authorLatestDateLabel"));
  assert.ok(presentation.includes("bio_preview?.trim() || author.short_bio?.trim() || null"));
});

test("team directory and author profile preserve public routes, SEO, and responsive grids", () => {
  const directory = read("src/app/tim/page.tsx");
  const profile = read("src/app/profil/[slug]/page.tsx");
  const styles = read("src/app/globals.css");

  assert.ok(directory.includes('canonical: "/tim"'));
  assert.ok(profile.includes("authorProfileJsonLd"));
  assert.ok(profile.includes("author.editorial_focus"));
  assert.ok(profile.includes("getAuthorArticles"));
  assert.match(styles, /@media \(max-width: 820px\)[\s\S]*?\.team-member-grid,/);
  assert.match(styles, /@media \(max-width: 820px\)[\s\S]*?\.team-member-card \{/);
});
