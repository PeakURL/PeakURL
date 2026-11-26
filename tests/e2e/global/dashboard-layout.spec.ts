import { test, expect } from "@playwright/test";

import { mockDashboardSession } from "../fixtures/dashboard.fixture";

const adminCapabilities = {
	view_links: true,
	view_analytics: true,
	manage_users: true,
	manage_site_settings: true,
	manage_api_keys: true,
	manage_integrations: true,
	manage_performance: true,
	manage_mail_delivery: true,
	manage_location_data: true,
	manage_updates: true,
};

test.describe("Stage 3.3 Dashboard Layout, Breadcrumbs & Overview Greeting", () => {
	test("renders Petrol & Jade shell hierarchy in light and dark modes with crisp header controls", async ({
		page,
	}) => {
		await mockDashboardSession(page, { capabilities: adminCapabilities });
		await page.setViewportSize({ width: 1440, height: 900 });
		await page.goto("/dashboard");

		const sidebar = page.locator(".dashboard-sidebar");
		const layout = page.locator(".dashboard-layout");
		const activeBackground = page.locator(".dashboard-sidebar-indicator");

		await expect(sidebar).toHaveCSS(
			"background-color",
			"rgb(212, 229, 224)"
		);
		await expect(layout).toHaveCSS(
			"background-color",
			"rgb(234, 240, 238)"
		);
		await expect(activeBackground).toHaveCSS(
			"background-color",
			"rgb(255, 255, 255)"
		);

		await page.getByRole("button", { name: "Toggle theme" }).click();
		await expect(page.locator("html")).toHaveClass(/dark/);
		await expect(sidebar).toHaveCSS("background-color", "rgb(17, 35, 32)");
		await expect(layout).toHaveCSS("background-color", "rgb(14, 19, 18)");
		await expect(activeBackground).toHaveCSS(
			"background-color",
			"rgb(37, 70, 65)"
		);
	});

	test("renders accurate dynamic breadcrumbs with permission-aware ancestor links", async ({
		page,
	}) => {
		await mockDashboardSession(page, { capabilities: adminCapabilities });
		await page.setViewportSize({ width: 1440, height: 900 });

		await page.goto("/dashboard/tools/import/file");
		const nav = page.getByRole("navigation", { name: "Breadcrumb" });
		await expect(nav).toContainText("PeakURL");
		await expect(nav).toContainText("Tools");
		await expect(nav).toContainText("Import");
		await expect(
			nav.getByRole("link", { name: "PeakURL" })
		).toHaveAttribute("href", "/dashboard");
		await expect(nav.getByRole("link", { name: "Tools" })).toHaveCount(0);
		await expect(nav.locator("[aria-current='page']")).toHaveText("Import");

		await page.goto("/dashboard/settings/security");
		await expect(
			nav.getByRole("link", { name: "Settings" })
		).toHaveAttribute("href", "/dashboard/settings/general");
		await expect(nav.locator("[aria-current='page']")).toHaveText(
			"Security"
		);
	});

	test("renders personalised overview greeting and concise subtitle without modifying other page headers", async ({
		page,
	}) => {
		await mockDashboardSession(page, { capabilities: adminCapabilities });
		await page.setViewportSize({ width: 1440, height: 900 });
		await page.goto("/dashboard");

		await expect(
			page.getByRole("heading", {
				name: /^Good (morning|afternoon|evening), Preview$/,
				level: 1,
			})
		).toBeVisible();
		await expect(
			page.getByText("Here's an overview of your link activity.")
		).toBeVisible();

		await page.goto("/dashboard/links");
		await expect(
			page.getByRole("heading", {
				name: "All Links",
				exact: true,
				level: 1,
			})
		).toBeVisible();
	});

	test("places desktop sidebar collapse button in brand header, avoids collapsed overlap, and persists across reload", async ({
		page,
	}) => {
		await mockDashboardSession(page, { capabilities: adminCapabilities });
		await page.setViewportSize({ width: 1440, height: 900 });
		await page.goto("/dashboard");

		const sidebarHeader = page.locator(".dashboard-sidebar-header");
		const sidebarNav = page.locator(".dashboard-sidebar-nav");
		const collapseBtn = sidebarHeader.getByRole("button", {
			name: "Collapse sidebar",
		});

		await expect(collapseBtn).toBeVisible();
		await expect(collapseBtn).toHaveAttribute("aria-expanded", "true");
		await expect(
			sidebarNav.getByRole("button", {
				name: /(Collapse|Expand) (Menu|sidebar)/i,
			})
		).toHaveCount(0);

		// Keyboard activation collapses sidebar
		await collapseBtn.focus();
		await page.keyboard.press("Enter");

		const sidebar = page.locator(".dashboard-sidebar");
		await expect(sidebar).toHaveClass(/dashboard-sidebar-collapsed/);
		const expandBtn = sidebarHeader.getByRole("button", {
			name: "Expand sidebar",
		});
		await expect(expandBtn).toBeVisible();
		await expect(expandBtn).toHaveAttribute("aria-expanded", "false");

		await expect(sidebar).toHaveCSS("width", "72px");

		// Brand logo mark and expand button remain visible and non-overlapping in 72px rail
		const logoMarkRect = await sidebarHeader
			.locator(".logo-mark")
			.evaluate((el) => el.getBoundingClientRect());
		const expandRect = await expandBtn.evaluate((el) =>
			el.getBoundingClientRect()
		);
		expect(expandRect.width).toBeGreaterThanOrEqual(24);
		expect(expandRect.height).toBeGreaterThanOrEqual(24);
		const overlaps = !(
			expandRect.right <= logoMarkRect.left ||
			expandRect.left >= logoMarkRect.right ||
			expandRect.bottom <= logoMarkRect.top ||
			expandRect.top >= logoMarkRect.bottom
		);
		expect(overlaps).toBe(false);

		// Reload preserves collapsed state
		await page.reload();
		await expect(sidebar).toHaveClass(/dashboard-sidebar-collapsed/);
		await expect(
			sidebarHeader.getByRole("button", { name: "Expand sidebar" })
		).toBeVisible();

		// Re-expand cleanly with Spacebar
		const reloadedExpand = sidebarHeader.getByRole("button", {
			name: "Expand sidebar",
		});
		await reloadedExpand.focus();
		await page.keyboard.press("Space");
		await expect(sidebar).not.toHaveClass(/dashboard-sidebar-collapsed/);
	});

	test("hides desktop collapse button inside mobile drawer and emits light/dark SVG favicon tags", async ({
		page,
	}) => {
		await mockDashboardSession(page, { capabilities: adminCapabilities });
		await page.setViewportSize({ width: 390, height: 844 });
		await page.goto("/dashboard");

		const svgSelector =
			'head link[data-peakurl-favicon="1"][type="image/svg+xml"]';
		await expect(page.locator(svgSelector)).toHaveCount(3);
		const svgLinks = await page.locator(svgSelector).evaluateAll((nodes) =>
			nodes.map((node) => ({
				href: node.getAttribute("href"),
				media: node.getAttribute("media"),
			}))
		);
		expect(svgLinks).toEqual([
			{ href: expect.stringContaining("/favicon.svg"), media: null },
			{
				href: expect.stringContaining("/favicon-light.svg"),
				media: "(prefers-color-scheme: light)",
			},
			{
				href: expect.stringContaining("/favicon-dark.svg"),
				media: "(prefers-color-scheme: dark)",
			},
		]);

		await page.getByRole("button", { name: "Open menu" }).click();
		await expect(
			page.locator(".dashboard-sidebar-header .dashboard-sidebar-close")
		).toBeVisible();
		await expect(
			page.locator(
				".dashboard-sidebar-header .dashboard-sidebar-collapse-button"
			)
		).toBeHidden();
	});

	test("maintains zero horizontal overflow across responsive viewports and RTL mode", async ({
		page,
	}) => {
		for (const direction of ["ltr", "rtl"] as const) {
			await mockDashboardSession(page, {
				capabilities: adminCapabilities,
				direction,
			});

			for (const width of [320, 390, 768, 1024, 1440]) {
				await page.setViewportSize({ width, height: 900 });
				await page.goto("/dashboard/settings/security");
				await expect(
					page.getByRole("navigation", { name: "Breadcrumb" })
				).toBeVisible();
				expect(
					await page.evaluate(
						() => document.documentElement.scrollWidth <= innerWidth
					)
				).toBe(true);
			}
		}
	});
});

test("rapid sidebar reversals settle alongside the selected nested route", async ({
	page,
}) => {
	await mockDashboardSession(page, {
		capabilities: adminCapabilities,
		direction: "rtl",
	});
	await page.setViewportSize({ width: 1440, height: 900 });
	await page.goto("/dashboard/tools/import/paste");

	const navigation = page.getByRole("navigation", { name: "Dashboard" });
	const tools = navigation.getByRole("button", { name: "Tools" });
	const indicator = navigation.locator(".dashboard-sidebar-indicator");
	const selected = navigation.locator("[data-sidebar-indicator-target]");
	await expect(selected).toHaveText("Import");
	const collapse = page.locator(".dashboard-sidebar-collapse-button");
	await collapse.evaluate((button: HTMLButtonElement) => {
		button.click();
		requestAnimationFrame(() => {
			button.click();
			requestAnimationFrame(() => button.click());
		});
	});
	await expect(collapse).toHaveAccessibleName("Expand sidebar");
	await expect(page.locator(".dashboard-sidebar")).toHaveCSS("width", "72px");
	await expect(selected).toHaveText("Tools");

	await tools.click();
	await expect(tools).toHaveAttribute("aria-expanded", "true");
	await navigation.getByRole("link", { name: "Export", exact: true }).click();
	await expect(page).toHaveURL(/\/tools\/export$/);
	await expect(tools).toHaveAttribute("aria-expanded", "false");
	await expect(selected).toHaveText("Tools");

	await collapse.click();
	await expect(page.locator(".dashboard-sidebar")).toHaveCSS(
		"width",
		"240px"
	);
	await tools.click();
	await expect(selected).toHaveText("Export");
	await expect
		.poll(async () => {
			const [background, target] = await Promise.all([
				indicator.boundingBox(),
				selected.boundingBox(),
			]);
			return (
				background &&
				target &&
				Math.max(
					Math.abs(background.x - target.x),
					Math.abs(background.y - target.y),
					Math.abs(background.width - target.width),
					Math.abs(background.height - target.height)
				)
			);
		})
		.toBeLessThan(1);
});

test("route motion keeps shared UI mounted and uses the final state with reduced motion", async ({
	page,
}) => {
	await page.emulateMedia({ reducedMotion: "reduce" });
	await mockDashboardSession(page, { capabilities: adminCapabilities });
	await page.setViewportSize({ width: 1440, height: 900 });
	let generalRequests = 0;
	await page.route("**/api/v1/system/general", (route) => {
		generalRequests++;
		return route.fulfill({
			json: {
				data: { canManageSiteSettings: true, siteName: "PeakURL" },
			},
		});
	});
	await page.goto("/dashboard/settings/general");
	await expect(
		page.getByRole("heading", { name: "Profile Information" })
	).toBeVisible();
	const header = page.locator(".dashboard-header");
	await header.evaluate((node) =>
		node.setAttribute("data-test-persistent", "yes")
	);
	const sidebar = page.locator(".dashboard-sidebar");
	await sidebar.evaluate((node) =>
		node.setAttribute("data-test-persistent", "yes")
	);

	const navigation = page.getByRole("navigation", { name: "Settings" });
	await navigation.getByRole("link", { name: "Security" }).click();
	await expect(
		page.getByRole("heading", { name: "Security", level: 1 })
	).toBeVisible();
	await page.goBack();
	await expect(
		page.getByRole("heading", { name: "Profile Information" })
	).toBeVisible();
	await expect(header).toHaveAttribute("data-test-persistent", "yes");
	await expect(sidebar).toHaveAttribute("data-test-persistent", "yes");
	await expect(page.locator(".settings-page-header")).toHaveCSS(
		"transform",
		"none"
	);
	await expect(page.locator(".settings-page-header")).toHaveCSS(
		"opacity",
		"1"
	);
	await expect(page.locator(".dashboard-layout")).toHaveCSS(
		"transition-duration",
		"0s"
	);
	expect(generalRequests).toBe(1);
});

test("route entrances leave the dashboard wrapper and fixed overlay positioning outside the animation", async ({
	page,
}) => {
	await mockDashboardSession(page, { capabilities: adminCapabilities });
	await page.goto("/dashboard/settings/general");
	const sidebar = page.getByRole("navigation", { name: "Dashboard" });

	await page.locator(".dashboard-page-content").evaluate((content) => {
		const watch = new MutationObserver((records) => {
			if (
				records.some(({ oldValue }) =>
					oldValue?.includes("transform")
				) ||
				(content as HTMLElement).style.transform
			) {
				content.setAttribute("data-test-animated-wrapper", "true");
			}
		});
		watch.observe(content, {
			attributeFilter: ["style"],
			attributeOldValue: true,
		});
	});

	await sidebar.getByRole("link", { name: "Links", exact: true }).click();
	await expect(
		page.getByRole("heading", { name: "All Links", level: 1 })
	).toBeVisible();
	await expect(page.locator(".dashboard-page-content")).not.toHaveAttribute(
		"data-test-animated-wrapper",
		"true"
	);

	await sidebar.getByRole("link", { name: "Settings", exact: true }).click();
	await expect(
		page.getByRole("heading", { name: "General", level: 1 })
	).toBeVisible();
	await page.emulateMedia({ reducedMotion: "reduce" });
	await expect(
		page.locator(".settings-page-header .page-header-text")
	).toHaveCSS("transform", "none");
});

test("a late plugin destination moves the shared indicator to the active submenu link", async ({
	page,
}) => {
	await mockDashboardSession(page, {
		capabilities: { ...adminCapabilities, manage_updates: true },
	});
	let releaseResponse: () => void = () => {};
	const responseReady = new Promise<void>((resolve) => {
		releaseResponse = resolve;
	});
	await page.route("**/api/v1/system/plugins/extensions", async (route) => {
		await responseReady;
		await route.fulfill({
			json: {
				data: {
					pages: [
						{
							pluginSlug: "sample",
							pageId: "status",
							title: "Sample Status",
							menuTitle: "Sample Status",
							capability: "manage_updates",
							sections: [],
							actions: [],
						},
					],
					uiExtensions: [],
				},
			},
		});
	});
	await page.goto("/dashboard/plugins/page/sample/status");
	const sidebar = page.getByRole("navigation", { name: "Dashboard" });
	await expect(
		sidebar.getByRole("link", { name: "Plugins Preview" })
	).toBeVisible();

	releaseResponse();
	const active = sidebar.getByRole("link", { name: "Sample Status" });
	await expect(active).toHaveAttribute("aria-current", "page");
	const background = sidebar.locator(".dashboard-sidebar-indicator");
	await expect
		.poll(async () => {
			const linkBox = await active.boundingBox();
			const markerBox = await background.boundingBox();
			if (!linkBox || !markerBox) return Infinity;
			return Math.max(
				Math.abs(linkBox.x - markerBox.x),
				Math.abs(linkBox.y - markerBox.y),
				Math.abs(linkBox.width - markerBox.width),
				Math.abs(linkBox.height - markerBox.height)
			);
		})
		.toBeLessThan(1);
	await expect(
		sidebar.getByRole("link", { name: "Installed Plugins" })
	).not.toHaveAttribute("aria-current", "page");
});

for (const direction of ["ltr", "rtl"] as const) {
	test(`the mobile toolbar keeps scroll targets visible and the drawer manages focus in ${direction}`, async ({
		page,
		browserName,
	}) => {
		await page.setViewportSize({ width: 390, height: 760 });
		await mockDashboardSession(page, {
			direction,
			capabilities: adminCapabilities,
		});
		await page.goto("/dashboard", { waitUntil: "domcontentloaded" });
		const greeting = page.getByRole("heading", { level: 1 });
		await expect(greeting).toHaveText(
			/^Good (morning|afternoon|evening), Preview$/
		);
		const toolbar = page.locator(".dashboard-header");
		const scrollPosition = () => page.evaluate(() => scrollY);
		const expectBelowToolbar = async (element: typeof greeting) => {
			await expect
				.poll(async () => {
					const bounds = await element.boundingBox();
					const header = await toolbar.boundingBox();
					return (
						!!bounds &&
						!!header &&
						bounds.y >= header.y + header.height - 1
					);
				})
				.toBe(true);
		};
		await expectBelowToolbar(greeting);
		await expect.poll(scrollPosition).toBe(0);

		const skip = page.getByRole("link", { name: "Skip to content" });
		// Safari's default keyboard settings use Option-Tab to include links.
		const nextControl = browserName === "webkit" ? "Alt+Tab" : "Tab";
		await page.keyboard.press(nextControl);
		await expect(skip).toBeFocused();
		await page.keyboard.press("Enter");
		await expect(page.getByRole("main")).toBeFocused();

		const range = page.getByRole("button", {
			name: "Dashboard time range",
		});
		await range.evaluate((target) =>
			target.scrollIntoView({ block: "start", behavior: "instant" })
		);
		await expectBelowToolbar(range);
		const scrolled = await scrollPosition();
		expect(scrolled).toBeGreaterThan(0);
		// The sticky button is already visible. Send a pointer click without Playwright scrolling it into view.
		const themeToggle = await page
			.getByRole("button", { name: "Toggle theme" })
			.boundingBox();
		expect(themeToggle).not.toBeNull();
		if (!themeToggle) throw new Error("The theme toggle is missing");
		await page.mouse.click(
			themeToggle.x + themeToggle.width / 2,
			themeToggle.y + themeToggle.height / 2
		);
		await expect.poll(scrollPosition).toBe(scrolled);
		await expect(toolbar).toHaveCSS("background-color", "rgb(14, 19, 18)");

		const open = page.getByRole("button", { name: "Open menu" });
		await open.evaluate((target) => target.focus({ preventScroll: true }));
		await page.keyboard.press("Enter");
		const drawer = page.getByRole("dialog", { name: "Dashboard" });
		await expect(
			drawer.getByRole("button", { name: "Close sidebar" })
		).toBeFocused();
		await expect
			.poll(() =>
				page
					.locator(".dashboard-layout-wrapper")
					.evaluate((element) => Boolean(element.closest("[inert]")))
			)
			.toBe(true);
		await expect(page.locator("html")).toHaveCSS("overflow-y", "hidden");
		for (let key = 0; key < 12; key++) {
			await page.keyboard.press(nextControl);
			await expect
				.poll(() =>
					drawer.evaluate((element) =>
						element.contains(document.activeElement)
					)
				)
				.toBe(true);
		}
		await page.keyboard.press("Escape");
		await expect(drawer).toHaveCount(0);
		await expect(open).toBeFocused();
		await expect.poll(scrollPosition).toBe(scrolled);

		await open.click();
		await drawer
			.getByRole("link", { name: "Settings", exact: true })
			.click();
		await expect(
			page.getByRole("heading", { level: 1, name: "General" })
		).toBeVisible();
		await expect.poll(scrollPosition).toBe(0);
		const settings = page.getByRole("navigation", { name: "Settings" });
		await expectBelowToolbar(settings);
		await expect(
			settings.getByRole("link", { name: "General" })
		).toHaveAttribute("aria-current", "page");
	});
}
