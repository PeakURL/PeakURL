import { test, expect, type Locator } from "@playwright/test";
import { mockDrawerPages, drawerScenarios } from "../fixtures/drawer.fixture";
import { getContrastRatio } from "../fixtures/theme.fixture";

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

async function expectBlueFocus(
	field: Locator,
	color: string,
	minimumBorderContrast = 1.2
) {
	await field.evaluate(async (element) => {
		if (element instanceof HTMLElement) element.blur();
		await Promise.all(
			element.getAnimations().map((animation) => animation.finished)
		);
	});
	const appearance = await field.evaluate((element) => {
		const style = getComputedStyle(element);
		return {
			border: style.borderColor,
			background: style.backgroundColor,
			text: style.color,
			readonly: element.hasAttribute("readonly"),
			name:
				element.getAttribute("aria-label") ||
				element.getAttribute("placeholder") ||
				element.getAttribute("id"),
		};
	});
	if (!appearance.readonly)
		expect(
			getContrastRatio(appearance.border, appearance.background),
			JSON.stringify(appearance)
		).toBeGreaterThanOrEqual(minimumBorderContrast);
	expect(
		getContrastRatio(appearance.text, appearance.background)
	).toBeGreaterThanOrEqual(4.5);
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
	test(`settings and Add User have visible unfocused fields and aligned navigation in ${theme} mode`, async ({
		page,
	}) => {
		await page.addInitScript(
			(theme) => localStorage.setItem("theme", theme),
			theme
		);
		await mockDrawerPages(page);
		await page.goto("/dashboard/settings/general");
		const header = page.locator(".dashboard-header");
		const sidebarHeader = page.locator(".dashboard-sidebar-header");
		const headerBounds = await header.boundingBox();
		const sidebarBounds = await sidebarHeader.boundingBox();
		if (!headerBounds || !sidebarBounds)
			throw new Error("Navigation header is missing");
		expect(headerBounds.y + headerBounds.height).toBe(
			sidebarBounds.y + sidebarBounds.height
		);
		if (theme === "light")
			await expect(
				page.getByLabel("First Name", { exact: false })
			).toHaveCSS(
				"border-color",
				await header.evaluate(
					(node) => getComputedStyle(node).borderBottomColor
				)
			);
		const divider = await header.evaluate(
			(node) => getComputedStyle(node).borderBottomColor
		);
		await expect(sidebarHeader).toHaveCSS("border-bottom-color", divider);
		const username = page.getByLabel("Username", { exact: false });
		await expect(username).toBeDisabled();
		await expect(username).toHaveCSS("opacity", "1");
		if (process.env.PEAKURL_UI_PREVIEW)
			await page.screenshot({
				path: `/tmp/peakurl-fields-settings-${theme}.png`,
			});
		for (const width of [1440, 320]) {
			await page.setViewportSize({ width, height: 1000 });
			await page.goto("/dashboard/users");
			await page
				.getByRole("button", { name: "Add User", exact: true })
				.click();
			const dialog = page.getByRole("dialog", {
				name: "Add User",
				exact: true,
			});
			await expect(
				dialog.locator(".users-page-dialog-panel")
			).toBeVisible();
			for (const field of await dialog
				.locator("input, .select-trigger")
				.all())
				await expectBlueFocus(
					field,
					theme === "dark"
						? "rgb(181, 200, 250)"
						: "rgb(92, 113, 159)",
					theme === "dark" ? 2.5 : 1.2
				);
			await dialog
				.getByRole("button", { name: "Show password" })
				.first()
				.click();
			await expect(
				dialog.getByRole("button", { name: "Hide password" }).first()
			).toBeVisible();
			await dialog
				.locator("input")
				.first()
				.evaluate((node) => node.blur());
			if (process.env.PEAKURL_UI_PREVIEW)
				await page.screenshot({
					path: `/tmp/peakurl-fields-add-user-${theme}-${width}.png`,
				});
			const bounds = await dialog
				.locator(".users-page-dialog-panel")
				.evaluate((node) => ({
					width: node.clientWidth,
					scroll: node.scrollWidth,
				}));
			expect(bounds.scroll).toBeLessThanOrEqual(bounds.width);
			await page.keyboard.press("Escape");
			await expect(dialog).toHaveCount(0);
		}
	});
	test(`public auth fields use the shared colors and single focus stroke in ${theme} mode`, async ({
		page,
	}) => {
		await page.addInitScript(
			(theme) => localStorage.setItem("theme", theme),
			theme
		);
		await mockDrawerPages(page);
		await page.route("**/api/v1/users/me", (route) =>
			route.fulfill({ status: 401, json: { message: "Not signed in" } })
		);
		await page.route(
			"**/api/v1/auth/reset-password/preview-token",
			(route) => route.fulfill({ json: { data: { valid: true } } })
		);
		for (const width of [1440, 320]) {
			await page.setViewportSize({ width, height: 1000 });
			for (const path of [
				"/login",
				"/forgot-password",
				"/reset-password/preview-token",
			]) {
				await page.goto(path);
				if (theme === "dark")
					await expect(page.locator("html")).toHaveClass(/dark/);
				const field = page.locator(".form-control-base").first();
				await expect(field).toBeVisible();
				for (const control of await page
					.locator(".form-control-base")
					.all()) {
					await expectBlueFocus(
						control,
						theme === "dark"
							? "rgb(181, 200, 250)"
							: "rgb(92, 113, 159)",
						theme === "dark" ? 2.5 : 1.2
					);
				}
				await field.evaluate((node) => node.blur());
				expect(
					await page.evaluate(
						() => document.documentElement.scrollWidth <= innerWidth
					)
				).toBe(true);
				if (process.env.PEAKURL_UI_PREVIEW)
					await page.screenshot({
						path: `/tmp/peakurl-sweep-auth-${path.split("/")[1]}-${theme}-${width}.png`,
					});
			}
		}
	});

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
					await expectBlueFocus(
						field,
						color,
						theme === "dark" ? 2.5 : 1.2
					);
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
				await expectBlueFocus(
					field,
					color,
					theme === "dark" ? 2.5 : 1.2
				);
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
