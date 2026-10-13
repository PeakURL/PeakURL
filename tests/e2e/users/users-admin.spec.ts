import {
	test,
	expect,
	ensureEditorUser,
	DEFAULT_EDITOR_CREDENTIALS,
} from "../fixtures/auth.fixture";
import {
	isSafeTestHostname,
	isExplicitlyAuthorizedTestInstance,
	captureRoleBaseline,
	restoreAndVerifyRoleBaseline,
} from "../fixtures/test-environment";

test.describe("Users & Roles Admin Journeys", () => {
	test.describe.configure({ mode: "serial" });
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
			hasText: `@${adminCredentials.identifier}`,
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
			hasText: `@${adminCredentials.identifier}`,
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

	test("delete user modal accurately describes account removal and link transfer", async ({
		authenticatedPage: page,
	}) => {
		await ensureEditorUser(page);

		await page.goto("/dashboard/users", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", { name: /^users$/i, level: 1 })
		).toBeVisible({ timeout: 25000 });
		await expect(page.locator(".users-page-table")).toBeVisible();

		const editorRow = page.locator(".users-page-table-row", {
			hasText: DEFAULT_EDITOR_CREDENTIALS.identifier,
		});
		await expect(editorRow).toBeVisible();

		await editorRow.locator(".users-page-action-btn-delete").click();

		const dialogTitle = page.getByRole("heading", {
			name: /^delete user$/i,
		});
		await expect(dialogTitle).toBeVisible();

		await expect(
			page.getByText(/transferred to your administrator account/i)
		).toBeVisible();

		// Close dialog safely without deleting
		await page.getByRole("button", { name: /cancel/i }).click();
		await expect(dialogTitle).not.toBeVisible();
	});

	test("manage roles drawer opens, shows configurable role tabs without immutable administrator, and displays grouped capabilities with admin-only badges", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/users", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", { name: /^users$/i, level: 1 })
		).toBeVisible({ timeout: 25000 });

		// Manage Roles & Capabilities button is visible beside Add User
		const manageRolesBtn = page.getByRole("button", {
			name: /manage roles & capabilities/i,
		});
		await expect(manageRolesBtn).toBeVisible();
		await manageRolesBtn.click();

		// Drawer heading
		const drawerTitle = page.getByRole("heading", {
			name: /manage roles & capabilities/i,
		});
		await expect(drawerTitle).toBeVisible();

		// Role tabs: immutable administrator is not configurable; Editor and Create Role are available
		await expect(
			page.getByRole("button", { name: /administrator/i })
		).not.toBeVisible();
		const editorTab = page.getByRole("button", { name: /editor/i });
		await expect(editorTab).toBeVisible();
		await expect(
			page.getByRole("button", { name: /create role/i })
		).toBeVisible();

		// Group headers
		await expect(page.getByText(/user & access/i).first()).toBeVisible();
		await expect(
			page.getByText(/site & integrations/i).first()
		).toBeVisible();
		await expect(page.getByText(/^links$/i).first()).toBeVisible();
		await expect(page.getByText(/^analytics$/i).first()).toBeVisible();

		// Editor tab displays Admin only badge for reserved capabilities
		await expect(page.getByText(/admin only/i).first()).toBeVisible();

		// Protected badges are not displayed (immutable superuser is not configurable)
		await expect(page.getByText(/protected/i)).not.toBeVisible();

		// Close drawer without edits closes cleanly
		await page.keyboard.press("Escape");
		await expect(drawerTitle).not.toBeVisible();
	});

	test("manage roles drawer prompts for confirmation when closing with unsaved changes", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/users", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", { name: /^users$/i, level: 1 })
		).toBeVisible({ timeout: 25000 });

		// Open drawer
		await page
			.getByRole("button", {
				name: /manage roles & capabilities/i,
			})
			.click();

		const drawerTitle = page.getByRole("heading", {
			name: /manage roles & capabilities/i,
		});
		await expect(drawerTitle).toBeVisible();

		// Switch to Editor tab
		await page.getByRole("button", { name: /editor/i }).click();

		// Find an editable capability toggle (e.g. Delete Links)
		const deleteLinksToggle = page.locator("#cap-toggle-delete_links");
		await expect(deleteLinksToggle).toBeAttached();
		await deleteLinksToggle.click({ force: true });

		// Press Escape - should trigger discard confirmation dialog
		await page.keyboard.press("Escape");

		const discardHeading = page.getByRole("heading", {
			name: /discard unsaved changes\?/i,
		});
		await expect(discardHeading).toBeVisible();

		// Click Keep editing - drawer remains open
		await page.getByRole("button", { name: /keep editing/i }).click();
		await expect(discardHeading).not.toBeVisible();
		await expect(drawerTitle).toBeVisible();

		// Press Cancel button in footer - triggers confirmation again
		await page
			.locator(".users-roles-drawer-footer button", {
				hasText: /cancel/i,
			})
			.click();
		await expect(discardHeading).toBeVisible();

		// Confirm discard - closes both confirmation and drawer
		await page.getByRole("button", { name: /discard changes/i }).click();
		await expect(drawerTitle).not.toBeVisible();
	});

	test("manage roles drawer saves editor capabilities with delta payload, persists across reload, and resets cleanly", async ({
		authenticatedPage: page,
	}) => {
		// 1. Refuse to mutate roles if running against an unsafe or non-approved host
		if (!isSafeTestHostname(page.url())) {
			throw new Error(
				`Refusing to mutate roles outside approved test hosts. Current host: ${new URL(page.url()).hostname}`
			);
		}

		// Require explicit confirmation that the instance is a dedicated disposable test database
		test.skip(
			!isExplicitlyAuthorizedTestInstance(),
			"Mutating role tests require explicit PEAKURL_TEST_DISPOSABLE_DB=1 confirmation to prevent altering developer environments"
		);

		// 2. Establish the actual initial effective role settings and customization state via the API before any mutation
		const originalBaseline = await captureRoleBaseline(
			page.request,
			"editor"
		);
		const initialDeleteLinks = Boolean(
			originalBaseline.capabilities["delete_links"]
		);
		const targetDeleteLinks = !initialDeleteLinks;

		let testError: unknown = null;
		try {
			await page.goto("/dashboard/users", { waitUntil: "commit" });
			await expect(
				page.getByRole("heading", { name: /^users$/i, level: 1 })
			).toBeVisible({ timeout: 25000 });

			// Open drawer
			await page
				.getByRole("button", {
					name: /manage roles & capabilities/i,
				})
				.click();

			const drawerTitle = page.getByRole("heading", {
				name: /manage roles & capabilities/i,
			});
			await expect(drawerTitle).toBeVisible();

			// Switch to Editor tab
			const editorTab = page.getByRole("button", { name: /editor/i });
			await editorTab.click();

			// Verify admin-only controls are disabled on Editor tab
			const manageUsersToggle = page.locator("#cap-toggle-manage_users");
			await expect(manageUsersToggle).toBeDisabled();

			// Toggle Delete Links capability relative to initial state
			const deleteLinksToggle = page.locator("#cap-toggle-delete_links");
			await expect(deleteLinksToggle).toBeAttached();
			if (initialDeleteLinks) {
				await expect(deleteLinksToggle).toBeChecked();
			} else {
				await expect(deleteLinksToggle).not.toBeChecked();
			}
			await deleteLinksToggle.click({ force: true });
			if (targetDeleteLinks) {
				await expect(deleteLinksToggle).toBeChecked();
			} else {
				await expect(deleteLinksToggle).not.toBeChecked();
			}

			// Click Save Changes in footer
			const saveBtn = page.locator(".users-roles-drawer-footer button", {
				hasText: /save changes/i,
			});
			await expect(saveBtn).toBeEnabled();
			await saveBtn.click();

			// Verify success notification
			await expect(
				page.getByText(/role capabilities updated/i)
			).toBeVisible();

			// Reload page to verify server persistence
			await page.reload({ waitUntil: "commit" });
			await expect(
				page.getByRole("heading", { name: /^users$/i, level: 1 })
			).toBeVisible({ timeout: 25000 });

			// Reopen drawer
			await page
				.getByRole("button", {
					name: /manage roles & capabilities/i,
				})
				.click();
			await expect(drawerTitle).toBeVisible();

			// Switch to Editor tab
			await page.getByRole("button", { name: /editor/i }).click();

			// Verify Delete Links persisted target value and Customized badge is shown
			if (targetDeleteLinks) {
				await expect(
					page.locator("#cap-toggle-delete_links")
				).toBeChecked();
			} else {
				await expect(
					page.locator("#cap-toggle-delete_links")
				).not.toBeChecked();
			}
			await expect(page.getByText(/customized/i).first()).toBeVisible();

			// Reset Editor to defaults
			const resetBtn = page.locator(".users-roles-drawer-footer button", {
				hasText: /reset to defaults/i,
			});
			await expect(resetBtn).toBeVisible();
			await resetBtn.click();

			// Confirm Reset dialog appears scoped to .confirm-dialog-panel
			const confirmDialog = page.locator(".confirm-dialog-panel");
			await expect(confirmDialog).toBeVisible();
			const resetHeading = confirmDialog.getByRole("heading", {
				name: /reset .* capabilities\?/i,
			});
			await expect(resetHeading).toBeVisible();
			await confirmDialog
				.getByRole("button", { name: /^reset to defaults$/i })
				.click();

			// Verify success notification and default state
			await expect(
				page.getByText(/role reset to defaults/i)
			).toBeVisible();
			await expect(
				page.locator("#cap-toggle-delete_links")
			).not.toBeChecked();

			// Reload again to verify restored baseline persists on server
			await page.reload({ waitUntil: "commit" });
			await expect(
				page.getByRole("heading", { name: /^users$/i, level: 1 })
			).toBeVisible({ timeout: 25000 });

			await page
				.getByRole("button", {
					name: /manage roles & capabilities/i,
				})
				.click();
			await expect(drawerTitle).toBeVisible();
			await page.getByRole("button", { name: /editor/i }).click();

			// Assert baseline defaults restored
			await expect(
				page.locator("#cap-toggle-delete_links")
			).not.toBeChecked();
			await expect(page.getByText(/customized/i)).not.toBeVisible();

			// Close drawer
			await page.keyboard.press("Escape");
			await expect(drawerTitle).not.toBeVisible();
		} catch (err) {
			testError = err;
		} finally {
			try {
				await restoreAndVerifyRoleBaseline(
					page.request,
					originalBaseline
				);
			} catch (cleanupErr) {
				if (testError) {
					const origMsg =
						testError instanceof Error
							? testError.stack || testError.message
							: String(testError);
					const cleanMsg =
						cleanupErr instanceof Error
							? cleanupErr.stack || cleanupErr.message
							: String(cleanupErr);
					throw new Error(
						`Test failed:\n${origMsg}\n\nAdditionally, baseline restoration failed:\n${cleanMsg}`
					);
				}
				throw cleanupErr;
			}

			if (testError) {
				throw testError;
			}
		}
	});

	test("manage roles drawer restores pre-test state cleanly when starting from an initially customized Editor role", async ({
		authenticatedPage: page,
	}) => {
		if (!isSafeTestHostname(page.url())) {
			throw new Error(
				`Refusing to mutate roles outside approved test hosts. Current host: ${new URL(page.url()).hostname}`
			);
		}

		test.skip(
			!isExplicitlyAuthorizedTestInstance(),
			"Mutating role tests require explicit PEAKURL_TEST_DISPOSABLE_DB=1 confirmation to prevent altering developer environments"
		);

		// 1. Establish initial baseline before setting customized test state
		const originalBaseline = await captureRoleBaseline(
			page.request,
			"editor"
		);

		let testError: unknown = null;
		try {
			// 2. Set up an initially customized Editor role (enabling delete_links) INSIDE guarded scope
			const setupCustomRes = await page.request.put(
				"/api/v1/roles/editor",
				{
					data: { capabilities: { delete_links: true } },
				}
			);
			if (!setupCustomRes.ok()) {
				const text = await setupCustomRes.text();
				throw new Error(
					`Failed to prepare customized Editor role fixture: HTTP ${setupCustomRes.status()} - ${text}`
				);
			}

			// Record the customized fixture baseline for checking midway
			const customBaseline = await captureRoleBaseline(
				page.request,
				"editor"
			);
			expect(customBaseline.isCustomized).toBe(true);
			expect(customBaseline.capabilities["delete_links"]).toBe(true);

			await page.goto("/dashboard/users", { waitUntil: "commit" });
			await expect(
				page.getByRole("heading", { name: /^users$/i, level: 1 })
			).toBeVisible({ timeout: 25000 });

			// Open drawer and navigate to Editor tab
			await page
				.getByRole("button", {
					name: /manage roles & capabilities/i,
				})
				.click();
			await page.getByRole("button", { name: /editor/i }).click();

			// Confirm Delete Links is currently enabled from our customized setup
			await expect(
				page.locator("#cap-toggle-delete_links")
			).toBeChecked();
			await expect(page.getByText(/customized/i).first()).toBeVisible();

			// Mutate another capability: toggle Manage Webhooks (unconditional assertion, no silent skipping)
			const webhooksToggle = page.locator("#cap-toggle-manage_webhooks");
			await expect(webhooksToggle).toBeVisible();
			await expect(webhooksToggle).toBeEnabled();
			await webhooksToggle.click({ force: true });

			const saveBtn = page.locator(".users-roles-drawer-footer button", {
				hasText: /save changes/i,
			});
			await expect(saveBtn).toBeEnabled();
			await saveBtn.click();
			await expect(
				page.getByText(/role capabilities updated/i)
			).toBeVisible();

			// Close drawer
			await page.keyboard.press("Escape");
		} catch (err) {
			testError = err;
		} finally {
			// Teardown: ALWAYS restore the original pre-test state, not intermediate fixture state
			try {
				await restoreAndVerifyRoleBaseline(
					page.request,
					originalBaseline
				);
			} catch (cleanupErr) {
				if (testError) {
					const origMsg =
						testError instanceof Error
							? testError.stack || testError.message
							: String(testError);
					const cleanMsg =
						cleanupErr instanceof Error
							? cleanupErr.stack || cleanupErr.message
							: String(cleanupErr);
					throw new Error(
						`Test failed:\n${origMsg}\n\nAdditionally, baseline restoration failed:\n${cleanMsg}`
					);
				}
				throw cleanupErr;
			}

			if (testError) {
				throw testError;
			}
		}
	});
});
