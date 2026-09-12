import { test, expect } from "../fixtures/auth.fixture";

test.describe("Dashboard & Layout Journeys", () => {
	test("authenticated dashboard loads overview metrics and widgets", async ({
		authenticatedPage: page,
	}) => {
		// Heading and subtitle
		await expect(
			page.getByRole("heading", { name: /^dashboard$/i })
		).toBeVisible();
		await expect(
			page.getByText(/here's what's happening with your links/i)
		).toBeVisible();

		// Primary metric cards
		await expect(page.getByText(/total clicks/i).first()).toBeVisible();
		await expect(page.getByText(/active links/i).first()).toBeVisible();
		await expect(page.getByText(/unique click rate/i).first()).toBeVisible();
		await expect(page.getByText(/visitors/i).first()).toBeVisible();

		// Analytics panels
		await expect(
			page.getByRole("heading", { name: /traffic overview/i })
		).toBeVisible();
		await expect(
			page.getByRole("heading", { name: /recent clicks/i })
		).toBeVisible();
		await expect(
			page.getByRole("heading", { name: /device breakdown/i })
		).toBeVisible();
		await expect(
			page.getByRole("heading", { name: /top countries/i })
		).toBeVisible();
		await expect(
			page.getByRole("heading", { name: /recent activity/i })
		).toBeVisible();
	});

	test("dashboard time range filter updates analytics query", async ({
		authenticatedPage: page,
	}) => {
		const timeRangeSelector = page.locator(
			".dashboard-overview-header-select button"
		);
		await expect(timeRangeSelector).toBeVisible();

		// Open time range selector and pick 30 days
		const responsePromise30 = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/analytics") &&
				res.status() === 200
		);

		await timeRangeSelector.click();
		const option30 = page.getByRole("option", { name: /last 30 days/i });
		await expect(option30).toBeVisible();
		await option30.click();

		const response30 = await responsePromise30;
		expect(response30.status()).toBe(200);

		// Switch to 90 days
		const responsePromise90 = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/analytics") &&
				res.status() === 200
		);

		await timeRangeSelector.click();
		const option90 = page.getByRole("option", { name: /last 90 days/i });
		await expect(option90).toBeVisible();
		await option90.click();

		const response90 = await responsePromise90;
		expect(response90.status()).toBe(200);
	});

	test("dashboard synchronized refresh triggers background refetch", async ({
		authenticatedPage: page,
	}) => {
		const refreshButton = page.getByRole("button", {
			name: /refresh dashboard data/i,
		});
		await expect(refreshButton).toBeVisible();

		const refetchPromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/analytics") &&
				res.status() === 200
		);

		await refreshButton.click();
		const response = await refetchPromise;
		expect(response.status()).toBe(200);

		// Button remains visible and enabled after refresh
		await expect(refreshButton).toBeEnabled();
	});

	test("navigation from dashboard to primary product areas", async ({
		authenticatedPage: page,
	}) => {
		// Navigate to Links
		await page.locator("a[href='/dashboard/links']").first().click();
		await page.waitForURL("**/dashboard/links", { timeout: 15000 });
		await expect(
			page.getByRole("heading", { name: /^links$/i })
		).toBeVisible();

		// Navigate to Settings
		await page.locator("a[href*='/settings']").first().click();
		await page.waitForURL("**/dashboard/settings/**", { timeout: 15000 });
		await expect(
			page.getByRole("heading", { name: /account settings/i })
		).toBeVisible();

		// Navigate to Users
		await page.locator("a[href='/dashboard/users']").first().click();
		await page.waitForURL("**/dashboard/users", { timeout: 15000 });
		await expect(
			page.getByRole("heading", { name: /^users$/i })
		).toBeVisible();

		// Navigate back to Dashboard
		await page.locator("a[href='/dashboard']").first().click();
		await page.waitForURL("**/dashboard", { timeout: 15000 });
		await expect(
			page.getByRole("heading", { name: /^dashboard$/i })
		).toBeVisible();
	});

	test("gracefully handles analytics API failure without logging out and recovers on retry", async ({
		authenticatedPage: page,
	}) => {
		// Mock analytics API to return 500
		await page.route("**/api/v1/analytics*", async (route) => {
			await route.fulfill({
				status: 500,
				contentType: "application/json",
				body: JSON.stringify({
					success: false,
					message: "Internal server error",
				}),
			});
		});

		// Trigger refresh while API is returning 500
		const refreshButton = page.getByRole("button", {
			name: /refresh dashboard data/i,
		});
		await refreshButton.click();

		// User remains authenticated on dashboard and is NOT kicked to /login
		await expect(page).toHaveURL(/\/dashboard$/);
		await expect(
			page.getByRole("heading", { name: /^dashboard$/i })
		).toBeVisible();

		// Remove route mock
		await page.unroute("**/api/v1/analytics*");

		// Trigger retry/refresh to recover
		const recoverPromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/analytics") &&
				res.status() === 200
		);
		await refreshButton.click();
		const response = await recoverPromise;
		expect(response.status()).toBe(200);

		// Metric widgets remain intact
		await expect(page.getByText(/total clicks/i).first()).toBeVisible();
	});
});



