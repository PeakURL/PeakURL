import { test, expect, type Page } from "@playwright/test";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";

const searchName = "Search the dashboard";
const inputName = "Search links, people, and pages";
const capabilityFlags = {
	view_links: true,
	manage_users: true,
	manage_api_keys: true,
	manage_integrations: true,
	manage_mail_delivery: true,
	manage_location_data: true,
	manage_performance: true,
	manage_updates: true,
};

async function openSearch(page: Page) {
	await page.getByRole("button", { name: searchName, exact: true }).click();
	await expect(page.getByRole("dialog", { name: searchName })).toBeVisible();
	return page.getByRole("combobox", { name: inputName });
}

test.beforeEach(async ({ page }) => {
	await mockDashboardSession(page, { capabilities: capabilityFlags });
	await page.route("**/api/v1/users", (route) =>
		route.fulfill({ json: { data: [] } })
	);
});

test("search lives beside the theme toggle and supports shortcuts, focus trapping, and dismissal", async ({
	page,
}) => {
	await page.goto("/dashboard/about");
	const trigger = page.getByRole("button", { name: searchName, exact: true });
	await expect(
		page
			.locator(".dashboard-header-actions")
			.getByRole("button", { name: searchName })
	).toBeVisible();
	await expect(page.getByRole("combobox", { name: inputName })).toHaveCount(
		0
	);
	const input = await openSearch(page);
	await expect(input).toBeFocused();
	await expect(page.getByRole("option", { name: /Overview/ })).toBeVisible();
	await page.keyboard.press("Shift+Tab");
	await expect(
		page.getByRole("button", { name: "Close search" })
	).toBeFocused();
	await page.keyboard.press("Tab");
	await expect(input).toBeFocused();
	await page.keyboard.press("Escape");
	await expect(page.getByRole("dialog")).toHaveCount(0);
	await expect(trigger).toBeFocused();
	for (const shortcut of ["Control+k", "Meta+k"]) {
		await page.keyboard.press(shortcut);
		await expect(input).toBeFocused();
		await page.getByRole("button", { name: "Close search" }).click();
		await expect(trigger).toBeFocused();
	}
	await openSearch(page);
	await page.mouse.click(5, 5);
	await expect(page.getByRole("dialog")).toHaveCount(0);
});

test("keyboard selection opens settings and the same search works after navigation", async ({
	page,
}) => {
	await page.goto("/dashboard/about");
	const input = await openSearch(page);
	await input.fill("security");
	await expect(
		page.getByRole("option", { name: /Security Settings/ })
	).toBeVisible();
	await page.keyboard.press("ArrowDown");
	await page.keyboard.press("ArrowUp");
	await page.keyboard.press("Enter");
	await expect(page).toHaveURL(/\/dashboard\/settings\/security$/);
	await expect(page.getByRole("dialog")).toHaveCount(0);
	await openSearch(page);
	await input.fill("about");
	await page.getByRole("option", { name: /About PeakURL/ }).click();
	await expect(page).toHaveURL(/\/dashboard\/about$/);
});

test("global search combines links, people, tools, and the full links search", async ({
	page,
}) => {
	await page.route("**/api/v1/urls?*", (route) =>
		route.fulfill({
			json: {
				data: {
					items: [
						{
							id: "example-link",
							alias: "guide",
							shortUrl: "https://example.test/guide",
							title: "Team guide",
							destinationUrl:
								"https://example.test/documentation",
						},
					],
					meta: { totalItems: 1 },
				},
			},
		})
	);
	await page.route("**/api/v1/users", (route) =>
		route.fulfill({
			json: {
				data: [
					{
						id: "guide-editor",
						username: "guide-editor",
						displayName: "Guide editor",
						role: "editor",
					},
				],
			},
		})
	);
	await page.goto("/dashboard/about");
	const input = await openSearch(page);
	await input.fill("guide");
	await expect(
		page.getByRole("option", { name: /Team guide/ })
	).toBeVisible();
	await expect(
		page.getByRole("option", { name: /Guide editor/ })
	).toBeVisible();
	await page
		.getByRole("option", { name: /Search all links for "guide"/ })
		.click();
	await expect(page).toHaveURL(/\/dashboard\/links\?search=guide$/);
	await openSearch(page);
	await input.fill("redis");
	await expect(
		page.getByRole("option", { name: /Performance/ })
	).toBeVisible();
	await input.fill("import");
	await expect(page.getByRole("option", { name: /^Import/ })).toBeVisible();
});

test("a link result opens its statistics and API failures can be retried", async ({
	page,
}) => {
	let requests = 0;
	await page.route("**/api/v1/urls?*", (route) => {
		if (!new URL(route.request().url()).searchParams.get("search"))
			return route.fulfill({
				json: { data: { items: [], meta: { totalItems: 0 } } },
			});
		requests++;
		return requests === 1
			? route.fulfill({ status: 503, json: { message: "Unavailable" } })
			: route.fulfill({
					json: {
						data: {
							items: [
								{
									id: "guide",
									alias: "guide",
									title: "Team guide",
									destinationUrl:
										"https://example.test/guide",
								},
							],
							meta: { totalItems: 1 },
						},
					},
				});
	});
	await page.goto("/dashboard/about");
	const input = await openSearch(page);
	await input.fill("guide");
	const links = page.getByRole("group", { name: "Links", exact: true });
	await expect(links.getByRole("alert")).toContainText(
		"Results could not be loaded."
	);
	await links.getByRole("button", { name: "Try again" }).click();
	await page.getByRole("option", { name: /Team guide/ }).click();
	await expect(page).toHaveURL(/\/dashboard\/links\?stats=guide$/);
});

test("permissions hide restricted destinations and prevent restricted API requests", async ({
	page,
}) => {
	await mockDashboardSession(page, {
		role: "editor",
		capabilities: { view_links: true },
	});
	let userRequests = 0;
	await page.route("**/api/v1/users", (route) => {
		userRequests++;
		return route.fulfill({ json: { data: [] } });
	});
	await page.goto("/dashboard/about");
	const input = await openSearch(page);
	await input.fill("users");
	await expect(
		page.getByRole("group", { name: "People", exact: true })
	).toHaveCount(0);
	await expect(page.getByRole("option", { name: /^Users/ })).toHaveCount(0);
	await input.fill("updates");
	await expect(page.getByRole("option", { name: /^Updates/ })).toHaveCount(0);
	await expect(
		page.getByRole("group", { name: "Links", exact: true })
	).toContainText("No matches found.");
	expect(userRequests).toBe(0);
});

test("typing is debounced, stale links disappear, and closing stops search requests", async ({
	page,
}) => {
	const searched: string[] = [];
	await page.route("**/api/v1/urls?*", (route) => {
		const query = new URL(route.request().url()).searchParams.get("search");
		if (query) searched.push(query);
		return route.fulfill({
			json: {
				data: {
					items:
						query === "first"
							? [
									{
										id: "first",
										alias: "first",
										title: "First result",
										destinationUrl:
											"https://example.test/first",
									},
								]
							: [],
					meta: { totalItems: 0 },
				},
			},
		});
	});
	await page.goto("/dashboard/about");
	const input = await openSearch(page);
	await input.fill("fi");
	await input.fill("fir");
	await input.fill("first");
	await expect(
		page.getByRole("option", { name: /First result/ })
	).toBeVisible();
	expect(searched).toEqual(["first"]);
	await input.fill("second");
	await expect(
		page.getByRole("option", { name: /First result/ })
	).toHaveCount(0);
	await page.keyboard.press("Escape");
	await page.waitForTimeout(350); // Covers the API debounce interval after the dialog unmounts.
	expect(searched).toEqual(["first"]);
});

for (const width of [390, 568, 1440]) {
	test(`search fits ${width}px in both themes and text directions`, async ({
		page,
	}) => {
		const height = width === 568 ? 320 : 844;
		await page.setViewportSize({ width, height });
		for (const direction of ["ltr", "rtl"] as const) {
			await mockDashboardSession(page, {
				capabilities: capabilityFlags,
				direction,
			});
			await page.goto("/dashboard/about");
			for (const theme of ["light", "dark"]) {
				await page.evaluate(
					(theme) =>
						document.documentElement.classList.toggle(
							"dark",
							theme === "dark"
						),
					theme
				);
				await openSearch(page);
				const panel = await page
					.locator(".dashboard-search-panel")
					.boundingBox();
				expect(panel).not.toBeNull();
				if (!panel) throw new Error("Search panel is missing");
				expect(panel.x).toBeGreaterThanOrEqual(0);
				expect(panel.x + panel.width).toBeLessThanOrEqual(width);
				expect(panel.y + panel.height).toBeLessThan(height);
				await expect(
					page.locator(".dashboard-search-panel")
				).toHaveAttribute("dir", direction);
				await page.keyboard.press("Escape");
			}
		}
	});
}

for (const [platform, shortcut] of [
	["macOS", "⌘K"],
	["Windows", "Ctrl+K"],
	["Linux", "Ctrl+K"],
] as const) {
	test(`the search field shows the ${platform} shortcut`, async ({
		page,
	}) => {
		await page.addInitScript(
			(platform) =>
				Object.defineProperty(navigator, "userAgentData", {
					value: { platform },
					configurable: true,
				}),
			platform
		);
		await page.goto("/dashboard/about");
		const input = await openSearch(page);
		await expect(input).toHaveAttribute("placeholder", "Search PeakURL…");
		await expect(page.locator(".dashboard-search-shortcut")).toHaveText(
			shortcut === "⌘K" ? "⌘K" : "CtrlK"
		);
		await expect(
			page.locator(".dashboard-search-shortcut")
		).toHaveAttribute("aria-label", shortcut);
	});
}

test("Quick access includes the sponsor page and opens it externally", async ({
	page,
	context,
}) => {
	const isSponsorUrl = (url: URL) =>
		url.origin === "https://peakurl.org" && url.pathname === "/sponsor";
	await context.route(isSponsorUrl, (route) =>
		route.fulfill({
			contentType: "text/html",
			body: "<title>Support PeakURL</title>",
		})
	);
	await page.goto("/dashboard/about");
	await openSearch(page);
	const sponsor = page.getByRole("option", { name: /Sponsor PeakURL/ });
	await expect(sponsor).toContainText("Opens in a new tab");
	const popupPromise = page.waitForEvent("popup");
	await sponsor.click();
	const popup = await popupPromise;
	await expect(popup).toHaveURL(isSponsorUrl);
	await expect(page).toHaveURL(/\/dashboard\/about$/);
	await expect(page.getByRole("dialog")).toHaveCount(0);
	await popup.close();
	const input = await openSearch(page);
	await input.fill("sponsor");
	await expect(sponsor).toBeVisible();
	await expect(
		page.getByRole("option", { name: /System Status/ })
	).toHaveCount(0);
	await input.fill("system");
	await expect(
		page
			.getByRole("option", { name: /System Status/ })
			.locator(".lucide-activity")
	).toBeVisible();
});

test("browser navigation dismisses search without reopening it on return", async ({
	page,
}) => {
	await page.goto("/dashboard/about");
	await page.getByRole("tab", { name: "Contributors", exact: true }).click();
	await openSearch(page);
	await page.goBack();
	await expect(page.getByRole("dialog")).toHaveCount(0);
	await page.goForward();
	await expect(page.getByRole("dialog")).toHaveCount(0);
});
