import { expect, test, type Page } from "@playwright/test";
import { readFile } from "node:fs/promises";

import { mockDashboardSession } from "../fixtures/dashboard.fixture";

const longAlias = "regional-release-notes-".repeat(8);
const longDestination = `https://example.com/${"documentation/".repeat(32)}`;
const links = [
	{
		id: "review-link",
		userId: "dashboard-preview",
		alias: longAlias,
		shortUrl: `https://go.example.com/${longAlias}`,
		title: "Regional release documentation",
		destinationUrl: longDestination,
		status: "active",
		createdAt: "2026-07-01T12:00:00Z",
		clicks: 96,
		uniqueClicks: 64,
	},
	{
		id: "review-over-limit",
		userId: "dashboard-preview",
		shortUrl: `https://go.example.com/${"search".repeat(650)}`,
		title: "Overlong QR payload",
		destinationUrl: "https://example.com/large-qr",
		status: "active",
	},
];

async function session(page: Page, direction: "ltr" | "rtl" = "ltr") {
	await page.route("**/api/v1/**", (route) =>
		route.fulfill({ json: { data: {} } })
	);
	await mockDashboardSession(page, {
		direction,
		capabilities: {
			view_links: true,
			view_analytics: true,
			create_links: true,
			edit_links: true,
			trash_links: true,
			delete_links: true,
		},
	});
	await page.route("**/api/v1/system/plugins/extensions", (route) =>
		route.fulfill({ json: { data: { pages: [], uiExtensions: [] } } })
	);
}

function records(items = links, page = 1, totalItems = 35) {
	return {
		data: {
			items,
			meta: {
				page,
				limit: 10,
				totalItems,
				totalPages: totalItems > 15 ? 4 : 1,
				activeLinks: totalItems - 2,
				trashedLinks: 2,
				totalClicks: 2345,
				uniqueClicks: 1923,
			},
		},
	};
}

test("links keep loaded rows during filters and contain long values at all review widths", async ({
	page,
}) => {
	await session(page);
	let finishLoad!: () => void;
	const firstLoad = new Promise<void>((resolve) => {
		finishLoad = resolve;
	});
	await page.route("**/api/v1/urls?*", async (route) => {
		await firstLoad;
		const params = new URL(route.request().url()).searchParams;
		const search = params.get("search");
		const currentPage = Number(params.get("page") ?? 1);
		await route.fulfill({
			json: records(
				search
					? links.filter((link) =>
							link.title.toLowerCase().includes(search)
						)
					: links,
				currentPage,
				search ? 1 : 35
			),
		});
	});
	await page.setViewportSize({ width: 320, height: 900 });
	await page.goto("/dashboard/links");
	const table = page.getByRole("table", { name: "All Links" });
	await expect(
		page.getByRole("heading", { name: "All Links", level: 1 })
	).toBeVisible();
	await expect(
		table.getByRole("columnheader", { name: "Destination" })
	).toBeVisible();
	await expect(
		page.getByRole("heading", { name: "Create Short Link" })
	).toBeVisible();
	await expect(page.getByText("Total Clicks", { exact: true })).toBeVisible();
	await expect(page.locator(".links-table")).toHaveAttribute(
		"aria-busy",
		"true"
	);
	finishLoad();

	const row = page.getByRole("row", {
		name: /Regional release documentation/,
	});
	await expect(row).toBeVisible();
	await expect(
		row.getByRole("link", { name: longDestination })
	).toHaveAttribute("href", longDestination);
	const custom = page.getByRole("button", { name: "Custom", exact: true });
	for (const width of [320, 390, 768, 1024, 1440, 1920]) {
		await page.setViewportSize({ width, height: 900 });
		expect(
			await page.evaluate(() => document.documentElement.scrollWidth)
		).toBe(width);
		const range = await page.locator(".links-date-range").boundingBox();
		const control = await custom.boundingBox();
		expect(control!.x + control!.width).toBeLessThanOrEqual(
			range!.x + range!.width
		);
		const footer = await page.locator(".links-table-footer").boundingBox();
		const pageSize = await page
			.getByRole("button", { name: "Rows per page" })
			.boundingBox();
		expect(pageSize!.x + pageSize!.width).toBeLessThanOrEqual(
			footer!.x + footer!.width
		);
	}
	await page.setViewportSize({ width: 1024, height: 900 });
	await custom.click();
	await page.getByLabel("From", { exact: true }).fill("2026-05-10");
	await page.getByLabel("To", { exact: true }).fill("2026-05-19");
	const dateQuery = page.waitForRequest((request) => {
		const params = new URL(request.url()).searchParams;
		return (
			params.get("range") === "custom" &&
			params.get("from") === "2026-05-10" &&
			params.get("to") === "2026-05-19"
		);
	});
	await page.getByRole("button", { name: "Apply", exact: true }).click();
	await dateQuery;
	await expect(custom).toHaveAttribute("aria-pressed", "true");
	await page.getByRole("button", { name: "Next page" }).click();
	await expect(
		page.getByRole("button", { name: "Page 2", exact: true })
	).toHaveAttribute("aria-current", "page");

	await page.getByRole("textbox", { name: "Search links" }).fill("regional");
	await expect(page).toHaveURL(/search=regional/);
	await expect(
		page.getByRole("row", { name: /Overlong QR payload/ })
	).toHaveCount(0);
	await expect(row).toBeVisible();
	await page.getByRole("button", { name: "Title", exact: true }).click();
	await expect(
		table.getByRole("columnheader", { name: "Title" })
	).toHaveAttribute("aria-sort", "ascending");
});

test("QR error, retry, download and clipboard follow the active link", async ({
	page,
}) => {
	await session(page);
	await page.emulateMedia({ reducedMotion: "reduce" });
	await page.setViewportSize({ width: 320, height: 800 });
	await page.addInitScript(() => {
		Object.defineProperty(navigator, "clipboard", {
			configurable: true,
			value: {
				writeText: async (value: string) => {
					document.documentElement.dataset.clipboard = value;
				},
			},
		});
	});
	await page.route("**/api/v1/urls?*", (route) =>
		route.fulfill({ json: records() })
	);
	await page.goto("/dashboard/links");
	const broken = page
		.getByRole("row", { name: /Overlong QR payload/ })
		.getByRole("button", { name: "QR Code", exact: true });
	const valid = page
		.getByRole("row", { name: /Regional release documentation/ })
		.getByRole("button", { name: "QR Code", exact: true });
	const qr = page.getByRole("dialog", { name: "QR Code" });
	await broken.click();
	await expect(qr.getByRole("status")).toHaveText(
		"The QR code could not be generated."
	);
	await expect(qr.getByRole("button", { name: "Download" })).toBeDisabled();
	await qr.getByRole("button", { name: "Try again" }).click();
	await expect(qr.getByRole("status")).toHaveText(
		"The QR code could not be generated."
	);
	await page.keyboard.press("Escape");
	await expect(broken).toBeFocused();
	await valid.click();
	await expect(qr.getByRole("img", { name: "QR Code" })).toBeVisible();
	await qr.getByRole("button", { name: "Copy" }).click();
	await expect(page.locator("html")).toHaveAttribute(
		"data-clipboard",
		links[0].shortUrl
	);
	await expect(qr.getByRole("button", { name: "Copied!" })).toBeVisible();
	const download = page.waitForEvent("download");
	await qr.getByRole("button", { name: "Download" }).click();
	const file = await download;
	expect(file.suggestedFilename()).toBe(`qr-code-${longAlias}.png`);
	const image = await readFile((await file.path())!);
	expect(image.subarray(0, 8)).toEqual(
		Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])
	);
	expect(image.readUInt32BE(16)).toBe(400);
	expect(image.readUInt32BE(20)).toBe(400);
	await page.keyboard.press("Escape");
	await expect(valid).toBeFocused();
});

test("create, edit, delete and restore links using the existing controls", async ({
	page,
}) => {
	await session(page);
	await page.emulateMedia({ reducedMotion: "reduce" });
	let created = {
		...links[0],
		id: "created-link",
		alias: "review-docs",
		title: "Review documentation",
		shortUrl: "https://go.example.com/review-docs",
	};
	let saved = false;
	let trashed = false;
	let writes = 0;
	await page.route("**/api/v1/urls", async (route) => {
		if (route.request().method() !== "POST")
			return route.fulfill({ json: records() });
		const payload = route.request().postDataJSON();
		expect(payload).toMatchObject({
			alias: "review-docs",
			title: "Review documentation",
			destinationUrl: "https://example.com/new",
		});
		saved = true;
		writes++;
		await route.fulfill({ status: 201, json: { data: created } });
	});
	await page.route("**/api/v1/urls?*", (route) => {
		const trashView =
			new URL(route.request().url()).searchParams.get("status") ===
			"trashed";
		const items = trashView
			? trashed
				? [created]
				: []
			: saved && !trashed
				? [created, ...links]
				: links;
		return route.fulfill({ json: records(items) });
	});
	await page.route("**/api/v1/urls/created-link", async (route) => {
		switch (route.request().method()) {
			case "PUT":
				created = { ...created, ...route.request().postDataJSON() };
				writes++;
				return route.fulfill({ json: { data: created } });
			case "DELETE":
				trashed = true;
				writes++;
				return route.fulfill({ json: { data: created } });
			default:
				return route.fulfill({ json: { data: created } });
		}
	});
	await page.route("**/api/v1/urls/created-link/restore", (route) => {
		expect(route.request().method()).toBe("POST");
		trashed = false;
		writes++;
		return route.fulfill({ json: { data: created } });
	});

	await page.goto("/dashboard/links");
	const create = page.locator(".links-form");
	await create
		.getByRole("button", { name: "Advanced Options", exact: true })
		.click();
	await create
		.getByLabel("Link Title (Optional)", { exact: true })
		.fill(created.title);
	await create
		.getByLabel("Destination URL", { exact: true })
		.fill("https://example.com/new");
	await create
		.getByLabel("Alias (Optional)", { exact: true })
		.fill(created.alias);
	await create.getByRole("button", { name: "Shorten", exact: true }).click();
	await expect(create.getByText(/Link shortened successfully/)).toBeVisible();

	let row = page.getByRole("row", { name: /Review documentation/ });
	await row.getByRole("button", { name: "Edit", exact: true }).click();
	const editor = page.getByRole("dialog", { name: "Edit Link" });
	await editor
		.getByLabel("Title (Optional)", { exact: true })
		.fill("Revised documentation");
	await editor
		.getByRole("button", { name: "Save Changes", exact: true })
		.click();
	row = page.getByRole("row", { name: /Revised documentation/ });
	await expect(row).toBeVisible();
	await row.getByRole("button", { name: "Delete", exact: true }).click();
	await page
		.getByRole("dialog", { name: "Move to Trash" })
		.getByRole("button", { name: "Move to Trash", exact: true })
		.click();
	await expect(row).toHaveCount(0);
	await page.getByRole("button", { name: "Filter links by status" }).click();
	await page.getByRole("option", { name: /^Trash/ }).click();
	await row.getByRole("button", { name: "Restore Link" }).click();
	await expect(row).toHaveCount(0);
	expect(writes).toBe(4);
});

test("analytics and editing keep controls inside the inset drawers in RTL and preserve plugin actions", async ({
	page,
}) => {
	await session(page, "rtl");
	await page.emulateMedia({ reducedMotion: "reduce" });
	await page.route("**/api/v1/urls?*", (route) =>
		route.fulfill({ json: records() })
	);
	await page.route("**/api/v1/analytics/url/**", (route) =>
		route.fulfill({
			json: {
				data: {
					totalClicks: 96,
					uniqueClicks: 64,
					clicksByDate: { "2026-07-01": 12, "2026-07-02": 84 },
				},
			},
		})
	);
	await page.route("**/api/v1/system/plugins/extensions", (route) =>
		route.fulfill({
			json: {
				data: {
					pages: [],
					uiExtensions: [
						{
							id: "overview-report",
							pluginSlug: "review",
							actionId: "report",
							location: "links_overview_actions",
							label: "Export link report",
						},
						{
							id: "analytics-report",
							pluginSlug: "review",
							actionId: "report",
							location: "link_stats_actions",
							label: "Export analytics report",
						},
					],
				},
			},
		})
	);

	const row = page.getByRole("row", {
		name: /Regional release documentation/,
	});
	const analytics = row.getByRole("button", {
		name: "Analytics",
		exact: true,
	});
	await page.setViewportSize({ width: 1440, height: 900 });
	await page.goto("/dashboard/links");
	await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
	await expect(
		page.getByRole("button", { name: "Export link report" })
	).toBeVisible();
	for (const width of [1440, 390, 320]) {
		await page.setViewportSize({ width, height: 850 });
		await analytics.click();
		const panel = page.locator(".links-drawer-panel");
		await expect(
			panel.getByRole("button", { name: "Export analytics report" })
		).toBeVisible();
		await expect(
			panel.getByRole("heading", { name: "Link Analytics" })
		).toBeVisible();
		await expect
			.poll(() =>
				panel.evaluate((element) => {
					const panel = element.getBoundingClientRect();
					const short = element.querySelector(
						".links-drawer-short-link"
					)!;
					const buttons = [...short.querySelectorAll("button")];
					const body = element.querySelector(
						".links-drawer-content"
					)!;
					return {
						left: Math.round(panel.left),
						top: Math.round(panel.top),
						overflow: body.scrollWidth - body.clientWidth,
						controlsFit: buttons.every((button) => {
							const box = button.getBoundingClientRect();
							return (
								box.left >= panel.left &&
								box.right <= panel.right
							);
						}),
					};
				})
			)
			.toEqual({
				left: width >= 640 ? 12 : 8,
				top: width >= 640 ? 12 : 8,
				overflow: 0,
				controlsFit: true,
			});
		await page.keyboard.press("Escape");
		await expect(panel).toBeHidden();
		await expect(analytics).toBeFocused();
	}
	await row.getByRole("button", { name: "Edit", exact: true }).click();
	const edit = page.locator(".links-edit-drawer-panel");
	await expect(
		edit.getByRole("button", { name: "Save Changes" })
	).toBeVisible();
	expect(
		await edit.evaluate((node) => {
			const content = node.querySelector(".links-edit-drawer-content")!;
			return content.scrollWidth - content.clientWidth;
		})
	).toBe(0);
});

test("bulk trash sends only the selected links and keeps the last unselected row", async ({
	page,
}) => {
	await session(page);
	await page.emulateMedia({ reducedMotion: "reduce" });
	let selection: string[] = [];
	await page.route("**/api/v1/urls?*", (route) =>
		route.fulfill({
			json: records(links.filter((link) => !selection.includes(link.id))),
		})
	);
	await page.route("**/api/v1/urls/bulk", (route) => {
		expect(route.request().method()).toBe("DELETE");
		selection = route.request().postDataJSON().ids;
		return route.fulfill({ json: { data: { deletedCount: 1 } } });
	});
	await page.goto("/dashboard/links");
	const row = page.getByRole("row", {
		name: /Regional release documentation/,
	});
	await row.getByRole("checkbox").check();
	await page.getByRole("button", { name: "Delete selected" }).click();
	await page
		.getByRole("dialog", { name: "Move Links to Trash" })
		.getByRole("button", { name: "Move to Trash (1)" })
		.click();
	await expect(row).toHaveCount(0);
	await expect(
		page.getByRole("row", { name: /Overlong QR payload/ })
	).toBeVisible();
	expect(selection).toEqual(["review-link"]);
});
