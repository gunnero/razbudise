import { expect, test } from "@playwright/test";

const viewports = [
  { width: 390, height: 844, columns: 1 },
  { width: 768, height: 1024, columns: 1 },
  { width: 1024, height: 1000, columns: 2 },
  { width: 1440, height: 1000, columns: 2 },
];

test("About mission stays balanced and content-driven at launch breakpoints", async ({ page }) => {
  for (const viewport of viewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto("/za-nas");

    const mission = page.locator(".public-mission-section");
    await expect(mission).toBeVisible();
    await expect(mission.locator(".public-mission-principles li")).toHaveCount(3);

    const layout = await mission.evaluate((element) => {
      const styles = getComputedStyle(element);
      const statement = element.querySelector<HTMLElement>(".public-mission-statement");
      const principles = element.querySelector<HTMLElement>(".public-mission-principles");

      return {
        columns: styles.gridTemplateColumns.trim().split(/\s+/).length,
        hasMinHeight: styles.minHeight !== "0px",
        principlesTop: principles?.getBoundingClientRect().top ?? 0,
        statementTop: statement?.getBoundingClientRect().top ?? 0,
      };
    });

    expect(layout.columns).toBe(viewport.columns);
    expect(layout.hasMinHeight).toBe(false);
    expect(layout.principlesTop).toBeGreaterThanOrEqual(layout.statementTop);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
  }
});
