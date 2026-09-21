import { test, expect } from "../fixtures/auth.fixture";

test.describe("Links Table Expiration Display Regression Tests", () => {
	test("displays accurate expiration countdowns and expired states on initial load and refresh", async ({
		authenticatedPage: page,
	}) => {
		const nowMs = Date.now();

		const mockLinks = [
			{
				id: "exp-1min",
				userId: "1",
				shortCode: "exp-1m",
				alias: "exp-1m",
				shortUrl: "https://peakurl.dev/exp-1m",
				title: "Link Expiring in 1 Minute",
				destinationUrl: "https://example.com/1m",
				socialPreview: {
					title: "",
					description: "",
					imageUrl: "",
					externalImageUrl: null,
				},
				domain: null,
				clicks: 0,
				uniqueClicks: 0,
				status: "active",
				hasPassword: false,
				expiresAt: new Date(nowMs + 60 * 1000).toISOString(),
				createdAt: new Date(nowMs - 60 * 1000).toISOString(),
				updatedAt: new Date(nowMs - 60 * 1000).toISOString(),
			},
			{
				id: "exp-15min",
				userId: "1",
				shortCode: "exp-15m",
				alias: "exp-15m",
				shortUrl: "https://peakurl.dev/exp-15m",
				title: "Link Expiring in 15 Minutes",
				destinationUrl: "https://example.com/15m",
				socialPreview: {
					title: "",
					description: "",
					imageUrl: "",
					externalImageUrl: null,
				},
				domain: null,
				clicks: 0,
				uniqueClicks: 0,
				status: "active",
				hasPassword: false,
				expiresAt: new Date(nowMs + 15 * 60 * 1000).toISOString(),
				createdAt: new Date(nowMs - 60 * 1000).toISOString(),
				updatedAt: new Date(nowMs - 60 * 1000).toISOString(),
			},
			{
				id: "exp-1hr",
				userId: "1",
				shortCode: "exp-1h",
				alias: "exp-1h",
				shortUrl: "https://peakurl.dev/exp-1h",
				title: "Link Expiring in 1 Hour",
				destinationUrl: "https://example.com/1h",
				socialPreview: {
					title: "",
					description: "",
					imageUrl: "",
					externalImageUrl: null,
				},
				domain: null,
				clicks: 0,
				uniqueClicks: 0,
				status: "active",
				hasPassword: false,
				expiresAt: new Date(nowMs + 60 * 60 * 1000).toISOString(),
				createdAt: new Date(nowMs - 60 * 1000).toISOString(),
				updatedAt: new Date(nowMs - 60 * 1000).toISOString(),
			},
			{
				id: "exp-passed",
				userId: "1",
				shortCode: "exp-past",
				alias: "exp-past",
				shortUrl: "https://peakurl.dev/exp-past",
				title: "Link Past Expiration",
				destinationUrl: "https://example.com/past",
				socialPreview: {
					title: "",
					description: "",
					imageUrl: "",
					externalImageUrl: null,
				},
				domain: null,
				clicks: 0,
				uniqueClicks: 0,
				status: "active", // Timestamp in past but cron has not yet transitioned status column
				hasPassword: false,
				expiresAt: new Date(nowMs - 5 * 60 * 1000).toISOString(),
				createdAt: new Date(nowMs - 10 * 60 * 1000).toISOString(),
				updatedAt: new Date(nowMs - 10 * 60 * 1000).toISOString(),
			},
			{
				id: "exp-protected",
				userId: "1",
				shortCode: "exp-prot",
				alias: "exp-prot",
				shortUrl: "https://peakurl.dev/exp-prot",
				title: "Protected Link with Expiration",
				destinationUrl: "https://example.com/prot",
				socialPreview: {
					title: "",
					description: "",
					imageUrl: "",
					externalImageUrl: null,
				},
				domain: null,
				clicks: 0,
				uniqueClicks: 0,
				status: "active",
				hasPassword: true,
				expiresAt: new Date(nowMs + 30 * 60 * 1000).toISOString(),
				createdAt: new Date(nowMs - 60 * 1000).toISOString(),
				updatedAt: new Date(nowMs - 60 * 1000).toISOString(),
			},
		];

		// Intercept the links list API to provide deterministic link fixtures
		await page.route("**/api/v1/urls*", async (route) => {
			if (route.request().method() === "GET") {
				await route.fulfill({
					status: 200,
					contentType: "application/json",
					body: JSON.stringify({
						success: true,
						data: {
							items: mockLinks,
							meta: {
								page: 1,
								limit: 25,
								totalItems: mockLinks.length,
								totalPages: 1,
								itemCount: mockLinks.length,
							},
						},
					}),
				});
				return;
			}
			await route.continue();
		});

		// 1. Initial Page Load
		await page.goto("/dashboard/links", { waitUntil: "domcontentloaded" });
		await expect(
			page.getByRole("heading", {
				name: /^(all links|links)$/i,
				level: 1,
			})
		).toBeVisible({ timeout: 25000 });

		const row1m = page.locator(".links-row", { hasText: "exp-1m" });
		const row15m = page.locator(".links-row", { hasText: "exp-15m" });
		const row1h = page.locator(".links-row", { hasText: "exp-1h" });
		const rowPast = page.locator(".links-row", { hasText: "exp-past" });
		const rowProt = page.locator(".links-row", { hasText: "exp-prot" });

		await expect(row1m).toBeVisible();
		await expect(row15m).toBeVisible();
		await expect(row1h).toBeVisible();
		await expect(rowPast).toBeVisible();
		await expect(rowProt).toBeVisible();

		// Requirement 1: A link expiring in ~1 minute is NOT displayed as "Expires in 1 hour"
		await expect(row1m.locator(".links-row-badge-copy")).toContainText(
			/expires in 1 minute/i
		);
		await expect(row1m.locator(".links-row-badge-copy")).not.toContainText(
			/expires in 1 hour/i
		);
		await expect(row1m.locator(".links-row-badge")).toHaveClass(
			/links-row-badge-info/
		);

		// Requirement 2: A link expiring in several minutes displays appropriate remaining time
		await expect(row15m.locator(".links-row-badge-copy")).toContainText(
			/expires in 15 minutes/i
		);
		await expect(row15m.locator(".links-row-badge")).toHaveClass(
			/links-row-badge-info/
		);

		// Requirement 3: A link expiring in about one hour displays the appropriate hour value
		await expect(row1h.locator(".links-row-badge-copy")).toContainText(
			/expires in 1 hour/i
		);
		await expect(row1h.locator(".links-row-badge")).toHaveClass(
			/links-row-badge-info/
		);

		// Requirement 4: An already-expired link is displayed as expired (with error badge and expired status)
		await expect(rowPast.locator(".links-row-badge-copy")).toHaveText(
			/expired/i
		);
		await expect(rowPast.locator(".links-row-badge")).toHaveClass(
			/links-row-badge-error/
		);
		await expect(rowPast.locator(".links-row-status-label")).toHaveText(
			/expired/i
		);
		await expect(rowPast.locator(".links-row-status-dot")).toHaveClass(
			/bg-error/
		);

		// Requirement 6: Protected link retains protected badge alongside expiration badge
		await expect(rowProt.getByText(/protected/i)).toBeVisible();
		await expect(rowProt.locator(".links-row-badge-copy")).toContainText(
			/expires in 30 minutes/i
		);

		// Requirement 5: Initial page data and refreshed page data use the same correct expiration logic
		// Trigger page reload / refresh
		await page.reload({ waitUntil: "domcontentloaded" });
		await expect(
			page.getByRole("heading", {
				name: /^(all links|links)$/i,
				level: 1,
			})
		).toBeVisible({ timeout: 25000 });

		// Verify that after refresh, the exact same correct values and badges are displayed
		await expect(
			page
				.locator(".links-row", { hasText: "exp-1m" })
				.locator(".links-row-badge-copy")
		).toContainText(/expires in 1 minute/i);
		await expect(
			page
				.locator(".links-row", { hasText: "exp-1m" })
				.locator(".links-row-badge-copy")
		).not.toContainText(/expires in 1 hour/i);

		await expect(
			page
				.locator(".links-row", { hasText: "exp-15m" })
				.locator(".links-row-badge-copy")
		).toContainText(/expires in 15 minutes/i);

		await expect(
			page
				.locator(".links-row", { hasText: "exp-1h" })
				.locator(".links-row-badge-copy")
		).toContainText(/expires in 1 hour/i);

		await expect(
			page
				.locator(".links-row", { hasText: "exp-past" })
				.locator(".links-row-badge-copy")
		).toHaveText(/expired/i);

		await expect(
			page
				.locator(".links-row", { hasText: "exp-prot" })
				.getByText(/protected/i)
		).toBeVisible();
	});
});
