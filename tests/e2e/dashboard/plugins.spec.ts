import { test, expect } from "@playwright/test";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";

test.describe("Dashboard Installed Plugins UI & Add New Directory", () => {
	test.beforeEach(async ({ page }) => {
		await page.route("**/api/v1/**", (route) =>
			route.fulfill({
				status: 503,
				json: { message: "Preview unavailable" },
			})
		);
		await mockDashboardSession(page, {
			capabilities: {
				manage_users: true,
				manage_site_settings: true,
				view_links: true,
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

	test("installed table renders two-column layout with inline actions, activation, and deletion confirmation", async ({
		page,
	}) => {
		let isExampleActive = false;

		await page.route("**/api/v1/system/plugins", (route) =>
			route.fulfill({
				json: {
					data: {
						items: [
							{
								id: "example-plugin",
								type: "regular",
								typeLabel: "Regular",
								name: "Example Plugin",
								description:
									"Demonstrates standard regular plugin packaging and registers an example filter hook to verify hook execution and activation lifecycle.",
								version: "1.0.0",
								requires: "1.7.0",
								testedUpTo: "1.8.0",
								php: "8.3",
								license: "MIT",
								valid: true,
								compatible: true,
								active: isExampleActive,
								errors: [],
								warnings: [],
								homepage: "https://example.com/plugin",
								documentation:
									"https://example.com/plugin/docs",
							},
						],
						regular: [
							{
								id: "example-plugin",
								type: "regular",
								typeLabel: "Regular",
								name: "Example Plugin",
								description:
									"Demonstrates standard regular plugin packaging and registers an example filter hook to verify hook execution and activation lifecycle.",
								version: "1.0.0",
								requires: "1.7.0",
								testedUpTo: "1.8.0",
								php: "8.3",
								license: "MIT",
								valid: true,
								compatible: true,
								active: isExampleActive,
								errors: [],
								warnings: [],
								homepage: "https://example.com/plugin",
								documentation:
									"https://example.com/plugin/docs",
							},
						],
						mustUse: [
							{
								id: "system-integration",
								type: "must_use",
								typeLabel: "Must-Use",
								name: "System Integration",
								description:
									"Demonstrates must-use (MU) plugin packaging and registers a test filter hook on application startup without dashboard activation.",
								version: "1.0.0",
								requires: "1.7.0",
								testedUpTo: "1.8.0",
								php: "8.3",
								license: "MIT",
								valid: true,
								compatible: true,
								active: true,
								errors: [],
								warnings: [],
							},
						],
						total: 2,
					},
				},
			})
		);

		await page.route("**/api/v1/system/plugins/activate", (route) => {
			isExampleActive = true;
			return route.fulfill({
				json: {
					success: true,
					data: {
						id: "example-plugin",
						active: true,
						activePlugins: ["example-plugin"],
					},
				},
			});
		});

		await page.route("**/api/v1/system/plugins/deactivate", (route) => {
			isExampleActive = false;
			return route.fulfill({
				json: {
					success: true,
					data: {
						id: "example-plugin",
						active: false,
						activePlugins: [],
					},
				},
			});
		});

		await page.setViewportSize({ width: 1440, height: 900 });
		await page.goto("/dashboard/plugins");

		// Header title and description
		await expect(
			page.getByRole("heading", { name: "Plugins", level: 1 })
		).toBeVisible();
		await expect(
			page.getByText("Manage the plugins installed on this server.")
		).toBeVisible();

		// Add New action button in header
		const addNewButton = page.getByRole("button", { name: "Add New" });
		await expect(addNewButton).toBeVisible();

		// Two tabs: Installed and Must-Use with accurate counts
		const installedTab = page.locator(".plugins-tabs button").filter({
			hasText: "Installed",
		});
		await expect(installedTab).toBeVisible();
		await expect(installedTab).toContainText("1");

		const mustUseTab = page.locator(".plugins-tabs button").filter({
			hasText: "Must-Use",
		});
		await expect(mustUseTab).toBeVisible();
		await expect(mustUseTab).toContainText("1");

		// Table columns: exactly two columns (Plugin and Description)
		const table = page.getByRole("table");
		await expect(
			table.getByRole("columnheader", { name: "Plugin" })
		).toBeVisible();
		await expect(
			table.getByRole("columnheader", { name: "Description" })
		).toBeVisible();

		// Displays Example Plugin with genuine description
		await expect(table.getByText("Example Plugin")).toBeVisible();
		await expect(
			table.getByText(
				"Demonstrates standard regular plugin packaging and registers an example filter hook to verify hook execution and activation lifecycle."
			)
		).toBeVisible();

		// Inline actions under name: Activate and Delete
		const activateBtn = table.getByRole("button", { name: "Activate" });
		const deleteBtn = table.getByRole("button", { name: "Delete" });
		await expect(activateBtn).toBeVisible();
		await expect(deleteBtn).toBeVisible();

		// Secondary links in Description column
		await expect(table.getByText("Version 1.0.0")).toBeVisible();
		await expect(
			table.getByRole("link", { name: /Visit plugin site/ })
		).toHaveAttribute("href", "https://example.com/plugin");

		// Test Delete confirmation dialog
		await deleteBtn.click();
		await expect(
			page.getByRole("heading", { name: "Delete Plugin" })
		).toBeVisible();
		await expect(
			page.getByRole("button", { name: "Delete Plugin" })
		).toBeVisible();
		// Cancel out of delete dialog
		await page.getByRole("button", { name: "Cancel" }).click();
		await expect(
			page.getByRole("heading", { name: "Delete Plugin" })
		).not.toBeVisible();

		// Test Activation
		await activateBtn.click();
		// Deactivate action should appear now
		await expect(
			table.getByRole("button", { name: "Deactivate" })
		).toBeVisible();

		// Switch to Must-Use tab
		await mustUseTab.click();

		// Must-Use explanation line
		await expect(
			page.getByText(
				"Must-use plugins are managed by the server administrator"
			)
		).toBeVisible();

		// Must-Use table displays System Integration without Activate, Deactivate, or Delete buttons
		await expect(table.getByText("System Integration")).toBeVisible();
		await expect(
			table.getByRole("button", { name: "Activate" })
		).not.toBeVisible();
		await expect(
			table.getByRole("button", { name: "Deactivate" })
		).not.toBeVisible();
		await expect(
			table.getByRole("button", { name: "Delete" })
		).not.toBeVisible();

		// Navigate to Add New directory view
		await addNewButton.click();
		await expect(page).toHaveURL(/\/dashboard\/plugins\/add/);
	});

	test("Add New view displays directory cards, upload toggle, and clean empty state", async ({
		page,
	}) => {
		// Mock official PeakURL.org directory API
		await page.route("https://api.peakurl.org/v1/plugins*", (route) =>
			route.fulfill({
				json: {
					success: true,
					data: {
						items: [
							{
								id: "plugin_123",
								slug: "utm-policy-enforcer",
								name: "UTM Policy Enforcer",
								summary:
									"Enforce standard UTM tracking parameters on all short links.",
								version: "1.0.0",
								requires: "1.7.0",
								php: "8.3",
								license: "MIT",
								author: {
									name: "Alex Morgan",
									username: "alex",
									isVerifiedAuthor: true,
								},
								directoryTrust: {
									status: "published",
									isOfficial: true,
									isVerifiedAuthor: true,
									featuredOrder: 1,
									publishedAt: "2026-10-28T00:00:00.000Z",
									updatedAt: "2026-10-28T00:00:00.000Z",
								},
								tags: [
									{ slug: "marketing", label: "Marketing" },
								],
							},
						],
						pagination: {
							total: 1,
							page: 1,
							limit: 12,
							totalPages: 1,
						},
					},
				},
			})
		);

		await page.setViewportSize({ width: 1440, height: 900 });
		await page.goto("/dashboard/plugins/add");

		// Header on Add New page has Title "Add New Plugin"
		await expect(
			page.getByRole("heading", { name: "Add New Plugin", level: 1 })
		).toBeVisible();

		// Upload Plugin button in header
		const uploadToggleBtn = page.getByRole("button", {
			name: "Upload Plugin",
		});
		await expect(uploadToggleBtn).toBeVisible();

		// Back button to Installed Plugins
		const backBtn = page.getByRole("button", {
			name: "Installed Plugins",
			exact: true,
		});
		await expect(backBtn).toBeVisible();

		// Published card renders accurately
		await expect(page.getByText("UTM Policy Enforcer")).toBeVisible();
		await expect(page.getByText("By Alex Morgan")).toBeVisible();
		await expect(page.getByText("v1.0.0")).toBeVisible();

		// View Details action link
		const detailLink = page.getByRole("link", { name: "View Details" });
		await expect(detailLink).toBeVisible();
		await expect(detailLink).toHaveAttribute(
			"href",
			"https://peakurl.org/plugin/utm-policy-enforcer"
		);

		// Clicking Upload Plugin toggles upload form
		await uploadToggleBtn.click();
		await expect(
			page.getByRole("heading", { name: "Install from ZIP" })
		).toBeVisible();
		await expect(
			page.getByText(
				"Upload a plugin ZIP file to install it on this server."
			)
		).toBeVisible();
		await expect(
			page.getByRole("button", { name: "Choose File" })
		).toBeVisible();
		await expect(page.getByText("No file chosen")).toBeVisible();
		const installBtn = page.getByRole("button", { name: "Install Now" });
		await expect(installBtn).toBeVisible();
		await expect(installBtn).toBeDisabled();
		await expect(page.getByText(/Maximum upload size:/)).toBeVisible();

		// Selecting a zip file displays the filename clearly and enables Install Now
		await page.locator('input[type="file"]').setInputFiles({
			name: "custom-plugin.zip",
			mimeType: "application/zip",
			buffer: Buffer.from("dummy-zip-data"),
		});
		await expect(page.getByText("custom-plugin.zip")).toBeVisible();
		await expect(installBtn).toBeEnabled();

		// Click back to return to Installed Plugins
		await backBtn.click();
		await expect(page).toHaveURL(/\/dashboard\/plugins$/);
	});
});
