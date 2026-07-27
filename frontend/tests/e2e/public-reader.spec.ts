import { expect, test, type Page } from "@playwright/test";

const publicJourneys = [
  {
    name: "homepage",
    path: "/",
    selectors: [".site-masthead", ".lead-grid-editorial", ".lead-story-primary"],
  },
  {
    name: "article page",
    path: "/bitola-se-budi-so-novi-kulturni-prikazni",
    selectors: [".site-masthead", ".article-detail", ".article-hero", ".article-body"],
  },
  {
    name: "category archive",
    path: "/category/vesti",
    selectors: [".site-masthead", ".page-heading", ".archive-list", ".article-card-list"],
  },
  {
    name: "author profile",
    path: "/profil/razbudi-se-redakcija",
    selectors: [".site-masthead", ".author-profile", ".archive-list", ".article-card-list"],
  },
  {
    name: "search results",
    path: "/search?query=%D0%91%D0%B8%D1%82%D0%BE%D0%BB%D0%B0",
    selectors: [".site-masthead", ".public-search-form", ".archive-list", ".article-card-list"],
  },
];

async function expectNoHorizontalOverflow(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

  expect(overflow).toBeLessThanOrEqual(1);
}

test.describe("public reader journeys", () => {
  for (const journey of publicJourneys) {
    test(`${journey.name} renders without layout errors`, async ({ page }) => {
      const consoleErrors: string[] = [];

      page.on("console", (message) => {
        const expectedSandboxEnforcement =
          message.location().url === "about:srcdoc"
          && message.text().includes("document's frame is sandboxed")
          && message.text().includes("allow-scripts");

        if (message.type() === "error" && !expectedSandboxEnforcement) {
          consoleErrors.push(message.text());
        }
      });

      await page.goto(journey.path);

      for (const selector of journey.selectors) {
        await expect(page.locator(selector).first()).toBeVisible();
      }

      await expectNoHorizontalOverflow(page);
      expect(consoleErrors).toEqual([]);
    });
  }

  test("homepage lead keeps the primary story first on mobile", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto("/");
    await expect(page.locator(".lead-grid-editorial")).toBeVisible();

    await expect
      .poll(
        () =>
          page.locator(".lead-grid-editorial > *").evaluateAll((elements) =>
            elements.map((element) => Math.round(element.getBoundingClientRect().width)),
          ),
        {
          message: "lead blocks should settle into visible mobile widths",
        },
      )
      .toEqual([350, 350, 350]);

    const leadBlocks = await page.locator(".lead-grid-editorial > *").evaluateAll((elements) =>
      elements.map((element) => {
        const rect = element.getBoundingClientRect();

        return {
          className: element.className,
          width: Math.round(rect.width),
          x: Math.round(rect.x),
          y: Math.round(rect.y),
        };
      }),
    );

    const visualOrder = [...leadBlocks].sort((a, b) => a.y - b.y || a.x - b.x).map((block) => block.className);

    expect(leadBlocks[0]?.className).toContain("lead-story-primary");
    expect(visualOrder[0]).toContain("lead-story-primary");
    expect(leadBlocks.every((block) => block.width > 0)).toBe(true);
    await expectNoHorizontalOverflow(page);
  });
});

test.describe("launch SEO and ad safeguards", () => {
  test("missing article and unknown nested URLs return HTTP 404 with noindex", async ({ page }) => {
    for (const path of [
      "/definitely-missing-launch-article",
      "/definitely/missing/launch-route",
    ]) {
      const response = await page.goto(path);

      expect(response?.status(), `${path} should be a real HTTP 404`).toBe(404);
      await expect(page.getByRole("heading", { name: "Оваа страница не е пронајдена." })).toBeVisible();
      await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", /noindex/i);
    }
  });

  test("legacy sitemap index permanently redirects to the current sitemap", async ({ request }) => {
    const response = await request.get("/sitemap_index.xml", { maxRedirects: 0 });

    expect(response.status()).toBe(301);
    expect(response.headers().location).toBe("/sitemap.xml");
  });

  test("legacy WordPress posts page permanently redirects to the replacement homepage", async ({ request }) => {
    const response = await request.get("/blog/", { maxRedirects: 0 });

    expect(response.status()).toBe(301);
    expect(response.headers().location).toBe("/");
  });

  test("sitemap is populated from the active CMS", async ({ request }) => {
    const response = await request.get("/sitemap.xml");
    const xml = await response.text();

    expect(response.status()).toBe(200);
    expect(xml).toContain("/bitola-se-budi-so-novi-kulturni-prikazni");
    expect(xml).toContain("/category/vesti");
    expect(xml).toContain("/profil/razbudi-se-redakcija");
  });

  test("homepage, team directory, and paginated author pages expose correct canonical URLs", async ({ page }) => {
    await page.goto("/");
    const homepageCanonical = await page.locator('link[rel="canonical"]').getAttribute("href");
    const homepageOpenGraphUrl = await page.locator('meta[property="og:url"]').getAttribute("content");

    expect(new URL(homepageCanonical ?? "").pathname).toBe("/");
    expect(new URL(homepageOpenGraphUrl ?? "").pathname).toBe("/");

    await page.goto("/tim?page=2");
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", /\/tim$/);
    await expect(page.locator('meta[property="og:url"]')).toHaveAttribute("content", /\/tim$/);

    await page.goto("/profil/razbudi-se-redakcija?page=2");
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute(
      "href",
      /\/profil\/razbudi-se-redakcija\?page=2$/,
    );
    await expect(page.locator('meta[property="og:url"]')).toHaveAttribute(
      "content",
      /\/profil\/razbudi-se-redakcija\?page=2$/,
    );
  });

  test("private advertising account pages are noindex", async ({ request }) => {
    for (const path of ["/reklami/najava", "/reklami/dashboard"]) {
      const response = await request.get(path);
      const html = await response.text();

      expect(response.ok(), `${path} should render`).toBe(true);
      expect(html).toMatch(/<meta[^>]+name="robots"[^>]+content="[^"]*noindex/i);
    }
  });

  test("advertiser HTML stays inside an opaque sandboxed iframe", async ({ page }) => {
    await page.goto("/");

    const creativeFrame = page.locator('iframe.ad-creative-sandbox[title^="Реклама:"]').first();

    await expect(creativeFrame).toBeVisible();
    await expect(creativeFrame).toHaveAttribute("sandbox", "");
    await expect(creativeFrame).toHaveAttribute("srcdoc", /ad-house-creative/);
    await expect(page.locator("body > .ad-house-creative, .ad-creative-body > .ad-house-creative")).toHaveCount(0);
  });

  test("HTML creative body clicks use the tracked redirect", async ({ page }) => {
    await page.goto("/");

    const creativeClick = page.getByRole("link", { name: /^Отвори реклама:/ }).first();
    const trackingRequestPromise = page.context().waitForEvent("request", {
      predicate: (request) => request.method() === "GET" && request.url().includes("/api/ads/click/"),
    });
    const popupPromise = page.waitForEvent("popup");

    await creativeClick.click();

    const [trackingRequest, popup] = await Promise.all([trackingRequestPromise, popupPromise]);
    const trackingResponse = await trackingRequest.response();

    expect(trackingResponse?.status()).toBe(302);
    await popup.close();
  });

  test("desktop does not mount or record the mobile sticky ad", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== "desktop", "desktop-only device targeting regression");

    const mobileStickyImpressions: unknown[] = [];

    page.on("request", (request) => {
      if (request.method() !== "POST" || !request.url().includes("/ads/impression")) {
        return;
      }

      const body = request.postDataJSON() as { zone_key?: string } | null;

      if (body?.zone_key === "mobile_sticky_bottom") {
        mobileStickyImpressions.push(body);
      }
    });

    await page.goto("/");
    await page.waitForTimeout(500);

    await expect(page.locator(".mobile-sticky-ad")).toHaveCount(0);
    expect(mobileStickyImpressions).toEqual([]);
  });

  test("mobile mounts and tracks the sticky ad with the selected mobile device", async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== "mobile", "mobile-only device targeting regression");

    const recordedImpressionPromise = page.waitForResponse((response) => {
      const request = response.request();
      const body = request.postDataJSON() as { zone_key?: string } | null;

      return request.method() === "POST"
        && request.url().includes("/ads/impression")
        && body?.zone_key === "mobile_sticky_bottom";
    });

    await page.goto("/");
    await expect(page.locator(".mobile-sticky-ad")).toBeVisible();

    const recordedImpression = await recordedImpressionPromise;
    const impressionBody = recordedImpression.request().postDataJSON() as { device?: string; zone_key?: string };

    const responseStatus = recordedImpression.status();
    const responseBody = await recordedImpression.json() as {
      data: { duplicate: boolean; recorded: boolean };
    };

    expect([200, 201]).toContain(responseStatus);
    expect(responseBody.data).toEqual(
      responseStatus === 201
        ? { duplicate: false, recorded: true }
        : { duplicate: true, recorded: false },
    );
    expect(impressionBody.device).toBe("mobile");
  });
});
