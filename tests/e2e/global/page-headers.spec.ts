import { test, expect } from "@playwright/test";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";

test.beforeEach(async ({ page }) => {
	// Keep layout checks independent of installation data and prevent real writes.
	await page.route("**/api/v1/**", (route) =>
		route.fulfill({ status: 503, json: { message: "Preview unavailable" } })
	);
	await mockDashboardSession(page, {
		capabilities: {
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
		},
	});
	await page.route("**/api/v1/system/status", (route) =>
		route.fulfill({
			json: { data: { summary: { overall: "ok" }, checks: [] } },
		})
	);
	await page.route("**/api/v1/system/notices", (route) =>
		route.fulfill({ json: { data: { items: [] } } })
	);
	await page.route("**/api/v1/system/general", (route) =>
		route.fulfill({
			json: {
				data: {
					canManageSiteSettings: true,
					siteName: "PeakURL",
					siteTagline: "Shorten, track, and own every link.",
					siteLanguage: "en_US",
					siteTimezone: "UTC",
				},
			},
		})
	);
});

const pages = [
	["/dashboard", "Dashboard"],
	["/dashboard/links", "All Links"],
	["/dashboard/plugins", "Plugins"],
	["/dashboard/users", "Users"],
	["/dashboard/activity", "Activity Log"],
	["/dashboard/tools/import", "Import"],
	["/dashboard/tools/export", "Export"],
	["/dashboard/tools/scheduled-jobs", "Scheduled Jobs"],
	["/dashboard/tools/system-status", "System Status"],
	["/dashboard/settings/general", "Settings"],
] as const;

for (const width of [1440, 390]) {
	test(`page headings align and actions fit at ${width}px`, async ({
		page,
	}) => {
		await page.setViewportSize({ width, height: 900 });
		let firstTitleTop: number | undefined;
		for (const [url, title] of pages) {
			await test.step(title, async () => {
				await page.goto(url);
				const heading = page.getByRole("heading", {
					name: title,
					exact: true,
					level: 1,
				});
				await expect(heading).toBeVisible();
				await expect(heading).toHaveCSS("font-size", "24px");
				const top = await heading.evaluate(
					(element) => element.getBoundingClientRect().top
				);
				firstTitleTop ??= top;
				expect(top).toBeCloseTo(firstTitleTop, 0);
				await expect(
					page.locator(".page-header-icon svg")
				).toBeVisible();
				expect(
					await page.evaluate(
						() => document.documentElement.scrollWidth <= innerWidth
					)
				).toBe(true);
			});
		}
	});

	test(`settings panels remain readable and controls reachable at ${width}px`, async ({
		page,
	}) => {
		await page.setViewportSize({ width, height: 900 });
		for (const tab of [
			"general",
			"security",
			"api",
			"integrations",
			"performance",
			"email",
			"location",
			"updates",
		]) {
			await test.step(tab, async () => {
				await page.goto(`/dashboard/settings/${tab}`);
				await expect(
					page.locator(".settings-section").first()
				).toBeVisible();
				const headings = page.locator(".settings-tab-page h2");
				await expect(headings.first()).toBeVisible();
				for (const heading of await headings.all()) {
					const expectedFontSize = await heading.evaluate(
						(element) => {
							if (element.closest(".settings-tab-header"))
								return "20px";
							if (
								element.closest(
									".settings-update-status-prominent"
								)
							)
								return "22px";
							return "18px";
						}
					);
					await expect(heading).toHaveCSS(
						"font-size",
						expectedFontSize
					);
				}
				expect(
					await page.evaluate(
						() => document.documentElement.scrollWidth <= innerWidth
					)
				).toBe(true);
				if (tab === "general") {
					await expect(
						page.getByRole("textbox", { name: "Username" })
					).toBeDisabled();
				}
				if (tab === "api") {
					await page
						.getByRole("button", {
							name: "Create New Key",
							exact: true,
						})
						.first()
						.click();
					await expect(
						page
							.getByRole("dialog")
							.getByRole("heading", { name: "Create API Key" })
					).toBeVisible();
					await page.keyboard.press("Escape");
					await expect(page.getByRole("dialog")).toHaveCount(0);
				}
			});
		}
	});
}

test("settings inputs and selects share sizing and custom values remain editable", async ({
	page,
}) => {
	await page.setViewportSize({ width: 1024, height: 900 });
	await page.goto("/dashboard/settings/general");
	const input = page.getByRole("textbox", {
		name: "First Name",
		exact: false,
	});
	await expect(input).toBeVisible();
	const appearance = await input.evaluate((element) => {
		const style = getComputedStyle(element);
		return {
			height: style.height,
			background: style.backgroundColor,
			radius: style.borderRadius,
			fontSize: style.fontSize,
		};
	});
	await page.goto("/dashboard/settings/performance");
	const ttl = page.getByRole("button", {
		name: "Redirect Cache TTL",
		exact: true,
	});
	await expect(ttl).toBeVisible();
	await expect(ttl).toHaveCSS("height", appearance.height);
	await expect(ttl).toHaveCSS("background-color", appearance.background);
	await expect(ttl).toHaveCSS("border-radius", appearance.radius);
	await expect(ttl).toHaveCSS("font-size", appearance.fontSize);
	await expect(ttl).toHaveAccessibleDescription(
		/cache active short URL|cache lifetime|redirect destinations/
	);
	await ttl.click();
	await page.getByRole("option", { name: /Custom/ }).click();
	const custom = page.getByRole("spinbutton", {
		name: "Custom Redirect TTL (seconds)",
	});
	await expect(custom).toBeVisible();
	await custom.fill("120");
	await expect(custom).toHaveValue("120");
});
