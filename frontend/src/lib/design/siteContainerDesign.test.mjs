import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const read = (path) => readFileSync(new URL(path, import.meta.url), "utf8");
const container = read("../../components/layout/SiteContainer.tsx");
const header = read("../../components/SiteHeader.tsx");
const footer = read("../../components/SiteFooter.tsx");
const publicPage = read("../../components/public/PublicPage.tsx");
const pricing = read("../../app/cenovnik-za-reklamiranje/page.tsx");
const legal = read("../../components/legal/LegalPage.tsx");
const css = read("../../app/globals.css");

test("SiteContainer exposes only the shared wide and reading variants", () => {
  assert.ok(container.includes('size?: "wide" | "reading"'));
  assert.ok(container.includes("site-container-${size}"));
  assert.ok(container.includes('as: Component = "div"'));
});

test("header, public pages, pricing, and footer use the shared container primitive", () => {
  assert.ok(header.includes('<SiteContainer className="site-header-inner">'));
  assert.ok(footer.includes('<SiteContainer className="site-footer-inner">'));
  assert.ok(publicPage.includes('<SiteContainer as="main"'));
  assert.ok(pricing.includes('<SiteContainer as="main" className="site-shell media-kit-page">'));
});

test("wide public alignment uses one width token without nested section containers", () => {
  assert.match(css, /--site-container-wide: 1360px;/);
  assert.match(css, /--site-container-reading: 820px;/);
  assert.match(css, /--site-gutter: 40px;/);
  assert.match(css, /@media \(max-width: 1100px\)[\s\S]*--site-gutter: 28px;/);
  assert.match(css, /@media \(max-width: 620px\)[\s\S]*--site-gutter: 20px;/);
  assert.equal((pricing.match(/<SiteContainer/g) ?? []).length, 1);
  assert.equal((publicPage.match(/<SiteContainer/g) ?? []).length, 1);
});

test("pricing sections inherit the shared wide edge and catalogue expansion cannot exceed it", () => {
  assert.match(css, /\.media-kit-hero\s*{[^}]*max-width: none;[^}]*width: 100%;/s);
  assert.match(css, /\.media-kit-section,[^}]*max-width: none;[^}]*width: 100%;/s);
  assert.match(css, /\.media-kit-position-catalog\s*{[^}]*max-width: none;[^}]*width: 100%;/s);
});

test("legal pages retain their reading-width layout inside the shared outer container", () => {
  assert.ok(legal.includes("legal-document-layout"));
  assert.match(css, /\.legal-document-layout-simple\s*{[^}]*minmax\(0, 820px\)/s);
  assert.match(css, /\.legal-page p\s*{[^}]*max-width: 72ch;/s);
});
