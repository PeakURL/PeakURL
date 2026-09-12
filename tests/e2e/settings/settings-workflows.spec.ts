import { test, expect } from "../fixtures/auth.fixture";

test.describe("Settings Workflows", () => {
	test("settings tabs navigate and render valid panels", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/settings/general", { waitUntil: "commit" });

		// General tab
		await expect(
			page.getByRole("heading", { name: /account settings/i })
		).toBeVisible({ timeout: 25000 });
		await expect(
			page.getByRole("heading", { name: /profile information/i })
		).toBeVisible();

		// Security tab
		await page.locator("a[href='/dashboard/settings/security']").click();
		await page.waitForURL("**/dashboard/settings/security", {
			timeout: 15000,
		});
		await expect(
			page.getByRole("heading", { name: /change password|password/i })
		).toBeVisible();
		await expect(
			page.getByRole("heading", { name: /active sessions/i })
		).toBeVisible();

		// Updates tab
		await page.locator("a[href='/dashboard/settings/updates']").click();
		await page.waitForURL("**/dashboard/settings/updates", {
			timeout: 15000,
		});
		await expect(
			page.getByRole("heading", { name: /application updates|updates/i })
		).toBeVisible();
		await expect(
			page.getByRole("heading", { name: /database schema/i })
		).toBeVisible();

		// API Keys tab
		await page.locator("a[href='/dashboard/settings/api']").click();
		await page.waitForURL("**/dashboard/settings/api", { timeout: 15000 });
		await expect(
			page.getByRole("button", { name: /create (new )?key/i })
		).toBeVisible();
	});

	test("general profile settings persist changes across reload and safeguard username", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/settings/general", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", { name: /profile information/i })
		).toBeVisible({ timeout: 25000 });

		// Username must be disabled and display cannot be changed notice
		const usernameInput = page.locator("#settings-general-username");
		await expect(usernameInput).toBeDisabled();
		await expect(page.getByText(/cannot be changed/i)).toBeVisible();

		const firstNameInput = page.locator("input[name='firstName']");
		await expect(firstNameInput).toBeVisible();
		const originalFirstName = await firstNameInput.inputValue();

		// Update First Name
		const testFirstName = `QA${Date.now().toString(36).slice(-4)}`;
		await firstNameInput.fill(testFirstName);

		const savePromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/users/me") &&
				res.request().method() === "PUT"
		);

		await page.getByRole("button", { name: /save changes/i }).click();
		const saveResponse = await savePromise;
		expect(saveResponse.status()).toBe(200);

		// Verify success feedback
		await expect(
			page.getByText(/profile updated successfully|success/i).first()
		).toBeVisible();

		// Reload page fresh to verify true persistence
		await page.goto("/dashboard/settings/general", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", { name: /profile information/i })
		).toBeVisible({ timeout: 25000 });

		// Input must contain persisted value
		await expect(page.locator("input[name='firstName']")).toHaveValue(
			testFirstName
		);

		// Cleanup: restore original first name
		await page
			.locator("input[name='firstName']")
			.fill(originalFirstName || "Admin");
		const restorePromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/users/me") &&
				res.request().method() === "PUT"
		);
		await page.getByRole("button", { name: /save changes/i }).click();
		await restorePromise;
	});
});
