import { test, expect, type Page } from "@playwright/test";
import { mockDrawerPages } from "../fixtures/drawer.fixture";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";

const events = [
	{
		id: "created",
		type: "link_created",
		timestamp: "2026-10-15T12:00:00Z",
		actor: { displayName: "Preview User" },
		link: {
			title: "Autumn launch",
			destinationUrl:
				"https://example.com/launch/with/a/long/path/that/should/not/overflow/the/activity/list",
		},
		location: { city: "London", country: "United Kingdom" },
	},
	{
		id: "trashed",
		type: "link_trashed",
		timestamp: "2026-10-15T11:00:00Z",
		actor: { displayName: "Preview User" },
		link: {
			title: "Previous campaign",
			destinationUrl: "https://example.com/previous",
		},
		isRestorable: true,
		linkStatus: "trashed",
	},
	{
		id: "user",
		type: "user_updated",
		timestamp: "2026-10-15T10:00:00Z",
		actor: { displayName: "Preview User" },
		user: { displayName: "Campaign Editor", role: "editor" },
	},
];

async function mockActivity(page: Page) {
	await page.route("**/api/v1/analytics/activity/history?*", (route) => {
		const query = new URL(route.request().url()).searchParams;
		const category = query.get("category");
		const items =
			category === "users"
				? events.slice(2)
				: category === "links"
					? events.slice(0, 2)
					: events;
		return route.fulfill({
			json: {
				data: {
					items,
					meta: {
						page: Number(query.get("page")),
						limit: Number(query.get("limit")),
						totalItems: category ? items.length : 70,
						totalPages: category ? 1 : 3,
					},
				},
			},
		});
	});
}

for (const { width, direction, dark } of [
	{ width: 1440, direction: "ltr", dark: false },
	{ width: 768, direction: "ltr", dark: false },
	{ width: 390, direction: "ltr", dark: false },
	{ width: 320, direction: "rtl", dark: true },
] as const) {
	test(`activity filters, selection and event actions reflow at ${width}px ${direction}`, async ({
		page,
	}) => {
		await page.setViewportSize({ width, height: 1000 });
		await mockDrawerPages(page, direction);
		await mockActivity(page);
		if (dark)
			await page.addInitScript(() =>
				localStorage.setItem("theme", "dark")
			);
		await page.goto("/dashboard/activity");
		if (dark) await expect(page.locator("html")).toHaveClass(/dark/);
		await expect(page.locator("article.activity-page-event")).toHaveCount(
			3
		);
		if (process.env.PEAKURL_UI_PREVIEW) {
			await page.screenshot({
				path: `/tmp/peakurl-activity-initial-${width}.png`,
			});
		}
		for (const selector of [
			".activity-page-panel",
			".activity-page-table-element",
			".activity-page-event",
			".activity-page-pagination",
		]) {
			for (const element of await page.locator(selector).all()) {
				const size = await element.evaluate((node) => ({
					width: node.clientWidth,
					scroll: node.scrollWidth,
				}));
				expect(size.scroll, selector).toBeLessThanOrEqual(
					size.width + 1
				);
			}
		}
		await page
			.getByRole("checkbox", { name: "Select all events on this page" })
			.check();
		await expect(
			page.getByText("3 selected", { exact: true })
		).toBeVisible();
		await page
			.getByRole("button", { name: "Delete selected", exact: true })
			.click();
		await expect(
			page
				.getByRole("dialog", { name: "Delete activity logs" })
				.locator(".confirm-dialog-panel")
		).toBeVisible();
		await page.getByRole("button", { name: "Cancel", exact: true }).click();
		await page
			.getByRole("checkbox", { name: "Select all events on this page" })
			.uncheck();
		await expect(
			page.getByRole("button", { name: "Restore link", exact: true })
		).toBeEnabled();
		await page.getByRole("tab", { name: "Users", exact: true }).click();
		await expect(page).toHaveURL(/category=users/);
		await expect(page.locator("article.activity-page-event")).toHaveCount(
			1
		);
		await expect(
			page.getByText('Updated user "Campaign Editor"', { exact: true })
		).toBeVisible();
		await page
			.getByRole("tab", { name: "All Events", exact: true })
			.click();
		await page.getByRole("button", { name: "Next", exact: true }).click();
		await expect(
			page.getByText("Page 2 of 3", { exact: true })
		).toBeVisible();
		expect(
			await page.evaluate(() => document.documentElement.scrollWidth)
		).toBeLessThanOrEqual(width);
		if (process.env.PEAKURL_UI_PREVIEW) {
			await page.evaluate(() => window.scrollTo(0, 0));
			await page.screenshot({
				path: `/tmp/peakurl-activity-${width}-${direction}.png`,
				fullPage: false,
			});
		}
	});
}

test("activity remains restricted to administrators", async ({ page }) => {
	await mockDrawerPages(page);
	await mockDashboardSession(page, {
		role: "editor",
		capabilities: { view_links: true },
	});
	await mockActivity(page);
	await page.goto("/dashboard/activity");
	await expect(page).toHaveURL(/\/dashboard\/links$/);
	await expect(page.locator("article.activity-page-event")).toHaveCount(0);
	await expect(page.getByRole("checkbox")).toHaveCount(0);
	await expect(
		page.getByRole("button", { name: "Delete activity log", exact: true })
	).toHaveCount(0);
});
