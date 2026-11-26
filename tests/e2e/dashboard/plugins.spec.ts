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
								pluginUri: "https://example.com/plugin",
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
								pluginUri: "https://example.com/plugin",
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

	test("Add New installs and activates a published plugin while still offering ZIP uploads", async ({
		page,
	}) => {
		const installedPlugin = {
			id: "utm-policy-enforcer",
			directorySlug: "utm-policy-enforcer-2",
			name: "UTM Policy Enforcer",
			type: "regular",
			typeLabel: "Regular",
			version: "1.0.0",
			valid: true,
			compatible: true,
			active: false,
			errors: [],
			warnings: [],
		};
		let installed = false;
		let attempts = 0;
		await page.route("**/api/v1/system/plugins", (route) => {
			const items = installed ? [installedPlugin] : [];
			return route.fulfill({
				json: {
					data: {
						items,
						regular: items,
						mustUse: [],
						total: items.length,
					},
				},
			});
		});
		await page.route("**/api/v1/system/plugins/install", (route) => {
			expect(route.request().method()).toBe("POST");
			expect(route.request().postDataJSON()).toEqual({
				slug: installedPlugin.directorySlug,
				version: installedPlugin.version,
			});
			attempts++;
			if (attempts === 1) {
				return route.fulfill({
					status: 502,
					json: {
						message: "The plugin directory could not be reached.",
					},
				});
			}
			installed = true;
			return route.fulfill({
				status: 201,
				json: { data: installedPlugin },
			});
		});
		await page.route("**/api/v1/system/plugins/activate", (route) => {
			expect(route.request().method()).toBe("POST");
			expect(route.request().postDataJSON()).toEqual({
				id: installedPlugin.id,
			});
			installedPlugin.active = true;
			return route.fulfill({
				json: {
					data: {
						id: installedPlugin.id,
						active: true,
						activePlugins: [installedPlugin.id],
					},
				},
			});
		});

		// Mock official PeakURL.org directory API
		await page.route("https://api.peakurl.org/v1/plugins*", (route) =>
			route.fulfill({
				json: {
					success: true,
					data: {
						items: [
							{
								id: "plugin_123",
								slug: installedPlugin.directorySlug,
								name: "UTM Policy Enforcer",
								summary:
									"Enforce standard UTM tracking parameters on all short links.",
								installationStats: {
									activeInstalls: 3,
									totalInstalls: 5,
								},
								downloadCount: 1_234,
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
								distribution: {
									downloadUrl:
										"/v1/plugins/utm-policy-enforcer-2/releases/1.0.0/download",
									sha256: "a".repeat(64),
								},
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
		const directoryCard = page.getByRole("article");
		await expect(
			directoryCard
				.getByText("Active installs", { exact: true })
				.locator("..")
				.locator("dd")
		).toHaveText("3");
		await expect(
			directoryCard
				.getByText("Downloads", { exact: true })
				.locator("..")
				.locator("dd")
		).toHaveText("1,234");

		// View Details action link
		const detailLink = page.getByRole("link", { name: "View Details" });
		await expect(detailLink).toBeVisible();
		await expect(detailLink).toHaveAttribute(
			"href",
			"https://peakurl.org/plugin/utm-policy-enforcer-2"
		);
		await expect(page.getByRole("link", { name: "Download" })).toHaveCount(
			0
		);

		const directoryGrid = page.locator(".plugins-directory-grid");
		await expect(directoryGrid).toHaveCSS(
			"grid-template-columns",
			/^\S+ \S+ \S+$/
		);
		await page.setViewportSize({ width: 1100, height: 900 });
		await expect(directoryGrid).toHaveCSS(
			"grid-template-columns",
			/^\S+ \S+$/
		);
		await page.setViewportSize({ width: 390, height: 844 });
		await expect(directoryGrid).toHaveCSS("grid-template-columns", /^\S+$/);
		await page.setViewportSize({ width: 1440, height: 900 });

		const directoryInstall = page.getByRole("button", {
			name: "Install Now",
		});
		await directoryInstall.click();
		await expect(
			page.locator(".notification-title").filter({
				hasText: /^The plugin directory could not be reached\.$/,
			})
		).toBeVisible();
		await directoryInstall.click();
		const activateButton = page.getByRole("button", {
			name: "Activate",
			exact: true,
		});
		await expect(activateButton).toBeEnabled();

		await page.reload();
		await activateButton.click();
		const activeButton = page.getByRole("button", {
			name: "Active",
			exact: true,
		});
		await expect(activeButton).toBeDisabled();
		await expect(page).toHaveURL(/\/dashboard\/plugins\/add$/);
		await page.reload();
		await expect(activeButton).toBeDisabled();

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
		await expect(page.getByText("Select plugin archive")).toBeVisible();
		await expect(
			page.getByText("Drag and drop a .zip file or click to browse")
		).toBeVisible();
		await expect(
			page.getByRole("button", { name: "Choose File" })
		).toBeVisible();
		const installBtn = page.getByRole("button", { name: "Install Now" });
		await expect(installBtn).toBeVisible();
		await expect(installBtn).toBeDisabled();
		await expect(page.getByText(/Maximum upload size:/)).toBeVisible();

		// Selecting a zip file displays the filename and size clearly and enables Install Now
		await page.locator('input[type="file"]').setInputFiles({
			name: "custom-plugin.zip",
			mimeType: "application/zip",
			buffer: Buffer.from("dummy-zip-data"),
		});
		await expect(page.getByText("custom-plugin.zip")).toBeVisible();
		await expect(
			page.getByRole("button", { name: "Replace" })
		).toBeVisible();
		const clearBtn = page.getByRole("button", {
			name: "Clear selected file",
		});
		await expect(clearBtn).toBeVisible();
		await expect(installBtn).toBeEnabled();

		// Clearing selected file resets the selection surface
		await clearBtn.click();
		await expect(page.getByText("Select plugin archive")).toBeVisible();
		await expect(installBtn).toBeDisabled();

		// Re-selecting file
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
		await expect(
			page.getByRole("button", { name: "Deactivate", exact: true })
		).toBeVisible();
	});

	test("displays first-use empty state with Add Plugin action and hides tab bar when no plugins installed", async ({
		page,
	}) => {
		await page.route("**/api/v1/system/plugins", (route) =>
			route.fulfill({
				json: {
					data: {
						items: [],
						regular: [],
						mustUse: [],
						total: 0,
					},
				},
			})
		);

		await page.goto("/dashboard/plugins");

		// Tab bar must NOT be visible
		await expect(page.getByRole("tablist")).not.toBeVisible();

		// Well-composed first-use empty state
		await expect(
			page.getByRole("heading", { name: "No plugins installed yet" })
		).toBeVisible();
		await expect(
			page.getByText(
				"Browse available plugins or upload a ZIP file to get started."
			)
		).toBeVisible();

		// Primary action "Add Plugin" linking to /dashboard/plugins/add
		const addBtn = page.getByRole("button", { name: "Add Plugin" });
		await expect(addBtn).toBeVisible();
		await addBtn.click();
		await expect(page).toHaveURL(/\/dashboard\/plugins\/add/);
	});

	test("renders regular plugins list directly without tab bar when only regular plugins exist", async ({
		page,
	}) => {
		await page.route("**/api/v1/system/plugins", (route) =>
			route.fulfill({
				json: {
					data: {
						items: [
							{
								id: "standalone-plugin",
								type: "regular",
								typeLabel: "Regular",
								name: "Standalone Plugin",
								description:
									"A single regular plugin without must-use plugins.",
								version: "1.0.0",
								requires: "1.7.0",
								testedUpTo: "1.8.0",
								php: "8.3",
								license: "MIT",
								valid: true,
								compatible: true,
								active: false,
								errors: [],
								warnings: [],
							},
						],
						regular: [
							{
								id: "standalone-plugin",
								type: "regular",
								typeLabel: "Regular",
								name: "Standalone Plugin",
								description:
									"A single regular plugin without must-use plugins.",
								version: "1.0.0",
								requires: "1.7.0",
								testedUpTo: "1.8.0",
								php: "8.3",
								license: "MIT",
								valid: true,
								compatible: true,
								active: false,
								errors: [],
								warnings: [],
							},
						],
						mustUse: [],
						total: 1,
					},
				},
			})
		);

		await page.goto("/dashboard/plugins");

		// Tab bar must NOT be visible
		await expect(page.getByRole("tablist")).not.toBeVisible();

		// Plugin row is rendered directly in table
		const table = page.getByRole("table");
		await expect(table.getByText("Standalone Plugin")).toBeVisible();
		await expect(
			table.getByRole("button", { name: "Activate" })
		).toBeVisible();
	});

	test("displays both tabs with Must-Use selected initially when only must-use plugins exist", async ({
		page,
	}) => {
		await page.route("**/api/v1/system/plugins", (route) =>
			route.fulfill({
				json: {
					data: {
						items: [
							{
								id: "system-core",
								type: "must_use",
								typeLabel: "Must-Use",
								name: "System Core Extension",
								description:
									"Only must-use plugins exist on this server.",
								version: "1.0.0",
								requires: "1.7.0",
								testedUpTo: "1.8.0",
								php: "8.3",
								license: "GPL-2.0",
								valid: true,
								compatible: true,
								active: true,
								errors: [],
								warnings: [],
							},
						],
						regular: [],
						mustUse: [
							{
								id: "system-core",
								type: "must_use",
								typeLabel: "Must-Use",
								name: "System Core Extension",
								description:
									"Only must-use plugins exist on this server.",
								version: "1.0.0",
								requires: "1.7.0",
								testedUpTo: "1.8.0",
								php: "8.3",
								license: "GPL-2.0",
								valid: true,
								compatible: true,
								active: true,
								errors: [],
								warnings: [],
							},
						],
						total: 1,
					},
				},
			})
		);

		await page.goto("/dashboard/plugins");

		// Tab bar must be visible
		const tablist = page.getByRole("tablist");
		await expect(tablist).toBeVisible();

		// Must-Use tab is selected initially
		const mustUseTab = page.getByRole("tab", { name: /Must-Use/ });
		await expect(mustUseTab).toHaveAttribute("aria-selected", "true");

		// Content is directly visible
		const table = page.getByRole("table");
		await expect(table.getByText("System Core Extension")).toBeVisible();
		await expect(
			page.getByText(
				"Must-use plugins are managed by the server administrator"
			)
		).toBeVisible();
		await expect(
			table.getByRole("button", { name: "Activate" })
		).not.toBeVisible();
	});
});
