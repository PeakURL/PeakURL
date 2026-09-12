import { test, expect } from "@playwright/test";

test.describe("Dashboard & Layout Journeys", () => {
	test("login redirects or renders login screen cleanly", async ({ page }) => {
		await page.goto("/");
		// Root path redirects to /dashboard which guards to /login if unauthenticated
		await expect(page).toHaveURL(/\/(dashboard|login)/);
	});

	test("login page provides link to password recovery", async ({ page }) => {
		await page.goto("/login");

		const forgotPasswordLink = page.getByRole("link", {
			name: /forgot your password/i,
		});
		await expect(forgotPasswordLink).toBeVisible();
		await forgotPasswordLink.click();

		await expect(page).toHaveURL(/\/forgot-password/);
	});

	test("forgot password page provides link back to sign in", async ({
		page,
	}) => {
		await page.goto("/forgot-password");

		const backToLoginLink = page.getByRole("link", {
			name: /back to sign in/i,
		});
		await expect(backToLoginLink).toBeVisible();
		await backToLoginLink.click();

		await expect(page).toHaveURL(/\/login/);
	});
});
