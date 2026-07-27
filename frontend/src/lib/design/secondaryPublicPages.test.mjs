import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const root = new URL("../../../", import.meta.url);
const read = (path) => readFileSync(new URL(path, root), "utf8");
const routes = ["src/app/reklami/zakazi/page.tsx", "src/app/kontakt/page.tsx", "src/app/za-nas/page.tsx", "src/app/search/page.tsx", "src/app/impresum/page.tsx", "src/app/uslovi-za-koristenje/page.tsx"];

test("secondary public routes use the shared theme-aware editorial shell", () => {
  for (const route of routes) {
    const source = read(route);
    assert.ok(source.includes("PublicPageShell") || source.includes("LegalPage"));
    assert.ok(source.includes("metadata"));
  }
  const shell = read("src/components/public/PublicPage.tsx");
  assert.ok(shell.includes('<SiteHeader variant="editorial" />'));
  assert.ok(!shell.includes("showThemeToggle={false}"));
  assert.ok(!read("src/app/globals.css").includes('color-scheme: light;\n  --paper: #fbf7f0'));
});

test("shared header exposes a constrained capability API", () => {
  const header = read("src/components/SiteHeader.tsx");
  assert.ok(header.includes('"editorial" | "commercial" | "minimal"'));
  assert.ok(header.includes("showThemeToggle"));
  assert.ok(header.includes("showSearch"));
  assert.ok(header.includes("showPrimaryNavigation"));
});

test("booking uses public placement presentation and avoids raw internal labels", () => {
  const editor = read("src/components/ads/AdBookingLineItemEditor.tsx");
  assert.ok(editor.includes("publicAdProducts"));
  assert.ok(editor.includes("Рекламна позиција"));
  assert.ok(!editor.includes("Рекламна зона"));
  assert.ok(!editor.includes("Image ID"));
});

test("search preserves URL-driven server search and graceful states", () => {
  const search = read("src/app/search/page.tsx");
  assert.ok(search.includes('action="/search"'));
  assert.ok(search.includes("searchArticles(query"));
  assert.ok(search.includes("Пребарувањето моментално не е достапно"));
  assert.ok(search.includes("public-search-categories"));
});

test("about mission uses a content-driven two-column editorial block", () => {
  const about = read("src/app/za-nas/page.tsx");
  const styles = read("src/app/globals.css");

  assert.ok(about.includes('className="public-mission-statement"'));
  assert.ok(about.includes('className="public-mission-principles"'));
  assert.ok(about.includes("Локални приказни"));
  assert.ok(about.includes("Корисна содржина"));
  assert.ok(about.includes("Човечки пристап"));
  assert.match(styles, /\.public-mission-section \{[^}]*grid-template-columns:[^}]*1\.35fr[^}]*\.9fr/);
  assert.doesNotMatch(styles, /\.public-mission-section \{[^}]*(?:min-height|height):/);
});

test("shared public CTAs use an explicit light-surface contrast contract", () => {
  const component = read("src/components/public/PublicPage.tsx");
  const styles = read("src/app/globals.css");
  const about = read("src/app/za-nas/page.tsx");
  const contact = read("src/app/kontakt/page.tsx");

  assert.ok(component.includes('data-surface={surface}'));
  assert.ok(about.includes('<PublicPageCTA surface="light"'));
  assert.ok(contact.includes('<PublicPageCTA surface="light"'));
  assert.match(styles, /\.public-page-cta\[data-surface="light"\] \{[^}]*--cta-heading: #171313;[^}]*--cta-surface: #fff7ef;/s);
  assert.match(styles, /\.public-page-cta\[data-surface="light"\] > p \{ color: var\(--cta-body\)/);
  assert.match(styles, /\.public-page-cta\[data-surface="light"\] \.public-text-link \{[^}]*color: var\(--cta-heading\)/);
  assert.doesNotMatch(styles, /\.public-page-cta\s*\{[^}]*background: var\(--foreground\)/s);
  assert.ok(about.includes('href: "/tim"'));
  assert.ok(about.includes('href: "/kontakt"'));
  assert.ok(contact.includes('href: "/cenovnik-za-reklamiranje"'));
  assert.ok(contact.includes('href: "/reklami/zakazi"'));
});
