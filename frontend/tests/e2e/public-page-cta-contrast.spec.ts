import { expect, test } from "@playwright/test";

const routes = [
  { path: "/za-nas", primary: "/tim", secondary: "/kontakt" },
  { path: "/kontakt", primary: "/cenovnik-za-reklamiranje", secondary: "/reklami/zakazi" },
];
const themes = ["light", "dark"] as const;
const viewports = [
  { width: 390, height: 844 },
  { width: 768, height: 1024 },
  { width: 1024, height: 1000 },
  { width: 1440, height: 1000 },
];

function channel(value: number) {
  const normalized = value / 255;
  return normalized <= 0.03928 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
}

function luminance(rgb: string) {
  const values = rgb.match(/[\d.]+/g)?.slice(0, 3).map(Number) ?? [];
  return 0.2126 * channel(values[0]) + 0.7152 * channel(values[1]) + 0.0722 * channel(values[2]);
}

function contrast(foreground: string, background: string) {
  const lighter = Math.max(luminance(foreground), luminance(background));
  const darker = Math.min(luminance(foreground), luminance(background));
  return (lighter + 0.05) / (darker + 0.05);
}

test("public page CTA keeps its explicit light-surface contrast in both themes", async ({ page }) => {
  test.setTimeout(120_000);
  for (const theme of themes) {
    for (const route of routes) {
      for (const viewport of viewports) {
        await page.setViewportSize(viewport);
        await page.goto(route.path);
        await page.evaluate((selectedTheme) => {
          window.localStorage.setItem("razbudise-theme", selectedTheme);
          document.documentElement.dataset.theme = selectedTheme;
        }, theme);

        const cta = page.locator('.public-page-cta[data-surface="light"]');
        const primary = cta.locator(".public-primary-button");
        const secondary = cta.locator(".public-text-link");

        await expect(cta).toBeVisible();
        await expect(primary).toHaveAttribute("href", route.primary);
        await expect(secondary).toHaveAttribute("href", route.secondary);

        const colors = await cta.evaluate((element) => {
          const panel = getComputedStyle(element);
          const headingElement = element.querySelector<HTMLElement>("h2")!;
          const bodyElement = Array.from(element.children).find((child) => child.tagName === "P" && !child.classList.contains("kicker")) as HTMLElement;
          const primaryElement = element.querySelector<HTMLElement>(".public-primary-button")!;
          const secondaryElement = element.querySelector<HTMLElement>(".public-text-link")!;
          return {
            panel: panel.backgroundColor,
            heading: getComputedStyle(headingElement).color,
            body: getComputedStyle(bodyElement).color,
            primaryBackground: getComputedStyle(primaryElement).backgroundColor,
            primaryText: getComputedStyle(primaryElement).color,
            secondary: getComputedStyle(secondaryElement).color,
          };
        });

        expect(contrast(colors.heading, colors.panel)).toBeGreaterThanOrEqual(3);
        expect(contrast(colors.body, colors.panel)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(colors.primaryText, colors.primaryBackground)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(colors.secondary, colors.panel)).toBeGreaterThanOrEqual(4.5);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);

        await secondary.focus();
        await expect(secondary).toBeFocused();
        expect(await secondary.evaluate((element) => getComputedStyle(element).outlineStyle)).not.toBe("none");
      }
    }
  }
});
