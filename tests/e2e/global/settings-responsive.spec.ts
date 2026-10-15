import { test, expect } from "@playwright/test";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";

const tabs = [
	"general",
	"security",
	"api",
	"integrations",
	"performance",
	"email",
	"location",
	"updates",
];

test.beforeEach(async ({ page }) => {
	await page.route("**/api/v1/**", (route) =>
		route.fulfill({ status: 503, json: { message: "Preview unavailable" } })
	);
	await mockDashboardSession(page, {
		capabilities: {
			view_links: true,
			manage_site_settings: true,
			manage_api_keys: true,
			manage_integrations: true,
			manage_performance: true,
			manage_mail_delivery: true,
			manage_location_data: true,
			manage_updates: true,
		},
	});
	const data = {
		"system/notices": { items: [] },
		"system/general": {
			canManageSiteSettings: true,
			siteName: "PeakURL",
			siteLanguage: "en_US",
			siteTimezone: "UTC",
		},
		"system/mail": {
			driver: "smtp",
			canManageFromDashboard: true,
			smtpHost: "smtp.example.com",
			smtpPort: 587,
			smtpAuth: true,
			smtpEncryption: "tls",
		},
		"system/captcha": {
			provider: "turnstile",
			configured: false,
			canManageFromDashboard: true,
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
			credentialsConfigured: true,
			accountId: "123456",
			licenseKeyHint: "preview••••key",
			canManageFromDashboard: true,
		},
		"system/update": {
			currentVersion: "1.7.1",
			latestVersion: "1.7.1",
			reinstallAvailable: true,
			canApply: false,
			applyDisabledReason: "Preview installation cannot apply updates.",
			database: { currentVersion: 11, targetVersion: 11 },
		},
		"auth/security": { twoFactorEnabled: false, sessions: [] },
		webhooks: [
			{
				id: "preview",
				label: "Notifications",
				url: "https://example.com/webhook/peakurl-notifications",
				isActive: true,
				verifySsl: true,
				secretHint: "preview••••secret",
				events: ["url.created"],
			},
		],
		"webhooks/events": [
			{
				id: "url.created",
				label: "Link Created",
				group: "links",
				description: "A link is created",
			},
		],
	};
	for (const [path, payload] of Object.entries(data)) {
		await page.route(`**/api/v1/${path}`, (route) =>
			route.fulfill({ json: { data: payload } })
		);
	}
});

for (const width of [320, 640, 768, 1024, 1280, 1920]) {
	test(`all Settings tabs keep their controls inside the content at ${width}px`, async ({
		page,
	}) => {
		await page.setViewportSize({ width, height: 900 });
		for (const tab of tabs) {
			await test.step(tab, async () => {
				await page.goto(`/dashboard/settings/${tab}`);
				await expect(page).toHaveURL(new RegExp(`/settings/${tab}$`));
				const pane = page.locator(".settings-tab-page");
				await expect(pane.locator("h2").first()).toBeVisible();
				const bounds = await pane.boundingBox();
				if (!bounds) throw new Error("Settings content is missing");
				const overflowing = await pane.evaluate((element) => {
					const paneBounds = element.getBoundingClientRect();
					return Array.from(
						element.querySelectorAll(
							"input:not([type=file]), textarea, button"
						)
					)
						.filter((control) => {
							const rect = control.getBoundingClientRect();
							return (
								rect.width > 0 &&
								rect.height > 0 &&
								(rect.left < paneBounds.left - 1 ||
									rect.right > paneBounds.right + 1)
							);
						})
						.map(
							(control) =>
								control.getAttribute("aria-label") ||
								control.textContent
						);
				});
				expect(overflowing).toEqual([]);
				expect(bounds.x).toBeGreaterThanOrEqual(0);
				expect(bounds.x + bounds.width).toBeLessThanOrEqual(width);
				expect(
					await page.evaluate(
						() => document.documentElement.scrollWidth <= innerWidth
					)
				).toBe(true);
			});
		}
	});
}
