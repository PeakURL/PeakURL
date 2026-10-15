import { test, expect } from "@playwright/test";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";

test.beforeEach(async ({ page }) => {
	await page.route("**/api/v1/**", (route) =>
		route.fulfill({ status: 503, json: { message: "Preview unavailable" } })
	);
	await mockDashboardSession(page, {
		capabilities: {
			view_links: true,
			manage_site_settings: true,
			manage_updates: true,
			manage_api_keys: true,
			manage_integrations: true,
			manage_performance: true,
			manage_mail_delivery: true,
			manage_location_data: true,
		},
	});
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

test("every Settings pane uses the available width on a large display", async ({
	page,
}) => {
	await page.setViewportSize({ width: 1920, height: 1000 });
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
			await expect(page).toHaveURL(new RegExp(`/settings/${tab}$`));
			await expect(
				page.locator(".settings-section").first()
			).toBeVisible();
			const grid = await page
				.locator(".settings-layout-grid")
				.boundingBox();
			const pane = await page.locator(".settings-tab-page").boundingBox();
			if (!grid || !pane) throw new Error("Settings content is missing");
			expect(pane.x + pane.width).toBeCloseTo(grid.x + grid.width, 0);
		});
	}
});

for (const width of [1440, 390]) {
	test(`branding previews can be selected, cleared, and selected again at ${width}px`, async ({
		page,
	}) => {
		await page.setViewportSize({ width, height: 900 });
		await page.goto("/dashboard/settings/general");
		await expect(
			page.getByRole("heading", { name: "Branding", exact: true })
		).toBeVisible();
		const uploads = page.locator(".settings-image-upload");
		const first = await uploads.first().boundingBox();
		const second = await uploads.last().boundingBox();
		if (!first || !second) throw new Error("Branding options are missing");
		const socialPreview = await uploads
			.last()
			.locator(".settings-image-preview")
			.boundingBox();
		if (!socialPreview) throw new Error("Social preview is missing");
		expect(socialPreview.width).toBeGreaterThanOrEqual(160);
		expect(socialPreview.width / socialPreview.height).toBeCloseTo(
			1200 / 630,
			1
		);
		expect(second.y).toBeGreaterThanOrEqual(first.y + first.height);
		expect(socialPreview.width).toBeLessThanOrEqual(448);
		if (width === 1440) expect(socialPreview.width).toBeGreaterThan(400);
		const faviconFrame = await uploads
			.first()
			.locator(".settings-image-preview")
			.boundingBox();
		if (!faviconFrame) throw new Error("Favicon frame is missing");
		expect(faviconFrame.height).toBeLessThanOrEqual(120);
		const png = await page.evaluate(() => {
			const canvas = document.createElement("canvas");
			canvas.width = canvas.height = 512;
			const context = canvas.getContext("2d");
			if (!context) throw new Error("Image preview canvas unavailable");
			context.fillStyle = "#2b3040";
			context.fillRect(0, 0, 512, 512);
			return canvas.toDataURL("image/png").split(",")[1];
		});
		const image = {
			name: "peakurl-preview.png",
			mimeType: "image/png",
			buffer: Buffer.from(png, "base64"),
		};
		for (const [title, choose, preview, remove] of [
			[
				"Site Favicon",
				"Choose Favicon",
				"Current favicon preview",
				"Remove Favicon",
			],
			[
				"Social Preview",
				"Choose Preview Image",
				"Default social preview image",
				"Remove Preview Image",
			],
		]) {
			const input = page.getByLabel(title, { exact: true });
			const chooserPromise = page.waitForEvent("filechooser");
			await page
				.getByRole("button", { name: choose, exact: true })
				.click();
			await (await chooserPromise).setFiles(image);
			await expect(
				page.getByRole("img", { name: preview, exact: true })
			).toBeVisible();
			const removeButton = page.getByRole("button", {
				name: remove,
				exact: true,
			});
			await expect(removeButton).toHaveText("");
			await expect(
				page.getByRole("button", {
					name:
						title === "Site Favicon"
							? "Replace Favicon"
							: "Replace Preview Image",
					exact: true,
				})
			).toBeVisible();
			await removeButton.click();
			await expect(
				page.getByRole("img", { name: preview, exact: true })
			).toHaveCount(0);
			expect(await input.inputValue()).toBe("");
			await input.setInputFiles(image);
			await expect(
				page.getByRole("img", { name: preview, exact: true })
			).toBeVisible();
		}
		expect(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= innerWidth
			)
		).toBe(true);
	});

	test(`update details and actions fit together at ${width}px`, async ({
		page,
	}) => {
		let checks = 0;
		const status = {
			currentVersion: "1.7.1",
			latestVersion: "1.7.1",
			updateAvailable: false,
			reinstallAvailable: true,
			canApply: false,
			applyDisabledReason:
				"Dashboard updates can only be applied from an installed release package.",
			releasedAt: "2026-10-07T12:00:00Z",
			lastCheckedAt: "2026-10-15T07:00:00Z",
			database: {
				currentVersion: 11,
				targetVersion: 11,
				upgradeRequired: false,
			},
		};
		await page.route("**/api/v1/system/update", (route) =>
			route.fulfill({ json: { data: status } })
		);
		await page.route("**/api/v1/system/update/check", (route) => {
			checks++;
			return route.fulfill({ json: { data: status } });
		});
		await page.setViewportSize({ width, height: 1000 });
		await page.goto("/dashboard/settings/updates");
		const metrics = page.locator(".settings-updates-metric-grid").first();
		await expect(metrics.locator("dt")).toHaveCount(4);
		if (width === 1440) {
			const rows = await metrics
				.locator("dt")
				.evaluateAll((elements) =>
					elements.map(
						(element) => element.getBoundingClientRect().top
					)
				);
			expect(new Set(rows).size).toBe(1);
		}
		const check = page.getByRole("button", {
			name: "Check for Updates",
			exact: true,
		});
		await check.click();
		await expect.poll(() => checks).toBe(1);
		await expect(
			page.getByRole("button", { name: "Reinstall Latest Version" })
		).toBeDisabled();
		await expect(
			page.getByText(status.applyDisabledReason, { exact: true })
		).toBeVisible();
		expect(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= innerWidth
			)
		).toBe(true);
	});
}

for (const width of [1440, 390]) {
	test(`2FA setup requires verification and can be cancelled at ${width}px`, async ({
		page,
	}) => {
		let enabled = false;
		let starts = 0;
		await page.route("**/api/v1/auth/security", (route) =>
			route.fulfill({
				json: {
					data: {
						twoFactorEnabled: enabled,
						hasPendingSetup: !enabled,
						backupCodesRemaining: enabled ? 2 : 0,
						sessions: [],
					},
				},
			})
		);
		await page.route(
			"**/api/v1/auth/security/two-factor/setup",
			(route) => {
				starts++;
				return route.fulfill({
					json: {
						data: {
							secret: "TESTSETUPKEY",
							otpauthUrl:
								"otpauth://totp/PeakURL:preview?secret=JBSWY3DPEHPK3PXP&issuer=PeakURL",
						},
					},
				});
			}
		);
		await page.route(
			"**/api/v1/auth/security/two-factor/verify",
			async (route) => {
				expect(route.request().postDataJSON()).toEqual({
					token: "123456",
				});
				enabled = true;
				await route.fulfill({
					json: {
						data: { backupCodes: ["test-code-1", "test-code-2"] },
					},
				});
			}
		);
		await page.setViewportSize({ width, height: 900 });
		await page.goto("/dashboard/settings/security");
		await expect(
			page.getByRole("heading", {
				level: 1,
				name: "Settings",
				exact: true,
			})
		).toBeVisible();
		await expect(
			page.getByText("2FA is disabled", { exact: true })
		).toBeVisible();
		await expect(
			page.getByRole("button", { name: /Download/ })
		).toHaveCount(0);
		await page
			.getByRole("button", { name: "Enable 2FA", exact: true })
			.click();
		await expect(
			page.getByRole("img", { name: "TOTP QR code" })
		).toBeVisible();
		await expect(
			page.getByRole("button", { name: "Enable 2FA", exact: true })
		).toHaveCount(0);
		await expect(
			page.getByText("2FA is disabled", { exact: true })
		).toBeVisible();
		await page.getByRole("button", { name: "Cancel", exact: true }).click();
		await expect(
			page.getByLabel("6-digit code", { exact: true })
		).toHaveCount(0);
		await page
			.getByRole("button", { name: "Enable 2FA", exact: true })
			.click();
		await page.getByLabel("6-digit code", { exact: true }).fill("123456");
		expect(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= innerWidth
			)
		).toBe(true);
		await page
			.getByRole("button", { name: "Verify & Enable", exact: true })
			.click();
		await expect(
			page.getByText("2FA is enabled", { exact: true })
		).toBeVisible();
		await expect(
			page.getByRole("button", {
				name: "Download backup codes",
				exact: true,
			})
		).toBeEnabled();
		await expect(
			page.getByText("test-code-1", { exact: true })
		).toBeVisible();
		expect(starts).toBe(2);
	});
}

for (const width of [1440, 390]) {
	test(`configured integrations remain usable at ${width}px`, async ({
		page,
	}) => {
		const webhook = {
			id: "preview-webhook",
			label: "Release notifications",
			url: "https://example.com/webhook/peakurl-notifications",
			isActive: true,
			verifySsl: true,
			events: ["url.created"],
			secretHint: "preview…masked",
		};
		await page.route("**/api/v1/webhooks", (route) =>
			route.fulfill({ json: { data: [webhook] } })
		);
		await page.route("**/api/v1/webhooks/events", (route) =>
			route.fulfill({
				json: {
					data: [
						{
							id: "url.created",
							label: "Link Created",
							group: "links",
							description: "A link is created",
						},
					],
				},
			})
		);
		await page.route("**/api/v1/system/captcha", (route) =>
			route.fulfill({
				json: {
					data: {
						provider: "none",
						configured: false,
						canManageFromDashboard: true,
					},
				},
			})
		);
		await page.setViewportSize({ width, height: 900 });
		await page.goto("/dashboard/settings/integrations");
		await expect(
			page.getByRole("heading", { name: webhook.label, exact: true })
		).toBeVisible();
		await expect(
			page.getByRole("button", { name: "Copy URL", exact: true })
		).toBeVisible();
		await page.getByRole("button", { name: "Edit", exact: true }).click();
		await expect(
			page.getByRole("heading", { name: "Edit Webhook", exact: true })
		).toBeVisible();
		await page.keyboard.press("Escape");
		await expect(
			page.getByRole("heading", { name: "Edit Webhook", exact: true })
		).toHaveCount(0);
		const protection = page.getByRole("switch", {
			name: "Enable CAPTCHA protection",
			exact: true,
		});
		await expect(protection).toHaveAttribute("aria-checked", "false");
		await protection.click();
		await expect(protection).toHaveAttribute("aria-checked", "true");
		await expect(
			page.getByLabel("Site Key", { exact: true })
		).toBeVisible();
		await expect(
			page.getByLabel("Secret Key", { exact: true })
		).toBeVisible();
		expect(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= innerWidth
			)
		).toBe(true);
	});
}

for (const width of [1440, 390]) {
	test(`API endpoint stays read-only and copies from its field at ${width}px`, async ({
		page,
		context,
	}) => {
		await context.grantPermissions(["clipboard-read", "clipboard-write"]);
		await page.setViewportSize({ width, height: 900 });
		await page.goto("/dashboard/settings/general");
		const normalField = await page
			.getByRole("textbox", { name: "First Name" })
			.boundingBox();
		await page.goto("/dashboard/settings/api");
		const endpoint = page.getByRole("textbox", {
			name: "Base API URL",
			exact: true,
		});
		await expect(endpoint).toHaveAttribute("readonly", "");
		const endpointBounds = await endpoint.boundingBox();
		const copy = page.getByRole("button", {
			name: "Copy API URL",
			exact: true,
		});
		const copyBounds = await copy.boundingBox();
		if (!normalField || !endpointBounds || !copyBounds)
			throw new Error("API endpoint controls are missing");
		expect(endpointBounds.height).toBe(normalField.height);
		expect(copyBounds.y).toBe(endpointBounds.y);
		expect(copyBounds.height).toBe(endpointBounds.height);
		await copy.click();
		expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(
			await endpoint.inputValue()
		);
	});
}

for (const width of [1440, 390]) {
	test(`saved location credentials can be edited and cancelled at ${width}px`, async ({
		page,
	}) => {
		await page.route("**/api/v1/system/geoip", (route) =>
			route.fulfill({
				json: {
					data: {
						locationAnalyticsReady: true,
						credentialsConfigured: true,
						accountId: "123456",
						licenseKeyHint: "test••••key",
						canManageFromDashboard: true,
					},
				},
			})
		);
		await page.setViewportSize({ width, height: 900 });
		await page.goto("/dashboard/settings/location");
		await expect(
			page.getByText("Credentials saved", { exact: true })
		).toBeVisible();
		const savedValues = page.locator(
			".settings-location-credentials-panel dd"
		);
		await expect(savedValues).toHaveText(["123456", "test••••key"]);
		await expect(
			page.locator(".settings-location-credentials-panel input")
		).toHaveCount(0);
		await savedValues.first().click();
		await expect(savedValues.first()).not.toBeFocused();
		await expect(savedValues.first()).toHaveCSS("box-shadow", "none");
		await page
			.getByRole("button", { name: "Update Credentials", exact: true })
			.click();
		await expect(
			page.getByRole("textbox", {
				name: "MaxMind Account ID",
				exact: true,
			})
		).toHaveValue("123456");
		await expect(
			page.getByLabel("MaxMind License Key", { exact: true })
		).toHaveValue("");
		await page.getByRole("button", { name: "Cancel", exact: true }).click();
		await expect(savedValues).toHaveText(["123456", "test••••key"]);
		expect(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= innerWidth
			)
		).toBe(true);
	});

	for (const failure of [false, true]) {
		test(`update summary shows ${failure ? "failures and findings" : "available release and database upgrade"} at ${width}px`, async ({
			page,
		}) => {
			await page.route("**/api/v1/system/update", (route) =>
				route.fulfill({
					json: {
						data: {
							currentVersion: "1.7.1",
							latestVersion: "1.7.2",
							updateAvailable: true,
							canApply: true,
							lastError: failure
								? "Release service unavailable"
								: null,
							database: {
								currentVersion: 11,
								targetVersion: 12,
								upgradeRequired: true,
								lastError: failure
									? "Database verification failed"
									: null,
								issues: failure
									? [
											{
												id: "missing-index",
												label: "Missing link lookup index",
											},
										]
									: [],
							},
						},
					},
				})
			);
			await page.setViewportSize({ width, height: 900 });
			await page.goto("/dashboard/settings/updates");
			await expect(
				page.getByRole("heading", {
					name: failure
						? "Update service unavailable"
						: "PeakURL 1.7.2 is available",
					exact: true,
				})
			).toBeVisible();
			await expect(
				page.getByRole("heading", {
					name: failure
						? "Database upgrade failed"
						: "Database upgrade recommended",
					exact: true,
				})
			).toBeVisible();
			await expect(
				page.getByRole("button", {
					name: "Install Update",
					exact: true,
				})
			).toBeEnabled();
			await expect(
				page.getByRole("button", {
					name: "Run Database Upgrade",
					exact: true,
				})
			).toBeEnabled();
			if (failure) {
				await expect(
					page.getByText("Release service unavailable", {
						exact: true,
					})
				).toBeVisible();
				await expect(
					page.getByText("Missing link lookup index", { exact: true })
				).toBeVisible();
			}
			expect(
				await page.evaluate(
					() => document.documentElement.scrollWidth <= innerWidth
				)
			).toBe(true);
		});
	}
}
