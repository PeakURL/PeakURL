import { test, expect, type Page } from "@playwright/test";

import { mockDashboardSession } from "../fixtures/dashboard.fixture";

test.beforeEach(async ({ page }) => mockDashboardSession(page));

const releaseNotesUrl = "https://api.peakurl.org/v1/release-notes";
const currentRelease = {
	title: "Installed version notes",
	summary: "Changes included in this release.",
	highlights: ["Updated application identity"],
	releaseDate: "2026-10-01T00:00:00Z",
};

/** The running Vite build can differ from a recently edited .version file. */
async function releaseForRunningApp(page: Page) {
	const label = await page.locator(".about-version").innerText();
	const version = label.match(/^Version\s+(\S+)$/)?.[1];
	if (!version)
		throw new Error(
			"The About page did not identify the installed version."
		);
	return { ...currentRelease, version };
}

test("one logo serves the sidebar, About, and collapsed navigation", async ({
	page,
}) => {
	await page.goto("/dashboard/about");
	await expect(
		page.getByRole("heading", { name: "PeakURL", exact: true, level: 1 })
	).toBeVisible();
	await expect(page.locator(".dashboard-layout-wrapper")).toHaveCSS(
		"margin-inline-start",
		"240px"
	);
	await expect(
		page.getByText("Shorten, track, and own every link.", { exact: true })
	).toBeVisible();
	const sidebar = page.locator(".dashboard-sidebar-logo");
	const aboutMark = page.locator(".about-identity .logo-mark");
	await expect(sidebar).toHaveAccessibleName("PeakURL");
	await expect(sidebar.locator("img")).toHaveCSS("width", "40px");
	await expect(aboutMark).toHaveCSS("width", "96px");
	await expect(aboutMark).toHaveJSProperty("naturalWidth", 72);
	await expect(aboutMark).toHaveAttribute(
		"src",
		(await sidebar.locator("img").getAttribute("src")) ?? ""
	);
	await page
		.getByRole("button", { name: "Collapse Menu", exact: true })
		.click();
	await expect(page.locator(".dashboard-sidebar")).toHaveClass(
		/dashboard-sidebar-collapsed/
	);
	await expect(sidebar).toHaveAccessibleName("PeakURL");
	await expect(sidebar.locator("img")).toBeVisible();
});

test("About tabs preserve browser history and support keyboard navigation in both directions", async ({
	page,
}) => {
	await page.route(releaseNotesUrl, async (route) =>
		route.fulfill({
			json: { data: { releases: [await releaseForRunningApp(page)] } },
		})
	);
	await page.goto("/dashboard/about");
	const overview = page.getByRole("tab", { name: "Overview", exact: true });
	const notes = page.getByRole("tab", { name: "Release notes", exact: true });
	const contributors = page.getByRole("tab", {
		name: "Contributors",
		exact: true,
	});
	await overview.focus();
	await page.keyboard.press("ArrowRight");
	await expect(notes).toBeFocused();
	await expect(page.getByRole("tabpanel")).toHaveAccessibleName(
		"Release notes"
	);
	await expect(page).toHaveURL(/tab=releases/);
	await page.keyboard.press("End");
	await expect(contributors).toBeFocused();
	await expect(
		page.getByRole("heading", { name: "The people behind PeakURL" })
	).toBeVisible();
	await expect(
		page.getByRole("img", { name: "Abd Ur Rehman", exact: true })
	).toHaveAttribute(
		"src",
		"https://static.peakurl.org/assets/sponsors/author.svg"
	);
	await page.goBack();
	await expect(notes).toHaveAttribute("aria-selected", "true");
	await page.keyboard.press("Home");
	await expect(overview).toBeFocused();
	await page.evaluate(() => {
		document.documentElement.dir = "rtl";
	});
	await page.keyboard.press("ArrowLeft");
	await expect(notes).toBeFocused();
});

test("the logo palette stays legible in light and dark themes on a phone", async ({
	page,
}) => {
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto("/dashboard/about");
	await expect(page.locator(".about-identity .logo-mark")).toBeVisible();
	const content = page.locator(".about-content");
	const lightBackground = await content.evaluate(
		(element) => getComputedStyle(element).backgroundColor
	);
	await page.getByRole("button", { name: "Toggle theme" }).click();
	await expect(page.locator("html")).toHaveClass(/dark/);
	await expect(content).not.toHaveCSS("background-color", lightBackground);
	await page.getByRole("button", { name: "Open menu" }).click();
	await expect(page.locator(".dashboard-sidebar-logo img")).toBeVisible();
	await page
		.getByRole("complementary")
		.first()
		.getByRole("button", { name: "Close sidebar" })
		.click();
	expect(
		await page.evaluate(
			() => document.documentElement.scrollWidth <= innerWidth
		)
	).toBe(true);
	await page.getByRole("tab", { name: "Contributors", exact: true }).click();
	expect(
		await page.evaluate(
			() => document.documentElement.scrollWidth <= innerWidth
		)
	).toBe(true);
});

test("release notes load on demand and a failed request can be retried", async ({
	page,
}) => {
	let requests = 0;
	await page.route(releaseNotesUrl, async (route) => {
		requests += 1;
		return requests === 1
			? route.fulfill({ status: 503, json: { message: "Unavailable" } })
			: route.fulfill({
					json: {
						data: {
							releases: [
								{
									...currentRelease,
									version: "99.0.0",
									title: "Another version",
								},
								await releaseForRunningApp(page),
							],
						},
					},
				});
	});
	await page.goto("/dashboard/about");
	await page.getByRole("tab", { name: "Contributors", exact: true }).click();
	expect(requests).toBe(0);
	await page.getByRole("tab", { name: "Release notes", exact: true }).click();
	await expect(page.getByRole("alert")).toContainText(
		"Release notes could not be loaded."
	);
	await page.getByRole("button", { name: "Try again" }).click();
	await expect(
		page.getByRole("heading", { name: "Installed version notes" })
	).toBeVisible();
	await expect(
		page.getByText("Another version", { exact: true })
	).toHaveCount(0);
});

test("installation view gives setup actions without loading release notes", async ({
	page,
}) => {
	let requests = 0;
	await page.route(releaseNotesUrl, async (route) => {
		requests += 1;
		return route.fulfill({ json: { data: { releases: [] } } });
	});
	await page.goto("/dashboard/about?source=install");
	await expect(
		page.getByRole("heading", { name: "Your installation is ready." })
	).toBeVisible();
	await expect(
		page.getByRole("tab", { name: "Overview", exact: true })
	).toHaveAttribute("aria-selected", "true");
	await expect(
		page.getByRole("link", { name: "Create your first short link" })
	).toHaveAttribute("href", "/dashboard/links");
	await expect(
		page.getByRole("link", { name: "Review general settings" })
	).toHaveAttribute("href", "/dashboard/settings/general");
	expect(requests).toBe(0);
});

for (const source of ["update", "reinstall"]) {
	test(`${source} view shows the completion status and installed release`, async ({
		page,
	}) => {
		await page.route(releaseNotesUrl, async (route) =>
			route.fulfill({
				json: {
					data: { releases: [await releaseForRunningApp(page)] },
				},
			})
		);
		await page.goto(`/dashboard/about?source=${source}`);
		await expect(
			page.getByRole("heading", {
				name:
					source === "update"
						? "Update complete."
						: "Reinstallation complete.",
			})
		).toBeVisible();
		await expect(
			page.getByRole("heading", { name: "Installed version notes" })
		).toBeVisible();
		await expect(
			page.getByRole("tab", { name: "Release notes", exact: true })
		).toHaveAttribute("aria-selected", "true");
		await expect(
			page.getByRole("link", { name: "Go to Dashboard", exact: true })
		).toHaveAttribute("href", "/dashboard");
		await page.getByRole("tab", { name: "Overview", exact: true }).click();
		await expect(page).toHaveURL(
			new RegExp(`source=${source}&tab=overview`)
		);
	});
}

test("a missing installed release is an explicit empty state", async ({
	page,
}) => {
	await page.route(releaseNotesUrl, (route) =>
		route.fulfill({
			json: {
				data: {
					releases: [
						{
							...currentRelease,
							version: "99.0.0",
							title: "Another version",
						},
					],
				},
			},
		})
	);
	await page.goto("/dashboard/about?tab=releases");
	await expect(
		page.getByText("Release notes for this version are not available yet.")
	).toBeVisible();
	await expect(
		page.getByText("Another version", { exact: true })
	).toHaveCount(0);
});

test("Settings and main navigation share the same soft selection palette", async ({
	page,
}) => {
	await page.goto("/dashboard/settings/general");
	const navigation = page.locator(".dashboard-sidebar-link-active");
	const settings = page.locator(".settings-sidebar-link-active");
	await expect(settings).toHaveAttribute("aria-current", "page");
	for (const colors of [
		{ background: "rgb(233, 238, 249)", text: "rgb(43, 48, 64)" },
		{ background: "rgb(51, 61, 84)", text: "rgb(213, 223, 245)" },
	]) {
		await expect(navigation).toHaveCSS(
			"background-color",
			colors.background
		);
		await expect(navigation).toHaveCSS("color", colors.text);
		await expect(settings).toHaveCSS("background-color", colors.background);
		await expect(settings).toHaveCSS("color", colors.text);
		await expect(settings.locator("svg")).toHaveCSS("color", colors.text);
		if (colors.background === "rgb(233, 238, 249)") {
			await page.getByRole("button", { name: "Toggle theme" }).click();
		}
	}
});

test("About footer stays in normal document flow and honors reduced motion", async ({
	page,
}) => {
	await page.setViewportSize({ width: 1440, height: 1100 });
	await page.goto("/dashboard/about?tab=contributors");
	const footer = page.locator(".about-footer");
	await expect(footer).toContainText(
		`© 2025–${new Date().getFullYear()} PeakURL`
	);
	await expect(footer).toContainText("Made with");
	await expect(
		footer.getByRole("link", { name: "Abd Ur Rehman" })
	).toHaveAttribute("href", "https://go.peakurl.org/author");
	const heart = footer.getByRole("img", { name: "love", exact: true });
	await expect(heart).toHaveCSS("animation-name", "about-heartbeat");
	await expect(footer).toHaveCSS("position", "static");
	const contentBottom = await page
		.locator(".about-columns")
		.evaluate((element) => element.getBoundingClientRect().bottom);
	const footerTop = await footer.evaluate(
		(element) => element.getBoundingClientRect().top
	);
	expect(footerTop).toBeGreaterThanOrEqual(contentBottom);
	await expect
		.poll(() =>
			footer.evaluate((element) =>
				Math.abs(
					innerHeight - element.getBoundingClientRect().bottom - 24
				)
			)
		)
		.toBeLessThanOrEqual(2);

	await page.emulateMedia({ reducedMotion: "reduce" });
	await expect(heart).toHaveCSS("animation-name", "none");
	await expect(
		page.locator(".about-header").getByText("About", { exact: true })
	).toHaveCount(0);
});

test("the footer uses a single year in the founding year and stays below long content", async ({
	page,
}) => {
	await page.clock.setFixedTime(new Date("2025-06-01T12:00:00Z"));
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto("/dashboard/about");
	const footer = page.locator(".about-footer");
	await expect(footer).toContainText("© 2025 PeakURL");
	await expect(page.locator(".about-identity .logo-mark")).toHaveCSS(
		"width",
		"80px"
	);
	expect(
		await page.evaluate(() => {
			const footer = document.querySelector(".about-footer");
			const content = document.querySelector(".about-columns");
			return (
				footer &&
				content &&
				footer.getBoundingClientRect().top >=
					content.getBoundingClientRect().bottom
			);
		})
	).toBe(true);
	await footer.scrollIntoViewIfNeeded();
	await expect(
		footer.getByRole("img", { name: "love", exact: true })
	).toBeVisible();
	expect(
		await page.evaluate(
			() => document.documentElement.scrollWidth <= innerWidth
		)
	).toBe(true);
});

test("About and the app footer use the same installed runtime version", async ({
	page,
}) => {
	await page.goto("/dashboard/about");
	await expect(page.locator(".about-version")).toHaveText("Version 4.2.0");
	await page.goto("/dashboard/settings/general");
	await expect(
		page.locator(".dashboard-layout-footer-version-value")
	).toHaveText("4.2.0");
});

test("long About tabs expand while the product header, tabs, and resources stay visible", async ({
	page,
}) => {
	await page.setViewportSize({ width: 1440, height: 1000 });
	await page.route(releaseNotesUrl, (route) =>
		route.fulfill({
			json: {
				data: {
					releases: [
						{
							...currentRelease,
							version: "4.2.0",
							highlights: Array.from(
								{ length: 40 },
								(_, index) =>
									`Release improvement ${index + 1}: details about this change.`
							),
						},
					],
				},
			},
		})
	);
	await page.goto("/dashboard/about?tab=releases");
	const panel = page.getByRole("tabpanel");
	await expect(
		page.getByRole("heading", { name: "Installed version notes" })
	).toBeVisible();
	await page.evaluate(() => window.scrollTo(0, 100));
	const fixedPositions = () =>
		page.evaluate(() =>
			[".about-header", ".about-tabs", ".about-resources"].map(
				(selector) =>
					document.querySelector(selector)?.getBoundingClientRect()
						.top
			)
		);
	const before = await fixedPositions();
	await page.mouse.move(700, 500);
	await page.mouse.wheel(0, 800);
	await expect
		.poll(() => page.evaluate(() => window.scrollY))
		.toBeGreaterThan(100);
	expect(await fixedPositions()).toEqual(before);
	const footer = page.locator(".about-footer");
	await expect(footer).not.toBeInViewport();
	await footer.scrollIntoViewIfNeeded();
	await expect(footer).toBeInViewport();
	expect(
		await footer.evaluate((element) => element.getBoundingClientRect().top)
	).toBeGreaterThanOrEqual(
		await panel.evaluate(
			(element) => element.getBoundingClientRect().bottom
		)
	);
});
