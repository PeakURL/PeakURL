import { test, expect } from "../fixtures/auth.fixture";

test.describe("Users & Roles Admin Journeys", () => {
	test("users page loads with user overview stats and table", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/users", { waitUntil: "commit" });

		// Heading
		await expect(
			page.getByRole("heading", { name: /^users$/i })
		).toBeVisible({ timeout: 25000 });

		// Overview stats (Total Users, Administrators, Editors)
		await expect(page.getByText(/total users/i).first()).toBeVisible();
		await expect(page.getByText(/administrators/i).first()).toBeVisible();

		// Add user button
		await expect(
			page.getByRole("button", { name: /add user/i })
		).toBeVisible();

		// Users table
		await expect(page.locator(".users-page-table")).toBeVisible();
	});

	test("add user modal opens with accessible inputs and closes cleanly", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/users", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", { name: /^users$/i })
		).toBeVisible({ timeout: 25000 });

		// Open Add User dialog
		await page.getByRole("button", { name: /add user/i }).click();

		const dialogTitle = page.getByRole("heading", { name: /^add user$/i });
		await expect(dialogTitle).toBeVisible();

		// Check required input fields
		await expect(page.getByLabel(/first name/i)).toBeVisible();
		await expect(page.getByLabel(/last name/i)).toBeVisible();
		await expect(page.getByLabel(/^email/i)).toBeVisible();
		await expect(page.getByLabel(/^username/i)).toBeVisible();

		// Close dialog
		await page.keyboard.press("Escape");
		await expect(dialogTitle).not.toBeVisible();
	});

	test("enforces administrative safeguards: cannot self-delete and self role is locked", async ({
		authenticatedPage: page,
		adminCredentials,
	}) => {
		await page.goto("/dashboard/users", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", { name: /^users$/i })
		).toBeVisible({ timeout: 25000 });

		// Locate self user row
		const selfRow = page.locator(".users-page-table-row", {
			hasText: adminCredentials.identifier,
		});
		await expect(selfRow).toBeVisible();

		// 1. Self-delete is disabled with safeguard notice
		const deleteBtn = selfRow.locator(".users-page-action-btn-delete");
		await expect(deleteBtn).toBeDisabled();
		await expect(deleteBtn).toHaveAttribute(
			"aria-label",
			/cannot delete your own account/i
		);

		// 2. Self role cannot be demoted
		const editBtn = selfRow.locator(".users-page-action-btn-edit");
		await editBtn.click();

		const editTitle = page.getByRole("heading", { name: /^edit user$/i });
		await expect(editTitle).toBeVisible();

		// Role dropdown is disabled for self
		const roleButton = page.locator(".users-page-dialog-field button.select-trigger");
		await expect(roleButton).toBeDisabled();
		await expect(
			page.getByText(/your own role is locked here/i)
		).toBeVisible();

		// Close dialog
		await page.getByRole("button", { name: /cancel/i }).click();
		await expect(editTitle).not.toBeVisible();
	});
});
