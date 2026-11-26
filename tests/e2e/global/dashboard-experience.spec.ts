import { expect, test } from "@playwright/test";
import type { Locator, Page } from "@playwright/test";

import { mockDashboardSession } from "../fixtures/dashboard.fixture";

const capabilities = {
	view_links: true,
	view_analytics: true,
	manage_users: true,
	manage_site_settings: true,
};

const populatedAnalytics = {
	totalClicks: 120,
	lastMonthTotalClicks: 100,
	totalLinks: 48,
	lastMonthTotalLinks: 48,
	uniqueClicks: 60,
	lastMonthUniqueClicks: 45,
	uniqueClickRate: 50,
	lastMonthUniqueClickRate: 45,
	lastMonth: {
		type: "lastMonth",
		days: 7,
		startDate: "2026-10-19",
		endDate: "2026-10-25",
	},
	traffic: {
		labels: ["2026-11-19", "2026-11-20"],
		clicks: [55, 65],
		unique: [25, 35],
	},
	devices: [
		{ name: "desktop", count: 75 },
		{ name: "mobile", count: 45 },
	],
	browsers: [{ name: "Firefox", count: 75 }],
	operatingSystems: [{ name: "macOS", count: 75 }],
	countries: [
		{ name: "United Kingdom", code: "GB", count: 80 },
		{ name: "Japan", code: "JP", count: 40 },
	],
};

function holdResponse() {
	let release = () => {};
	const ready = new Promise<void>((resolve) => {
		release = resolve;
	});
	return { ready, release };
}

async function expectSidebarIndicator(page: Page, selected: Locator) {
	await expect
		.poll(async () => {
			const active = await selected.boundingBox();
			const background = await page
				.locator(".dashboard-sidebar-indicator")
				.boundingBox();
			if (!active || !background) return Infinity;
			return Math.max(
				Math.abs(active.x - background.x),
				Math.abs(active.y - background.y),
				Math.abs(active.width - background.width)
			);
		})
		.toBeLessThan(1);
}

test("sidebar groups retain selection across groups and remain usable in the rail", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	await page.setViewportSize({ width: 1440, height: 900 });
	await page.goto("/dashboard/links");

	const nav = page.getByRole("navigation", { name: "Dashboard" });
	const overview = nav.getByRole("link", { name: "Overview", exact: true });
	const management = nav.getByRole("region", { name: "Management" });
	const system = nav.getByRole("region", { name: "System" });
	await expect(
		management.getByRole("link", { name: "Links" })
	).toHaveAttribute("aria-current", "page");
	await expect(
		management.getByRole("link", { name: /plugins/i })
	).toBeVisible();

	await expect(nav.getByRole("region", { name: "Workspace" })).toHaveCount(0);
	await expect(
		management.getByRole("link", { name: "Overview" })
	).toHaveCount(0);
	await overview.click();
	await expect(overview).toHaveAttribute("aria-current", "page");
	await expectSidebarIndicator(page, overview);

	const settings = system.getByRole("link", { name: "Settings" });
	await settings.click();
	await expect(settings).toHaveAttribute("aria-current", "page");
	await expectSidebarIndicator(page, settings);

	await page.getByRole("button", { name: "Collapse sidebar" }).click();
	await expect(
		page.getByRole("button", { name: "Expand sidebar" })
	).toBeVisible();
	await expect(
		management.locator(".dashboard-sidebar-group-label")
	).toBeHidden();
	const users = management.getByRole("link", { name: "Users" });
	await users.click();
	await expect(users).toHaveAttribute("aria-current", "page");
	await expectSidebarIndicator(page, users);
});

test("mobile RTL navigation retains Links but filters restricted destinations", async ({
	page,
}) => {
	await mockDashboardSession(page, {
		role: "editor",
		capabilities: { view_links: true },
		direction: "rtl",
	});
	await page.route("**/api/v1/system/plugins/extensions", (route) =>
		route.fulfill({ json: { data: { pages: [], uiExtensions: [] } } })
	);
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto("/dashboard/links");
	await page.getByRole("button", { name: "Open menu" }).click();
	const nav = page.getByRole("navigation", { name: "Dashboard" });
	await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
	await expect(
		nav.getByRole("link", { name: "Overview", exact: true })
	).toBeVisible();
	const management = nav.getByRole("region", { name: "Management" });
	await expect(management.getByRole("link", { name: "Links" })).toBeVisible();
	await expect(management.getByRole("link", { name: "Users" })).toHaveCount(
		0
	);
	await expect(management.getByRole("link", { name: /Plugins/ })).toHaveCount(
		0
	);
	await expect(nav.getByRole("button", { name: "Tools" })).toHaveCount(0);
	await nav
		.getByRole("region", { name: "System" })
		.getByRole("link", { name: "Settings" })
		.click();
	await expect(
		page.getByRole("heading", { name: "General", exact: true })
	).toBeVisible();
	const drawer = page.locator(".dashboard-sidebar");
	await expect(drawer).toHaveAttribute("inert", "");
	await expect
		.poll(async () => (await drawer.boundingBox())?.x ?? 0)
		.toBeGreaterThanOrEqual(390);
});

test("sidebar brand opens Overview without animating the mark", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	await page.setViewportSize({ width: 1440, height: 900 });
	await page.goto("/dashboard/links");
	await page.getByRole("button", { name: "Collapse sidebar" }).click();

	const logo = page.locator(".dashboard-sidebar-logo");
	const mark = logo.locator("[data-logo-icon]");
	const expectStatic = async () => {
		await expect(mark).not.toHaveAttribute("data-motion", /.+/);
		expect(
			await mark.evaluate(
				(svg) => svg.getAnimations({ subtree: true }).length
			)
		).toBe(0);
	};
	await logo.press("Enter");
	await expect(page).toHaveURL(/\/dashboard$/);
	await expectStatic();

	await page.getByRole("link", { name: /^Links/ }).click();
	await logo.click();
	await expect(page).toHaveURL(/\/dashboard$/);
	await expectStatic();
});

test("session loader loops while pending, honors reduced motion and unmounts", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	const session = holdResponse();
	await page.route("**/api/v1/users/me", async (route) => {
		await session.ready;
		await route.fallback();
	});
	await page.goto("/dashboard");
	const loader = page.getByRole("status").filter({ hasText: "Loading…" });
	try {
		await expect(loader).toBeVisible();
		const mark = loader.locator("[data-logo-icon]");
		await expect(mark).toHaveCSS("width", "112px");
		await expect(mark.locator(".logo-icon-middle")).toHaveCSS(
			"animation-duration",
			"1.7s"
		);
		await expect(mark.locator(".logo-icon-middle")).toHaveCSS(
			"animation-delay",
			"-0.2s"
		);
		await page.setViewportSize({ width: 390, height: 844 });
		await expect(mark).toHaveCSS("width", "88px");
		await expect(mark).toHaveAttribute("data-motion", "loop");
		await expect
			.poll(() =>
				mark.evaluate(
					(svg) =>
						svg
							.getAnimations({ subtree: true })
							.filter(
								(animation) =>
									animation.effect?.getTiming().iterations ===
									Infinity
							).length
				)
			)
			.toBe(3);
		await page.emulateMedia({ reducedMotion: "reduce" });
		await expect
			.poll(() =>
				mark.evaluate(
					(svg) => svg.getAnimations({ subtree: true }).length
				)
			)
			.toBe(0);
	} finally {
		session.release();
	}
	await expect(loader).toHaveCount(0);
	await page.getByRole("button", { name: "Open menu" }).click();
	await expect(
		page.getByRole("navigation", { name: "Dashboard" })
	).toBeVisible();
});

test("Users keeps the same heading, card grid and account table while waiting for rows", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	const users = holdResponse();
	await page.route("**/api/v1/users", async (route) => {
		await users.ready;
		await route.fulfill({
			json: {
				data: [
					{
						id: "staff-1",
						role: "editor",
						username: "sam",
						displayName: "Sam Example",
						email: "sam@example.com",
						createdAt: "2026-11-07T12:00:00Z",
					},
				],
			},
		});
	});
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto("/dashboard/users");
	const table = page.locator(".users-page-panel");
	const search = page.getByRole("textbox", { name: "Search users" });
	const cards = page.locator(".users-page-overview-item");
	try {
		await expect(
			page.getByRole("heading", { name: "Users", exact: true })
		).toBeVisible();
		await expect(
			page.getByRole("button", { name: "Add User", exact: true })
		).toBeDisabled();
		await expect(table).toHaveAttribute("aria-busy", "true");
		await expect(search).toBeDisabled();
		await expect(cards).toHaveCount(4);
		await expect(table.getByRole("columnheader")).toHaveCount(4);
		await expect(
			table.locator("tr[aria-hidden=true] .avatar-md").first()
		).toHaveCSS("width", "44px");
	} finally {
		users.release();
	}
	await expect(table).toHaveAttribute("aria-busy", "false");
	await expect(search).toBeEnabled();
	await expect(cards).toHaveCount(4);
	await expect(table.getByText("Sam Example")).toBeVisible();
	await expect(table.locator(".avatar-md").first()).toHaveCSS(
		"width",
		"44px"
	);
});

test("Overview uses returned comparison windows, preserves loaded data on refresh, and renders the pinned CDN map", async ({
	page,
	baseURL,
}) => {
	const local = new URL(baseURL!);
	const atlasUrl =
		"https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/countries-50m.json";
	await page.route(
		(url) => url.origin !== local.origin && url.href !== atlasUrl,
		(route) => route.abort()
	);
	const atlasRequests: string[] = [];
	page.on("request", (request) => {
		if (request.url() === atlasUrl) atlasRequests.push(request.url());
	});
	await mockDashboardSession(page, { capabilities });
	await page.route("**/api/v1/analytics/activity", (route) =>
		route.fulfill({ json: { data: [] } })
	);
	await page.route("**/api/v1/analytics/recent-clicks?*", (route) =>
		route.fulfill({ json: { data: [] } })
	);
	const overview = holdResponse();
	const refresh = holdResponse();
	let requests = 0;
	await page.route("**/api/v1/analytics?*", async (route) => {
		const days = new URL(route.request().url()).searchParams.get("days");
		if (days === "7") {
			await (++requests === 1 ? overview.ready : refresh.ready);
			return route.fulfill({ json: { data: populatedAnalytics } });
		}
		return route.fulfill({
			json: {
				data: {
					totalClicks: 0,
					lastMonthTotalClicks: 0,
					totalLinks: 48,
					uniqueClicks: 0,
					uniqueClickRate: 0,
					countries: [],
				},
			},
		});
	});
	await page.setViewportSize({ width: 1440, height: 900 });
	const mapResponse = page.waitForResponse(atlasUrl);
	await page.goto("/dashboard");
	const stats = page.locator(".dashboard-stats-grid");
	await expect(stats).toHaveAttribute("aria-busy", "true");
	await expect(
		page.locator(
			".dashboard-page-traffic-grid .dashboard-traffic[aria-busy=true]"
		)
	).toBeVisible();
	overview.release();
	const metric = (label: string) =>
		stats
			.locator(".dashboard-stats-card")
			.filter({ has: page.getByText(label, { exact: true }) });
	await expect(metric("Total Clicks")).toContainText("+20%");
	await expect(
		metric("Total Clicks").locator(".dashboard-stats-card-change")
	).toHaveAttribute("title", "Compared with Oct 19, 2026 to Oct 25, 2026.");
	await expect(metric("Unique Click Rate")).toContainText("+5 pp");
	await expect(
		metric("Total Links").locator(".dashboard-stats-change-neutral")
	).toHaveText("0%");
	const response = await mapResponse;
	expect(response.ok()).toBe(true);
	expect(response.url()).toBe(atlasUrl);
	expect(await response.request().headerValue("referer")).toBeNull();
	expect(await response.request().headerValue("cookie")).toBeNull();
	await expect
		.poll(() => page.locator(".world-map-country").count())
		.toBeGreaterThan(200);
	await expect(page.locator(".world-map-country-clickable")).toHaveCount(2);
	expect(atlasRequests).toEqual([atlasUrl]);

	const chart = page.locator(".dashboard-traffic canvas");
	const initial = await chart.boundingBox();
	expect(initial).not.toBeNull();
	await page.getByRole("button", { name: "Collapse sidebar" }).click();
	await expect
		.poll(async () => (await chart.boundingBox())?.width ?? 0)
		.toBeGreaterThan(initial!.width + 80);
	await expect(page.locator(".world-map-country-clickable")).toHaveCount(2);

	await page.getByRole("button", { name: "Refresh" }).click();
	await expect.poll(() => requests).toBe(2);
	await expect(stats).toHaveAttribute("aria-busy", "false");
	await expect(metric("Total Clicks")).toContainText("120");
	await expect(
		stats.locator(".dashboard-stats-value-placeholder")
	).toHaveCount(0);
	refresh.release();

	const range = page.locator(".dashboard-overview-header-select button");
	await range.click();
	await page.getByRole("option", { name: "Last 30 days" }).click();
	await expect(
		metric("Total Clicks").locator(".dashboard-stats-card-value")
	).toHaveText("0");
	await expect(
		metric("Unique Click Rate").locator(".dashboard-stats-card-value")
	).toHaveText("—");
	await expect(page.getByText("No country data available")).toBeVisible();
	await expect(page.locator(".world-map")).toHaveCount(0);
});

test("delayed General settings use the real page title and permitted section layout", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	const general = holdResponse();
	await page.route("**/api/v1/system/general", async (route) => {
		await general.ready;
		await route.fulfill({
			json: {
				data: {
					canManageSiteSettings: true,
					siteName: "Review installation",
				},
			},
		});
	});
	await page.setViewportSize({ width: 1440, height: 900 });
	await page.goto("/dashboard/settings/general");
	const heading = page.getByRole("heading", {
		name: "General",
		level: 1,
		exact: true,
	});
	try {
		await expect(heading).toHaveCount(1);
		const form = page.locator(".settings-general-form");
		await expect(form).toHaveAttribute("aria-busy", "true");
		await expect(form.getByRole("heading", { level: 2 })).toHaveText([
			"Profile Information",
			"Branding",
			"Site Configuration",
			"Regional Preferences",
			"Data Retention",
			"Homepage Configuration",
		]);
		await expect(form.getByLabel("First Name")).toBeDisabled();
		await expect(form.getByLabel("First Name")).toHaveValue("Preview");
		await expect(form.locator(".settings-general-profile")).toHaveAttribute(
			"aria-busy",
			"false"
		);
		const siteTitle = form.getByLabel("Site title");
		await expect(siteTitle).toHaveCSS("color", "rgba(0, 0, 0, 0)");
		expect(
			await siteTitle.evaluate(
				(element) =>
					getComputedStyle(element.parentElement!, "::after").content
			)
		).toBe('""');
		await expect(
			form.locator(".settings-image-preview[aria-busy=true] .skeleton")
		).toHaveCount(2);
		await expect(
			form.getByRole("button", { name: "Save Changes" })
		).toBeDisabled();
	} finally {
		general.release();
	}
	await expect(heading).toHaveCount(1);
	await expect(
		page.getByRole("heading", { name: "Profile Information" })
	).toBeVisible();
	await expect(page.locator(".settings-general-form")).toHaveAttribute(
		"aria-busy",
		"false"
	);
	await expect(page.getByLabel("First Name")).toBeEnabled();
	await expect(page.getByLabel("Site title")).toHaveValue(
		"Review installation"
	);
	await expect(
		page.locator(".settings-image-preview[aria-busy=true]")
	).toHaveCount(0);
});

test("Links keeps an in-progress shortening field while its table loads", async ({
	page,
}) => {
	await mockDashboardSession(page, {
		capabilities: { ...capabilities, create_links: true },
	});
	const links = holdResponse();
	await page.route("**/api/v1/urls?*", async (route) => {
		await links.ready;
		await route.fallback();
	});
	await page.goto("/dashboard/links");
	const field = page.getByPlaceholder("Enter long link here...");
	try {
		await expect(
			page.locator(".links-table[aria-busy=true]")
		).toBeVisible();
		await field.fill("https://example.com/keep-this-draft");
	} finally {
		links.release();
	}
	await expect(page.locator(".links-table[aria-busy=true]")).toHaveCount(0);
	await expect(field).toHaveValue("https://example.com/keep-this-draft");
});

test("installed plugins reserve the actual table columns during initial loading", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities });
	const plugins = holdResponse();
	await page.route("**/api/v1/system/plugins", async (route) => {
		await plugins.ready;
		await route.fulfill({ json: { data: { regular: [], mustUse: [] } } });
	});
	await page.setViewportSize({ width: 1440, height: 900 });
	await page.goto("/dashboard/plugins");
	try {
		const table = page.locator(".plugins-table[aria-busy=true]");
		await expect(table.getByRole("columnheader")).toHaveText([
			"Plugin",
			"Description",
		]);
		await expect(table.locator("tbody tr")).toHaveCount(3);
	} finally {
		plugins.release();
	}
	await expect(
		page.getByText("No plugins installed yet", { exact: true })
	).toBeVisible();
});
