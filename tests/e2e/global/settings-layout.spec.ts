import { test, expect } from "@playwright/test";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";

const capabilities = {
	view_links: true,
	manage_api_keys: true,
	manage_integrations: true,
	manage_performance: true,
	manage_mail_delivery: true,
	manage_location_data: true,
	manage_updates: true,
};

for (const direction of ["ltr", "rtl"] as const) {
	test(`Settings uses a compact navigation column and matching active markers in ${direction}`, async ({
		page,
	}) => {
		await mockDashboardSession(page, { capabilities, direction });
		await page.setViewportSize({ width: 1440, height: 1000 });
		await page.goto("/dashboard/settings/general");
		const settings = page.locator(".settings-sidebar-link-active");
		const main = page.locator(".dashboard-sidebar-link-active");
		await expect(settings).toHaveAttribute("aria-current", "page");
		await expect(main).toHaveAttribute("aria-current", "page");
		const sidebar = await page.locator(".settings-sidebar").boundingBox();
		const content = await page.locator(".settings-tab-page").boundingBox();
		if (!sidebar || !content) throw new Error("Settings layout is missing");
		expect(sidebar.width).toBeLessThanOrEqual(240);
		expect(content.width).toBeGreaterThan(sidebar.width * 2);
		await expect(settings).toHaveCSS("height", "36px");
		for (const color of ["rgb(43, 48, 64)", "rgb(181, 200, 250)"]) {
			for (const link of [settings, main]) {
				const marker = await link.evaluate((element) => {
					const style = getComputedStyle(element, "::before");
					return {
						color: style.backgroundColor,
						edge: style.insetInlineStart,
						width: style.width,
					};
				});
				expect(marker).toEqual({ color, edge: "0px", width: "3px" });
			}
			if (color === "rgb(43, 48, 64)")
				await page
					.getByRole("button", { name: "Toggle theme" })
					.click();
		}
	});
}

test("phone settings navigation scrolls horizontally without widening the page", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto("/dashboard/settings/general");
	const navigation = page.locator(".settings-sidebar-nav");
	await expect(navigation).toHaveCSS("flex-direction", "row");
	await page
		.locator(".settings-sidebar-link")
		.last()
		.scrollIntoViewIfNeeded();
	expect(
		await page.evaluate(
			() => document.documentElement.scrollWidth <= innerWidth
		)
	).toBe(true);
	const panel = await page.locator(".settings-sidebar-panel").boundingBox();
	expect(panel?.height).toBeLessThanOrEqual(70);
});

test("Settings navigation remains below its heading while long forms scroll", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	await page.setViewportSize({ width: 1440, height: 900 });
	await page.goto("/dashboard/settings/general");
	await expect(page.locator(".settings-sidebar-link-active")).toBeVisible();
	const heading = page.locator(".settings-page-header");
	const initialHeading = await heading.boundingBox();
	// A long form makes the sticky containing block larger than the navigation list.
	await page
		.locator(".settings-tab-page")
		.evaluate((element) => (element.style.minHeight = "1800px"));
	await page.evaluate(() => window.scrollTo(0, 600));
	const navigation = page.locator(".settings-sidebar-panel");
	const scrolledHeading = await heading.boundingBox();
	expect(scrolledHeading?.y).toBe(initialHeading?.y);
	expect(scrolledHeading?.height).toBe(initialHeading?.height);
	await expect(
		page.locator(".settings-sidebar-link-active")
	).toBeInViewport();
	const headingBottom = await heading.evaluate(
		(element) => element.getBoundingClientRect().bottom
	);
	const navigationTop = await navigation.evaluate(
		(element) => element.getBoundingClientRect().top
	);
	expect(navigationTop).toBeGreaterThanOrEqual(headingBottom);
	await page.evaluate(() => window.scrollBy(0, 200));
	expect(
		await navigation.evaluate(
			(element) => element.getBoundingClientRect().top
		)
	).toBe(navigationTop);
});
