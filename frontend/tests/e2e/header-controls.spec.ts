import { expect, test } from "@playwright/test";

const viewports = [
  { width: 390, height: 844 },
  { width: 768, height: 900 },
  { width: 1024, height: 900 },
  { width: 1440, height: 1000 },
];

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    if (!window.localStorage.getItem("razbudise-theme")) {
      window.localStorage.setItem("razbudise-theme", "light");
    }
  });
});

test("shared header icons preserve theme, layout, and search behavior", async ({ page }) => {
  const hydrationErrors: string[] = [];
  page.on("console", (message) => {
    if (message.type() === "error" && /hydration|did not match|server rendered html/i.test(message.text())) {
      hydrationErrors.push(message.text());
    }
  });

  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto("/");

  const themeToggle = page.getByRole("button", { name: "Префрли во темен режим" });
  const searchToggle = page.getByRole("button", { name: "Пребарување" });
  const headerBefore = await page.locator(".site-header").boundingBox();

  await expect(themeToggle.locator(".lucide-moon")).toHaveCount(1);
  await expect(themeToggle.locator(".lucide-sun")).toHaveCount(0);
  await expect(searchToggle.locator(".lucide-search")).toHaveCount(1);

  await themeToggle.focus();
  await page.keyboard.press("Enter");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  await expect(page.getByRole("button", { name: "Префрли во светол режим" }).locator(".lucide-sun")).toHaveCount(1);
  await expect.poll(() => page.evaluate(() => window.localStorage.getItem("razbudise-theme"))).toBe("dark");

  const headerAfter = await page.locator(".site-header").boundingBox();
  expect(Math.abs((headerAfter?.height ?? 0) - (headerBefore?.height ?? 0))).toBeLessThanOrEqual(1);

  await page.reload();
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  await page.goto("/tim");
  await expect(page.getByRole("button", { name: "Префрли во светол режим" })).toBeVisible();
  await page.goto("/cenovnik-za-reklamiranje");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");

  await searchToggle.click();
  const searchInput = page.getByRole("searchbox", { name: "Барај" });
  await expect(searchInput).toBeFocused();
  await searchInput.fill("Битола");
  await searchInput.press("Enter");
  await expect(page).toHaveURL(/\/search\?query=%D0%91%D0%B8%D1%82%D0%BE%D0%BB%D0%B0/);
  expect(hydrationErrors).toEqual([]);
});

test("header icon controls keep responsive tap targets without overflow", async ({ page }) => {
  for (const viewport of viewports) {
    await page.setViewportSize(viewport);
    await page.goto("/");

    const buttons = page.locator(".site-header-actions .header-icon-button");
    await expect(buttons).toHaveCount(2);

    for (const button of await buttons.all()) {
      const box = await button.boundingBox();
      const minimum = viewport.width <= 560 ? 44 : 36;
      expect(box?.width).toBeGreaterThanOrEqual(minimum);
      expect(box?.height).toBeGreaterThanOrEqual(minimum);
    }

    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
  }
});
