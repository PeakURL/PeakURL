import { test, expect } from "@playwright/test";

test.describe("Tools & Utilities Journeys", () => {
	test("handles 404 routes gracefully by redirecting to dashboard / login", async ({
		page,
	}) => {
		await page.goto("/non-existent-route-12345");
		// Unauthenticated requests should redirect to /login
		await expect(page).toHaveURL(/\/(dashboard|login)/);
	});
});
