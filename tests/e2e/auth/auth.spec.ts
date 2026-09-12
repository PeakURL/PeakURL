import { test, expect } from "../fixtures/auth.fixture";

test.describe("Authentication Journeys", () => {
	test("login page renders with accessible form elements", async ({ page }) => {
		await page.goto("/login", { waitUntil: "domcontentloaded" });

		await expect(page).toHaveTitle(/PeakURL/);
		await expect(page.getByRole("heading", { name: /sign in/i })).toBeVisible();

		// Check presence of login inputs
		const usernameInput = page.getByLabel(
			/email or username|username or email/i
		);
		await expect(usernameInput).toBeVisible();
		await expect(usernameInput).toBeEditable();

		const passwordInput = page.getByLabel(/^password/i);
		await expect(passwordInput).toBeVisible();

		const submitButton = page.getByRole("button", { name: /sign in/i });
		await expect(submitButton).toBeVisible();
	});

	test("unauthenticated access to protected routes redirects to /login", async ({
		page,
	}) => {
		await page.goto("/dashboard", { waitUntil: "domcontentloaded" });
		await expect(page).toHaveURL(/\/login/);

		await page.goto("/dashboard/links", { waitUntil: "domcontentloaded" });
		await expect(page).toHaveURL(/\/login/);

		await page.goto("/dashboard/settings", { waitUntil: "domcontentloaded" });
		await expect(page).toHaveURL(/\/login/);
	});

	test("login displays error message on invalid credentials", async ({
		page,
	}) => {
		await page.goto("/login", { waitUntil: "domcontentloaded" });

		await page
			.getByLabel(/email or username|username or email/i)
			.fill("invalid_user_test");
		await page.getByLabel(/^password/i).fill("wrong_password_123");
		await page.getByRole("button", { name: /sign in/i }).click();

		// Wait for error feedback
		const errorAlert = page.locator(
			".login-page-alert, [role='alert'], .notification, .login-error-message"
		);
		await expect(errorAlert).toBeVisible();
		await expect(errorAlert).toContainText(/invalid|incorrect|credentials|password/i);

		// Login remains usable
		await expect(page.getByRole("button", { name: /sign in/i })).toBeEnabled();
	});

	test("valid credentials authenticate user, establish session, and allow logout", async ({
		page,
		adminCredentials,
	}) => {
		await page.goto("/login", { waitUntil: "domcontentloaded" });

		// Perform UI-driven login
		await page
			.getByLabel(/email or username|username or email/i)
			.fill(adminCredentials.identifier);
		await page.getByLabel(/^password/i).fill(adminCredentials.password);
		await page.getByRole("button", { name: /sign in/i }).click();

		// Should navigate to dashboard
		await page.waitForURL("**/dashboard", { timeout: 15000 });
		await expect(page.getByRole("heading", { name: /dashboard/i })).toBeVisible();

		// Header displays authenticated user trigger
		const userTrigger = page.locator(".dashboard-header-user-trigger");
		await expect(userTrigger).toBeVisible();

		// Open user menu and click logout
		await userTrigger.click();
		const logoutButton = page.getByRole("menuitem", {
			name: /logout|sign out/i,
		});
		await expect(logoutButton).toBeVisible();
		await logoutButton.click();

		// Redirects to /login
		await page.waitForURL("**/login", { timeout: 15000 });
		await expect(page.getByRole("heading", { name: /sign in/i })).toBeVisible();

		// Attempting to visit /dashboard should now redirect to /login
		await page.goto("/dashboard", { waitUntil: "domcontentloaded" });
		await expect(page).toHaveURL(/\/login/);
	});

	test("forgot password page renders and accepts email input", async ({
		page,
	}) => {
		await page.goto("/forgot-password", { waitUntil: "domcontentloaded" });

		await expect(
			page.getByRole("heading", {
				name: /forgot your password|reset (your )?password/i,
			})
		).toBeVisible();

		const emailInput = page.getByLabel(/email/i);
		await expect(emailInput).toBeVisible();

		const submitButton = page.getByRole("button", {
			name: /send reset (link|instructions)/i,
		});
		await expect(submitButton).toBeVisible();
	});
});

