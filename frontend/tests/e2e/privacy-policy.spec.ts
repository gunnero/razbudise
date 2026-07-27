import { expect, test } from "@playwright/test";

test.beforeEach(async ({ page }) => {
  await page.goto("/");
  await page.evaluate(() => localStorage.removeItem("razbudise-privacy-consent"));
});

test("privacy page and consent preferences default to necessary-only", async ({ page }) => {
  await page.goto("/politika-za-privatnost");

  await expect(page).toHaveTitle("Политика за приватност | Разбуди се");
  await expect(page.getByRole("heading", { level: 1, name: "Политика за приватност" })).toBeVisible();
  await expect(page.getByText("Здружение за културно информирање РАЗБУДИ СЕ ДЕНЕС Битола", { exact: true })).toBeVisible();
  await expect(page.getByRole("dialog", { name: "Поставки за приватност" })).toBeVisible();
  await expect(page.locator('script[src*="googletagmanager.com"]')).toHaveCount(0);

  await page.getByRole("button", { name: "Само неопходни" }).click();
  await expect(page.getByRole("dialog", { name: "Поставки за приватност" })).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => localStorage.getItem("razbudise-privacy-consent"))).toBe('{"analytics":false,"externalMedia":false,"version":1}');

  await page.getByRole("button", { name: "Поставки за колачиња" }).click();
  await page.getByRole("checkbox", { name: "Аналитика" }).check();
  await page.getByRole("button", { name: "Зачувај избор" }).click();
  await expect.poll(() => page.evaluate(() => localStorage.getItem("razbudise-privacy-consent"))).toContain('"analytics":true');

  await page.getByRole("button", { name: "Поставки за колачиња" }).click();
  await page.getByRole("button", { name: "Само неопходни" }).click();
  await expect.poll(() => page.evaluate(() => localStorage.getItem("razbudise-privacy-consent"))).toContain('"analytics":false');
});

test("privacy policy has no horizontal overflow", async ({ page }) => {
  await page.goto("/politika-za-privatnost");
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
