import { test, expect, type Locator, type Page } from "@playwright/test";
import { mockDashboardBootstrap } from "../fixtures/dashboard.fixture";
import { mockDrawerPages } from "../fixtures/drawer.fixture";
import { getContrastRatio } from "../fixtures/theme.fixture";

async function expectReadableTableHeading(heading: Locator) {
	await expect(heading).toBeVisible();
	const colors = await heading.evaluate((element) => {
		const style = getComputedStyle(element);
		let background = style.backgroundColor;
		let parent = element.parentElement;
		while (background === "rgba(0, 0, 0, 0)" && parent) {
			background = getComputedStyle(parent).backgroundColor;
			parent = parent.parentElement;
		}
		return { text: style.color, background };
	});
	expect(
		getContrastRatio(colors.text, colors.background)
	).toBeGreaterThanOrEqual(4.5);
}

async function expectPageFits(page: Page) {
	expect(
		await page.evaluate(
			() => document.documentElement.scrollWidth <= innerWidth
		)
	).toBe(true);
}

for (const theme of ["light", "dark"] as const) {
	test(`tables, plugins and system status remain readable and fit in ${theme} mode`, async ({
		page,
	}) => {
		test.setTimeout(180_000);
		await page.addInitScript(
			(theme) => localStorage.setItem("theme", theme),
			theme
		);
		await mockDrawerPages(page);
		await page.route("**/api/v1/users", (route) =>
			route.fulfill({
				json: {
					data: [
						{
							id: "dashboard-preview",
							username: "preview",
							firstName: "Preview",
							lastName: "User",
							email: "preview@example.com",
							role: "admin",
							createdAt: "2026-10-01T12:00:00Z",
						},
					],
				},
			})
		);
		const plugin = {
			id: "fixture-plugin",
			type: "regular",
			typeLabel: "Regular",
			name: "Sample reporting plugin",
			version: "1.0.0",
			requires: "1.9.0",
			testedUpTo: "1.9.0",
			php: "8.3",
			license: "MIT",
			valid: true,
			compatible: true,
			active: true,
			errors: [],
			warnings: [],
		};
		await page.route("**/api/v1/system/plugins", (route) =>
			route.fulfill({
				json: {
					data: {
						items: [plugin],
						regular: [plugin],
						mustUse: [],
						total: 1,
					},
				},
			})
		);
		await page.route("**/api/v1/system/status", (route) =>
			route.fulfill({
				json: {
					data: {
						generatedAt: "2026-10-16T12:00:00Z",
						summary: { overall: "warning" },
						site: {
							name: "PeakURL",
							url: "https://peakurl.dev",
							timezone: "UTC",
						},
						checks: [
							{
								id: "database",
								status: "ok",
								label: "Database connected",
								message: "Database is reachable.",
							},
							{
								id: "cache",
								status: "warning",
								label: "Filesystem cache",
								message: "Redis is not configured.",
							},
						],
						database: {
							serverType: "MariaDB",
							version: "11.4",
							schemaVersion: 11,
							requiredSchemaVersion: 11,
						},
						cache: {
							activeDriver: "file",
							configuredDriver: "auto",
						},
						server: { phpVersion: "8.4", software: "Apache" },
					},
				},
			})
		);
		await page.route("**/api/v1/system/notices", (route) =>
			route.fulfill({ json: { data: { items: [] } } })
		);

		for (const width of [1440, 320]) {
			await page.setViewportSize({ width, height: 1000 });
			for (const [path, heading] of [
				["/dashboard/links", ".links-table-header-cell"],
				["/dashboard/users", ".users-page-table-heading"],
				["/dashboard/tools/scheduled-jobs", ".scheduled-jobs-table th"],
			] as const) {
				await page.goto(path, { waitUntil: "domcontentloaded" });
				const label = page
					.locator(heading)
					.filter({ hasText: /\S/ })
					.first();
				await expectReadableTableHeading(label);
				await expectPageFits(page);
				if (process.env.PEAKURL_UI_PREVIEW)
					await page.screenshot({
						path: `/tmp/peakurl-sweep-${path.split("/").pop()}-${theme}-${width}.png`,
					});
			}
			await page.goto("/dashboard/plugins", {
				waitUntil: "domcontentloaded",
			});
			await expect(
				page
					.getByText(plugin.name, { exact: true })
					.filter({ visible: true })
			).toBeVisible();
			await expect(
				page.getByRole("button", { name: "Deactivate", exact: true })
			).toBeVisible();
			if (width >= 768) {
				await expectReadableTableHeading(
					page.locator(".plugins-table-head-cell").first()
				);
			} else {
				await expect(page.locator(".plugins-table-grid")).toBeHidden();
			}
			await expectPageFits(page);
			if (process.env.PEAKURL_UI_PREVIEW)
				await page.screenshot({
					path: `/tmp/peakurl-sweep-plugins-${theme}-${width}.png`,
				});

			await page.goto("/dashboard/tools/system-status", {
				waitUntil: "domcontentloaded",
			});
			await expect(
				page.locator(".system-status-page-banner-warning")
			).toBeVisible();
			await page
				.getByRole("button", { name: "Info", exact: true })
				.click();
			await expectReadableTableHeading(
				page.locator(".system-status-page-info-heading").first()
			);
			await expectPageFits(page);
			if (process.env.PEAKURL_UI_PREVIEW)
				await page.screenshot({
					path: `/tmp/peakurl-sweep-system-status-${theme}-${width}.png`,
				});
		}
	});

	test(`connection errors match ${theme} mode, show debug diagnostics immediately and recover on retry`, async ({
		page,
	}) => {
		await page.addInitScript(
			(theme) => localStorage.setItem("theme", theme),
			theme
		);
		await mockDrawerPages(page, theme === "dark" ? "rtl" : "ltr");
		await mockDashboardBootstrap(page, { debug: true });
		let sessionRequests = 0;
		let available = false;
		const retry = Promise.withResolvers<void>();
		await page.route("**/api/v1/users/me", async (route) => {
			if (++sessionRequests === 2) await retry.promise;
			return route.fulfill(
				available
					? {
							json: {
								data: {
									id: "preview",
									username: "preview",
									firstName: "Preview",
									role: "admin",
									capabilities: { view_links: true },
								},
							},
						}
					: { status: 500, json: { message: "Service unavailable" } }
			);
		});
		await page.goto("/dashboard", { waitUntil: "domcontentloaded" });
		await expect(
			page.getByRole("heading", {
				name: "Unable to connect to the backend",
				exact: true,
			})
		).toBeVisible();
		await expect(page.locator("details.api-error-debug")).toHaveCount(0);
		await expect(
			page.getByRole("heading", { name: "Connection details" })
		).toBeVisible();
		for (const width of [1440, 320]) {
			await page.setViewportSize({ width, height: 1000 });
			await expect(
				page.getByText("HTTP 500", { exact: true })
			).toBeVisible();
			await expect(
				page.getByText("PHP runtime", { exact: true })
			).toBeVisible();
			await expectPageFits(page);
			if (process.env.PEAKURL_UI_PREVIEW)
				await page.screenshot({
					path: `/tmp/peakurl-sweep-connection-${theme}-${width}.png`,
					fullPage: true,
				});
		}
		try {
			await page
				.getByRole("button", { name: "Retry connection", exact: true })
				.click();
			const checking = page.getByRole("button", {
				name: "Checking connection",
				exact: true,
			});
			await expect(checking).toBeDisabled();
			await expect(
				page.getByText("HTTP 500", { exact: true })
			).toBeVisible();
			await expect(page.locator(".page-loader")).toHaveCount(0);
			await expect.poll(() => sessionRequests).toBe(2);
			await checking.evaluate((button: HTMLButtonElement) =>
				button.click()
			);
			expect(sessionRequests).toBe(2);
		} finally {
			retry.resolve();
		}
		await expect(
			page.getByRole("button", { name: "Retry connection", exact: true })
		).toBeEnabled();
		available = true;
		await page
			.getByRole("button", { name: "Retry connection", exact: true })
			.click();
		await expect(
			page.getByRole("heading", {
				name: /^Good (morning|afternoon|evening), Preview$/,
			})
		).toBeVisible();
	});
}
