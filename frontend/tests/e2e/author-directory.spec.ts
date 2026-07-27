import { expect, test } from "@playwright/test";

const viewports = [
  { width: 390, height: 900, columns: 1 },
  { width: 768, height: 1024, columns: 2 },
  { width: 1024, height: 1000, columns: 2 },
  { width: 1440, height: 1000, columns: 3 },
];

test("team directory at /tim is responsive and shows public crew cards", async ({ page }) => {
  test.setTimeout(90_000);

  for (const viewport of viewports) {
    await page.setViewportSize(viewport);
    await page.goto("/tim");

    await expect(page.locator("h1")).toHaveCount(1);
    await expect(page.locator("h1")).toContainText("Луѓето зад Разбуди се");
    await expect(page.locator(".team-section-stack, .empty-state").first()).toBeVisible();

    const grid = page.locator(".team-member-grid").first();
    if (await grid.count()) {
      const columns = await grid.evaluate((element) => getComputedStyle(element).gridTemplateColumns.trim().split(/\s+/).length);
      expect(columns).toBeLessThanOrEqual(viewport.columns);
      expect(columns).toBeGreaterThanOrEqual(1);
    }

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
  }
});
