import {
	test,
	expect,
	ensureEditorUser,
	DEFAULT_EDITOR_CREDENTIALS,
} from "../fixtures/auth.fixture";

test.describe("Users & Roles Admin Journeys", () => {
	test("users page loads with user overview stats and table", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/users", { waitUntil: "commit" });

		// Heading
		await expect(
			page.getByRole("heading", { name: /^users$/i, level: 1 })
		).toBeVisible({ timeout: 25000 });

		// Overview stats (Total Users, Administrators, Editors)
		await expect(page.getByText(/total users/i).first()).toBeVisible();
		await expect(page.getByText(/administrators/i).first()).toBeVisible();

		// Add user button
		await expect(
			page.getByRole("button", { name: /add user/i })
		).toBeVisible();

		// Users table and column alignment
		await expect(page.locator(".users-page-table")).toBeVisible();
		await expect(page.locator("th.users-page-col-user")).toBeVisible();
		await expect(page.locator("th.users-page-col-role")).toBeVisible();
		await expect(page.locator("th.users-page-col-created")).toBeVisible();
		await expect(page.locator("th.users-page-col-actions")).toBeVisible();
	});

	test("add user modal opens with accessible inputs and closes cleanly", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/users", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", { name: /^users$/i, level: 1 })
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
			page.getByRole("heading", { name: /^users$/i, level: 1 })
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
		const roleButton = page.locator(
			".users-page-dialog-field button.select-trigger"
		);
		await expect(roleButton).toBeDisabled();
		await expect(
			page.getByText(/your own role is locked here/i)
		).toBeVisible();

		// Close dialog
		await page.getByRole("button", { name: /cancel/i }).click();
		await expect(editTitle).not.toBeVisible();
	});

	test("edit user modal populates existing user details and updates when switching between users", async ({
		authenticatedPage: page,
		adminCredentials,
	}) => {
		// Ensure a second user exists
		await ensureEditorUser(page);

		await page.goto("/dashboard/users", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", { name: /^users$/i, level: 1 })
		).toBeVisible({ timeout: 25000 });
		await expect(page.locator(".users-page-table")).toBeVisible();

		// 1. Open edit dialog for admin user
		const adminRow = page.locator(".users-page-table-row", {
			hasText: adminCredentials.identifier,
		});
		await expect(adminRow).toBeVisible();
		await adminRow.locator(".users-page-action-btn-edit").click();

		const dialogTitle = page.getByRole("heading", { name: /^edit user$/i });
		await expect(dialogTitle).toBeVisible();

		// Admin user fields should be populated
		await expect(page.getByLabel(/^username/i)).toHaveValue(
			adminCredentials.identifier
		);
		const adminEmail = await page.getByLabel(/^email/i).inputValue();
		expect(adminEmail).toBeTruthy();
		expect(adminEmail).toContain("@");

		// Passwords should be blank in edit mode
		await expect(page.getByLabel(/^new password/i)).toHaveValue("");
		await expect(page.getByLabel(/^confirm new password/i)).toHaveValue("");

		// Close dialog
		await page.getByRole("button", { name: /cancel/i }).click();
		await expect(dialogTitle).not.toBeVisible();

		// 2. Open edit dialog for editor user
		const editorRow = page.locator(".users-page-table-row", {
			hasText: DEFAULT_EDITOR_CREDENTIALS.identifier,
		});
		await expect(editorRow).toBeVisible();
		await editorRow.locator(".users-page-action-btn-edit").click();

		await expect(dialogTitle).toBeVisible();

		// Editor user fields should now be populated with editor data
		await expect(page.getByLabel(/^username/i)).toHaveValue(
			DEFAULT_EDITOR_CREDENTIALS.identifier
		);
		const editorEmail = await page.getByLabel(/^email/i).inputValue();
		expect(editorEmail).toBeTruthy();
		expect(editorEmail).toContain("@");

		// Values should differ from admin user
		expect(DEFAULT_EDITOR_CREDENTIALS.identifier).not.toBe(
			adminCredentials.identifier
		);
		expect(editorEmail).not.toBe(adminEmail);

		await expect(page.getByLabel(/^new password/i)).toHaveValue("");
		await expect(page.getByLabel(/^confirm new password/i)).toHaveValue("");

		// Close dialog
		await page.getByRole("button", { name: /cancel/i }).click();
		await expect(dialogTitle).not.toBeVisible();

		// 3. Open Add User dialog to verify it resets to empty form
		await page.getByRole("button", { name: /add user/i }).click();
		const addTitle = page.getByRole("heading", { name: /^add user$/i });
		await expect(addTitle).toBeVisible();

		await expect(page.getByLabel(/^first name/i)).toHaveValue("");
		await expect(page.getByLabel(/^last name/i)).toHaveValue("");
		await expect(page.getByLabel(/^display name/i)).toHaveValue("");
		await expect(page.getByLabel(/^username/i)).toHaveValue("");
		await expect(page.getByLabel(/^email/i)).toHaveValue("");
		await expect(page.getByLabel(/^password/i)).toHaveValue("");
		await expect(page.getByLabel(/^confirm password/i)).toHaveValue("");

		await page.keyboard.press("Escape");
		await expect(addTitle).not.toBeVisible();
	});
});
