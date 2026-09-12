import { test, expect } from "../fixtures/auth.fixture";

test.describe("Activity Feed Journeys", () => {
	test("activity page loads with audit log header, categories, and table", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/activity", { waitUntil: "commit" });

		// Heading and badge
		await expect(
			page.getByRole("heading", { name: /activity log/i })
		).toBeVisible({ timeout: 25000 });
		await expect(page.getByText(/audit log/i).first()).toBeVisible();

		// Category tabs
		await expect(
			page.getByRole("tab", { name: /all events/i })
		).toBeVisible();
		await expect(page.getByRole("tab", { name: /links/i })).toBeVisible();

		// Activity panel / table exists
		await expect(
			page.locator(
				".activity-page-panel, .activity-table, [role='table']"
			)
		).toBeVisible();
	});

	test("category filtering and refresh work smoothly", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/activity", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", { name: /activity log/i })
		).toBeVisible({ timeout: 25000 });

		// Filter by Links
		const linksCategoryBtn = page.getByRole("button", { name: /^links/i });
		if (await linksCategoryBtn.isVisible()) {
			const filterPromise = page.waitForResponse(
				(res) =>
					res.url().includes("/api/v1/analytics/activity") &&
					res.status() === 200
			);
			await linksCategoryBtn.click();
			await filterPromise;
		}

		// Refresh activity
		const refreshButton = page.locator(
			".activity-page-hero-refresh, button[aria-label*='refresh' i]"
		);
		if (await refreshButton.isVisible()) {
			const refreshPromise = page.waitForResponse(
				(res) =>
					res.url().includes("/api/v1/analytics/activity") &&
					res.status() === 200
			);
			await refreshButton.click();
			await refreshPromise;
		}
	});
});
