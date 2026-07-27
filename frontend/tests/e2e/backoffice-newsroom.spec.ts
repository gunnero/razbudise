import {
  expect,
  test,
  type Locator,
  type Page,
  type TestInfo,
} from "@playwright/test";

const backofficeBaseUrl = (
  process.env.PLAYWRIGHT_BACKOFFICE_BASE_URL ?? "http://127.0.0.1:8001"
).replace(/\/$/, "");

const password = "Newsroom-Testing-Only-2026!";

const accounts = {
  editorInChief: "newsroom.eic@example.test",
  editor: "newsroom.editor@example.test",
  author: "newsroom.author@example.test",
} as const;

const longTableTitle =
  "Долг редакциски наслов што проверува читливо прикажување во два реда без кршење на зборовите";

const responsiveViewports = [
  { name: "mobile", width: 390, height: 844 },
  { name: "tablet", width: 768, height: 900 },
  { name: "compact-desktop", width: 1024, height: 900 },
  { name: "desktop", width: 1440, height: 900 },
] as const;

async function login(page: Page, email: string): Promise<void> {
  await page.goto(`${backofficeBaseUrl}/backoffice/login`);
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(password);
  await page.locator('button[type="submit"]').click();

  await expect(page).toHaveURL(/\/backoffice(?:\?.*)?$/);
  await expect(page.getByTestId("newsroom-dashboard")).toBeVisible();
}

async function setNewsroomViewport(page: Page, testInfo: TestInfo): Promise<void> {
  await page.setViewportSize(
    testInfo.project.name === "mobile"
      ? { width: 390, height: 844 }
      : { width: 1440, height: 900 },
  );
}

async function openNewsroomPage(
  page: Page,
  path: string,
  testId: string,
  heading: string,
): Promise<void> {
  const response = await page.goto(`${backofficeBaseUrl}${path}`);

  expect(response?.ok()).toBe(true);
  await expect(page.getByTestId(testId)).toBeVisible();
  await expect(page.getByRole("heading", { name: heading, exact: true })).toBeVisible();
  await expectNoHorizontalOverflow(page);
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const dimensions = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));

  expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth + 1);
}

async function expectSurfaceInsideViewport(
  page: Page,
  surface: Locator,
): Promise<void> {
  await expect(surface).toBeVisible();

  const dimensions = await surface.evaluate((element) => {
    const bounds = element.getBoundingClientRect();

    return {
      left: bounds.left,
      right: bounds.right,
      width: bounds.width,
      viewportWidth: window.innerWidth,
    };
  });

  expect(dimensions.left).toBeGreaterThanOrEqual(-1);
  expect(dimensions.right).toBeLessThanOrEqual(dimensions.viewportWidth + 1);
  expect(dimensions.width).toBeLessThanOrEqual(dimensions.viewportWidth + 1);
}

async function expectTouchTarget(control: Locator): Promise<void> {
  await expect(control).toBeVisible();

  const box = await control.boundingBox();
  expect(box).not.toBeNull();
  expect(box?.width ?? 0).toBeGreaterThanOrEqual(44);
  expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
}

async function expectReviewContentClearsMobileDock(
  page: Page,
  reviewSidebar: Locator,
  workflowDock: Locator,
): Promise<void> {
  await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));

  const lastContentSection = reviewSidebar
    .locator(":scope > div > .fi-section:not(.newsroom-review-workflow-desktop)")
    .last();
  const dockBar = workflowDock.locator(".newsroom-mobile-workflow__bar");
  const [contentBox, dockBox] = await Promise.all([
    lastContentSection.boundingBox(),
    dockBar.boundingBox(),
  ]);

  expect(contentBox).not.toBeNull();
  expect(dockBox).not.toBeNull();
  const contentBottom = (contentBox?.y ?? Number.POSITIVE_INFINITY)
    + (contentBox?.height ?? 0);
  expect(contentBottom).toBeLessThanOrEqual((dockBox?.y ?? Number.NEGATIVE_INFINITY) + 1);
}

async function expectMobileTouchTarget(page: Page, locatorName: RegExp): Promise<void> {
  const control = page.getByRole("link", { name: locatorName }).first();
  await expectTouchTarget(control);
}

async function attachLayoutScreenshot(page: Page, testInfo: TestInfo): Promise<void> {
  const viewport = page.viewportSize();
  const expectedWidth = testInfo.project.name === "mobile" ? 390 : 1440;

  expect(viewport?.width).toBe(expectedWidth);

  const screenshotPath = `${testInfo.project.outputDir}/newsroom-dashboard-${testInfo.project.name}.png`;
  const screenshot = await page.screenshot({
    fullPage: true,
    path: screenshotPath,
  });
  expect(screenshot.byteLength).toBeGreaterThan(5_000);
  await testInfo.attach(`newsroom-${testInfo.project.name}`, {
    path: screenshotPath,
    contentType: "image/png",
  });
}

test.describe("authenticated newsroom workspace", () => {
  test.describe.configure({ mode: "serial" });

  test("Editor-in-Chief sees the global dashboard, approval queue, and managed posts", async ({
    page,
  }, testInfo) => {
    await setNewsroomViewport(page, testInfo);
    await login(page, accounts.editorInChief);

    await expect(page.getByRole("heading", { name: "Редакциски преглед", exact: true })).toBeVisible();
    await expect(page.getByText("Редакција", { exact: true }).first()).toBeVisible();
    await expect(
      page.locator('a[href$="/backoffice/newsroom/approval"]').first(),
    ).toBeVisible();
    await expect(
      page.locator('a[href$="/backoffice/newsroom/posts"]').first(),
    ).toBeVisible();
    await expect(
      page.locator('a[href$="/backoffice/newsroom/authors"]').first(),
    ).toBeVisible();
    await expect(
      page.locator('a[href$="/backoffice/newsroom/activity"]').first(),
    ).toBeVisible();
    await expect(
      page.locator('a[href$="/backoffice/newsroom/calendar"]'),
    ).toHaveCount(0);
    await expectNoHorizontalOverflow(page);
    await attachLayoutScreenshot(page, testInfo);

    await openNewsroomPage(
      page,
      "/backoffice/newsroom/approval",
      "newsroom-approval-queue",
      "Чекаат одобрување",
    );
    await expect(page.getByText("Newsroom Test Waiting Approval", { exact: true })).toBeVisible();
    await expect(page.getByText("Romina Markovska", { exact: true }).first()).toBeVisible();

    if (testInfo.project.name === "mobile") {
      await expectMobileTouchTarget(page, /Отвори/);
    }

    await openNewsroomPage(
      page,
      "/backoffice/newsroom/posts",
      "newsroom-managed-posts",
      "Објави што ги управувам",
    );
    await expect(
      page.getByRole("heading", { name: "Romina Markovska", exact: true }).first(),
    ).toBeVisible();
    await expect(page.getByText("Newsroom Test Changes Requested", { exact: true })).toBeVisible();
  });

  test("Editor-in-Chief sidebar presets apply their canonical article filters", async ({
    page,
  }, testInfo) => {
    test.skip(testInfo.project.name !== "desktop", "Desktop covers the persistent newsroom sidebar.");

    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, accounts.editorInChief);

    const sidebar = page.locator(".fi-sidebar");
    await expect(sidebar).toBeVisible();

    const presets = [
      {
        label: "На преглед",
        filter: "editorial_queue",
        value: "in_review",
        title: "Newsroom Test In Review",
      },
      {
        label: "Потребна доработка",
        filter: "editorial_queue",
        value: "changes_requested",
        title: "Newsroom Test Changes Requested",
      },
      {
        label: "Одобрени",
        filter: "editorial_queue",
        value: "approved",
        title: "Newsroom Test Approved Unscheduled",
      },
      {
        label: "Закажани",
        filter: "editorial_queue",
        value: "scheduled",
        title: "Newsroom Test Scheduled Today",
      },
      {
        label: "Објавени денес",
        filter: "editorial_queue",
        value: "published_today",
        title: "Newsroom Test Published Today",
      },
      {
        label: "Архива",
        filter: "status",
        value: "archived",
        title: "Newsroom Test Archived",
      },
    ] as const;

    for (const preset of presets) {
      await test.step(preset.label, async () => {
        const link = sidebar.getByRole("link", {
          name: preset.label,
          exact: true,
        });
        await expect(link).toHaveCount(1);
        await link.click();

        await expect
          .poll(() =>
            new URL(page.url()).searchParams.get(
              `filters[${preset.filter}][value]`,
            ),
          )
          .toBe(preset.value);
        await expect(page.locator('[data-newsroom-table="articles"]')).toBeVisible();
        await expect(page.getByText(preset.title, { exact: true })).toBeVisible();
        await expect(page.getByText("Newsroom Test Draft", { exact: true })).toHaveCount(0);
      });
    }

    await test.step("Сите објави clears the persisted preset", async () => {
      const allArticles = sidebar.getByRole("link", {
        name: "Сите објави",
        exact: true,
      });
      await expect(allArticles).toHaveCount(1);
      await allArticles.click();

      await expect
        .poll(() =>
          new URL(page.url()).searchParams.get(
            "filters[editorial_queue][value]",
          ),
        )
        .toBeNull();
      await expect(page.getByText(longTableTitle, { exact: true })).toBeVisible();
      await expect(page.getByText("Newsroom Test Archived", { exact: true })).toBeVisible();
    });
  });

  test("Editor sees only the assigned newsroom workload", async ({ page }, testInfo) => {
    await setNewsroomViewport(page, testInfo);
    await login(page, accounts.editor);

    await expect(page.getByText("Редакција", { exact: true }).first()).toBeVisible();
    await expect(
      page.locator('a[href$="/backoffice/newsroom/approval"]').first(),
    ).toBeVisible();

    await openNewsroomPage(
      page,
      "/backoffice/newsroom/approval",
      "newsroom-approval-queue",
      "Чекаат одобрување",
    );
    await expect(
      page.getByText("Newsroom Test Editor Managed Submission", { exact: true }),
    ).toBeVisible();
    await expect(page.getByText("Newsroom Test Waiting Approval", { exact: true })).toHaveCount(0);

    await openNewsroomPage(
      page,
      "/backoffice/newsroom/posts",
      "newsroom-managed-posts",
      "Објави што ги управувам",
    );
    await expect(
      page.getByRole("heading", { name: "Editor Managed Author", exact: true }).first(),
    ).toBeVisible();
    await expect(page.getByText("Romina Markovska", { exact: true })).toHaveCount(0);

    await openNewsroomPage(
      page,
      "/backoffice/newsroom/authors",
      "newsroom-managed-authors",
      "Автори",
    );
    await expect(
      page.getByRole("heading", { name: "Editor Managed Author", exact: true }).first(),
    ).toBeVisible();
    await expect(page.getByText("Romina Markovska", { exact: true })).toHaveCount(0);
  });

  test("Author sees a personal workspace and cannot open editorial queues", async ({
    page,
  }, testInfo) => {
    await setNewsroomViewport(page, testInfo);
    await login(page, accounts.author);

    await expect(page.getByText("Мои објави", { exact: true }).first()).toBeVisible();
    await expect(page.locator('a[href$="/backoffice/newsroom/posts"]').first()).toBeVisible();
    await expect(page.locator('a[href$="/backoffice/newsroom/approval"]')).toHaveCount(0);
    await expect(page.locator('a[href$="/backoffice/newsroom/authors"]')).toHaveCount(0);
    await expect(page.locator('a[href$="/backoffice/newsroom/activity"]')).toHaveCount(0);
    await expectNoHorizontalOverflow(page);

    await openNewsroomPage(
      page,
      "/backoffice/newsroom/posts",
      "newsroom-managed-posts",
      "Мои објави",
    );
    await expect(page.getByText("Newsroom Test Draft", { exact: true })).toBeVisible();
    await expect(page.getByText("Newsroom Test Editor Managed Submission", { exact: true })).toHaveCount(0);

    if (testInfo.project.name === "mobile") {
      const draftCard = page
        .locator("article")
        .filter({ hasText: "Newsroom Test Draft" })
        .first();
      await draftCard.getByRole("link", { name: "Отвори", exact: true }).click();

      await expect(page.getByTestId("newsroom-review-sidebar")).toBeVisible();
      await expectNoHorizontalOverflow(page);

      const workflowDock = page.getByTestId("newsroom-mobile-workflow");
      const workflowToggle = workflowDock.getByRole("button", {
        name: "Работен тек",
        exact: true,
      });
      await expectTouchTarget(workflowDock.getByRole("button", { name: "Зачувај", exact: true }));
      await expectTouchTarget(workflowToggle);
      await workflowToggle.click();

      const workflowDialog = page.getByRole("dialog", { name: "Работен тек" });
      await expect(workflowDialog).toBeVisible();
      await expect(
        workflowDialog.getByRole("button", { name: "Испрати на преглед", exact: true }),
      ).toBeVisible();
      await expect(workflowDialog.getByRole("button", { name: "Одобри", exact: true })).toHaveCount(0);
      await expect(
        workflowDialog.getByRole("button", { name: "Побарај измени", exact: true }),
      ).toHaveCount(0);
    }

    const forbiddenResponse = await page.goto(
      `${backofficeBaseUrl}/backoffice/newsroom/approval`,
    );
    expect(forbiddenResponse?.status()).toBe(403);
  });

  test("Posts table and article review stay within every target viewport", async ({
    page,
  }, testInfo) => {
    test.skip(testInfo.project.name !== "desktop", "The desktop project runs the complete viewport matrix.");
    test.setTimeout(90_000);

    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, accounts.editorInChief);
    await openNewsroomPage(
      page,
      "/backoffice/newsroom/approval",
      "newsroom-approval-queue",
      "Чекаат одобрување",
    );

    const approvalCard = page
      .locator('[data-testid^="approval-card-"]')
      .filter({ hasText: "Newsroom Test Waiting Approval" })
      .first();
    const reviewHref = await approvalCard
      .getByRole("link", { name: "Отвори преглед", exact: true })
      .getAttribute("href");
    expect(reviewHref).toBeTruthy();

    for (const viewport of responsiveViewports) {
      await test.step(`${viewport.width}px Posts table`, async () => {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        const response = await page.goto(`${backofficeBaseUrl}/backoffice/articles`);
        expect(response?.ok()).toBe(true);

        const postsTable = page.locator('[data-newsroom-table="articles"]');
        await expectSurfaceInsideViewport(page, postsTable);
        await expectNoHorizontalOverflow(page);

        const titleText = postsTable
          .locator(".fi-ta-cell-title", { hasText: longTableTitle })
          .first()
          .locator(".fi-ta-text-item")
          .first();
        await expect(titleText).toBeVisible();

        const titleMetrics = await titleText.evaluate((element) => {
          const style = window.getComputedStyle(element);
          const lineHeight = Number.parseFloat(style.lineHeight);

          return {
            height: element.getBoundingClientRect().height,
            lineHeight,
            overflowWrap: style.overflowWrap,
            text: element.textContent?.trim(),
          };
        });
        expect(titleMetrics.text).toBe(longTableTitle);
        expect(titleMetrics.overflowWrap).not.toBe("anywhere");
        expect(titleMetrics.height).toBeLessThanOrEqual(
          titleMetrics.lineHeight * 2 + 2,
        );

        if (viewport.width === 390) {
          const tableContent = postsTable.locator(".fi-ta-content-ctn");
          const contentWidth = await tableContent.evaluate((element) => {
            const style = window.getComputedStyle(element);

            return {
              clientWidth: element.clientWidth,
              scrollWidth: element.scrollWidth,
              overflowY: style.overflowY,
            };
          });
          expect(contentWidth.scrollWidth).toBeLessThanOrEqual(contentWidth.clientWidth + 1);
          expect(contentWidth.overflowY).not.toBe("auto");
          await expect(postsTable.locator(".newsroom-article-row").first()).toBeVisible();
        }

        if (viewport.width >= 1024) {
          await expect(postsTable.locator(".fi-ta-header-cell-title")).toContainText("Објава");
          await expect(postsTable.locator(".fi-ta-header-cell-status")).toBeVisible();
          await expect(postsTable.locator(".fi-ta-header-cell-unresolved-comments-count")).toContainText(
            "Коментари",
          );
          await expect(postsTable.locator(".fi-ta-header-cell-author-name")).toHaveCount(0);
          await expect(postsTable.locator(".fi-ta-header-cell-category-name")).toHaveCount(0);

          const tableContent = postsTable.locator(".fi-ta-content-ctn");
          const scrollMetrics = await tableContent.evaluate((element) => {
            const style = window.getComputedStyle(element);

            return {
              clientHeight: element.clientHeight,
              maxHeight: style.maxHeight,
              overflowY: style.overflowY,
              scrollHeight: element.scrollHeight,
            };
          });
          expect(scrollMetrics.overflowY).toBe("auto");
          expect(scrollMetrics.maxHeight).not.toBe("none");
          expect(scrollMetrics.scrollHeight).toBeGreaterThan(scrollMetrics.clientHeight);
          expect(await tableContent.evaluate((element) => element.scrollWidth)).toBeLessThanOrEqual(
            (await tableContent.evaluate((element) => element.clientWidth)) + 1,
          );

          await expect(tableContent).toHaveAttribute("role", "region");
          await expect(tableContent).toHaveAttribute(
            "aria-label",
            "Објави, скролувачка табела",
          );
          await tableContent.focus();
          await expect(tableContent).toBeFocused();
          await page.keyboard.press("PageDown");
          await expect
            .poll(() => tableContent.evaluate((element) => element.scrollTop))
            .toBeGreaterThan(0);
          expect(
            await postsTable.locator(".fi-ta-header-cell-title").evaluate(
              (element) => window.getComputedStyle(element).position,
            ),
          ).toBe("sticky");
          expect(await tableContent.locator(".fi-pagination").count()).toBe(0);
          await expect(postsTable.getByRole("button", { name: "Акции" }).first()).toBeVisible();

          const editorHeader = postsTable.locator(".fi-ta-header-cell-editorial-editor");
          const updatedHeader = postsTable.locator(".fi-ta-header-cell-updated-at");
          if (viewport.width >= 1280) {
            await expect(editorHeader).toBeVisible();
            await expect(updatedHeader).toBeVisible();
          } else {
            await expect(editorHeader).toBeHidden();
            await expect(updatedHeader).toBeHidden();
          }
        }
      });

      await test.step(`${viewport.width}px article review`, async () => {
        const response = await page.goto(new URL(reviewHref!, backofficeBaseUrl).toString());
        expect(response?.ok()).toBe(true);

        const reviewSidebar = page.getByTestId("newsroom-review-sidebar");
        await expectSurfaceInsideViewport(page, reviewSidebar);
        await expectNoHorizontalOverflow(page);

        const workflowDock = page.getByTestId("newsroom-mobile-workflow");
        if (viewport.width === 390) {
          await expect(workflowDock).toBeVisible();
          const save = workflowDock.getByRole("button", { name: "Зачувај", exact: true });
          const toggle = workflowDock.getByRole("button", {
            name: "Работен тек",
            exact: true,
          });
          await expectTouchTarget(save);
          await expectTouchTarget(toggle);
          await toggle.click();

          const workflowDialog = page.getByRole("dialog", { name: "Работен тек" });
          await expectSurfaceInsideViewport(page, workflowDialog);
          await expect(
            workflowDialog.getByRole("button", { name: "Започни преглед", exact: true }),
          ).toBeVisible();
          await workflowDialog
            .getByRole("button", { name: "Затвори ги дејствата", exact: true })
            .click();
          await expect(workflowDialog).toBeHidden();
          await expectReviewContentClearsMobileDock(page, reviewSidebar, workflowDock);
        } else {
          await expect(workflowDock).toBeHidden();
          await expect(page.locator(".newsroom-review-workflow-desktop")).toBeVisible();
        }
      });
    }
  });
});
