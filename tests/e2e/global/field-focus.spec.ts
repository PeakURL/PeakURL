import { test, expect, type Locator } from "@playwright/test";
import { mockDrawerPages, drawerScenarios } from "../fixtures/drawer.fixture";

const settings = [
	"general",
	"security",
	"api",
	"integrations",
	"performance",
	"email",
	"location",
	"updates",
];
const paths = [
	"/dashboard",
	"/dashboard/links",
	"/dashboard/users",
	"/dashboard/plugins",
	"/dashboard/activity",
	"/dashboard/tools/import/paste",
	"/dashboard/tools/export",
	"/dashboard/tools/scheduled-jobs",
	"/dashboard/tools/system-status",
	...settings.map((tab) => `/dashboard/settings/${tab}`),
];

async function expectBlueFocus(field: Locator, color: string) {
	await field.focus();
	await expect(field).toBeFocused();
	await expect(field).toHaveCSS("border-color", color);
	await expect
		.poll(() =>
			field.evaluate((element) => getComputedStyle(element).boxShadow)
		)
		.toBe(`${color} 0px 0px 0px 1px inset`);
}

for (const theme of ["light", "dark"] as const) {
	test(`dashboard fields and drawer controls share the logo blue focus ring in ${theme} mode`, async ({
		page,
	}) => {
		await page.addInitScript(
			(theme) => localStorage.setItem("theme", theme),
			theme
		);
		await mockDrawerPages(page);
		const data = {
			"auth/security": { twoFactorEnabled: false, sessions: [] },
			"system/mail": {
				driver: "smtp",
				canManageFromDashboard: true,
				smtpHost: "smtp.example.com",
				smtpPort: 587,
				smtpAuth: true,
				smtpEncryption: "tls",
			},
			"system/cache": {
				enabled: true,
				activeDriver: "file",
				configuredDriver: "auto",
				defaultTtl: 3600,
				negativeTtl: 60,
			},
			"system/geoip": {
				locationAnalyticsReady: true,
				credentialsConfigured: false,
				canManageFromDashboard: true,
			},
			"system/update": {
				currentVersion: "1.7.1",
				latestVersion: "1.7.1",
				canApply: false,
				database: { currentVersion: 11, targetVersion: 11 },
			},
		};
		for (const [path, payload] of Object.entries(data))
			await page.route(`**/api/v1/${path}`, (route) =>
				route.fulfill({ json: { data: payload } })
			);
		const color =
			theme === "dark" ? "rgb(181, 200, 250)" : "rgb(92, 113, 159)";
		const fieldsSelector =
			"input:visible:not([disabled],[type=checkbox],[type=radio],[type=file],[type=hidden]), textarea:visible:not([disabled]), select:visible:not([disabled]), .select-trigger:visible:not([disabled])";
		let focusedCount = 0;
		for (const path of paths) {
			await test.step(path, async () => {
				await page.goto(path);
				await expect(page.locator("main")).toBeVisible();
				await expect(page.locator("main h1").first()).toBeVisible();
				if (path.startsWith("/dashboard/settings/"))
					await expect(
						page.locator(".settings-tab-page h2").first()
					).toBeVisible();
				for (const field of await page.locator(fieldsSelector).all()) {
					await expectBlueFocus(field, color);
					focusedCount++;
				}
			});
		}
		expect(focusedCount).toBeGreaterThan(25);
		for (const drawer of drawerScenarios) {
			await page.goto(drawer.path);
			await page.locator(drawer.trigger).first().click();
			const panel = page.locator(drawer.panel);
			await expect(panel).toBeVisible();
			for (const field of await panel.locator(fieldsSelector).all())
				await expectBlueFocus(field, color);
			await page.keyboard.press("Escape");
			await expect(panel).toHaveCount(0);
		}
		await page.goto("/dashboard/settings/general");
		const name = page.getByLabel("First Name", { exact: false });
		await expectBlueFocus(name, color);
		if (process.env.PEAKURL_UI_PREVIEW)
			await page
				.locator(".form-field")
				.filter({ has: name })
				.screenshot({ path: `/tmp/peakurl-single-focus-${theme}.png` });
		await name.evaluate((element) =>
			element.setAttribute("aria-invalid", "true")
		);
		await name.focus();
		await expect(name).not.toHaveCSS("border-color", color);
		await expect
			.poll(() =>
				name.evaluate((element) => getComputedStyle(element).boxShadow)
			)
			.toContain("inset");
		await page
			.getByRole("button", { name: "Search the dashboard", exact: true })
			.click();
		await expect(page.locator(".dashboard-search-field")).toHaveCSS(
			"box-shadow",
			"none"
		);
		const searchInput = page.getByRole("combobox", {
			name: "Search links, people, and pages",
		});
		await expect(searchInput).toBeFocused();
		await expect(searchInput).toHaveCSS("box-shadow", "none");
		await expect(searchInput).toHaveCSS("outline-style", "none");
	});
}
