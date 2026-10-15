import { test, expect, type Page } from "@playwright/test";
import { drawerScenarios, mockDrawerPages } from "../fixtures/drawer.fixture";

async function assertViewportBounds(page: Page, selector: string) {
	const bounds = await page.locator(selector).boundingBox();
	expect(bounds).not.toBeNull();
	if (!bounds) return;
	expect(bounds.x).toBeGreaterThanOrEqual(-1);
	expect(bounds.x + bounds.width).toBeLessThanOrEqual(
		(page.viewportSize()?.width ?? 0) + 1
	);
}

for (const { width, direction } of [
	{ width: 1440, direction: "ltr" },
	{ width: 390, direction: "ltr" },
	{ width: 320, direction: "rtl" },
] as const) {
	test(`all drawers keep their header and actions reachable at ${width}px ${direction}`, async ({
		page,
	}) => {
		await page.setViewportSize({ width, height: 844 });
		await mockDrawerPages(page, direction);
		if (direction === "rtl")
			await page.addInitScript(() =>
				localStorage.setItem("theme", "dark")
			);
		for (const drawer of drawerScenarios) {
			await test.step(drawer.name, async () => {
				await page.goto(drawer.path);

				await page.locator(drawer.trigger).first().click();
				const panel = page.locator(drawer.panel);
				await expect(panel).toBeVisible();
				await expect(panel).not.toHaveAttribute("data-enter");
				await expect
					.poll(async () => {
						const bounds = await panel.boundingBox();
						return (
							!!bounds &&
							bounds.x >= -1 &&
							bounds.x + bounds.width <= width + 1
						);
					})
					.toBe(true);
				await assertViewportBounds(page, drawer.panel);
				const header = panel.locator(".drawer-header");
				const footer = panel.locator(".drawer-footer");
				await expect(header).toBeInViewport();
				await expect(footer).toBeInViewport();
				await expect(panel.locator(".drawer-title")).toHaveCSS(
					"font-size",
					"18px"
				);
				await expect(page.locator(".dialog-backdrop")).toHaveCSS(
					"backdrop-filter",
					"none"
				);
				const headerTop = (await header.boundingBox())?.y;
				await panel.evaluate((element) => {
					for (const child of element.querySelectorAll<HTMLElement>(
						"*"
					)) {
						if (getComputedStyle(child).overflowY === "auto")
							child.scrollTop = child.scrollHeight;
					}
				});
				await expect(header).toBeInViewport();
				expect((await header.boundingBox())?.y).toBe(headerTop);
				await expect(footer).toBeInViewport();
				await assertViewportBounds(
					page,
					`${drawer.panel} .drawer-footer`
				);
				if (process.env.PEAKURL_UI_PREVIEW)
					await page.screenshot({
						path: `/tmp/peakurl-drawer-${drawer.name}-${width}-${direction}.png`,
					});
				if (drawer.name === "analytics")
					await page.keyboard.press("Escape");
				else await panel.locator(drawer.close).last().click();
				await expect(panel).toHaveCount(0);
			});
		}
	});
}
