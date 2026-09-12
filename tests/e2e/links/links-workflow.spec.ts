import { test, expect } from "@playwright/test";

test.describe("Links Workflow Journeys", () => {
	test("login form allows keyboard submission with Enter", async ({ page }) => {
		await page.goto("/login");

		const usernameInput = page.getByLabel(/username or email/i);
		await usernameInput.fill("testuser");
		await usernameInput.press("Enter");

		const passwordInput = page.getByLabel(/password/i);
		await expect(passwordInput).toBeVisible();
	});
});
