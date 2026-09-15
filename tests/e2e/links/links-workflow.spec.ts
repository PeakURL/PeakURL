import { test, expect, generateTestId } from "../fixtures/auth.fixture";

test.describe("Links Workflow Journeys", () => {
	test("links page loads with list, quick stats, and shortening form", async ({
		authenticatedPage: page,
	}) => {
		await page.locator("a[href='/dashboard/links']").first().click();
		await page.waitForURL("**/dashboard/links", { timeout: 15000 });

		// Heading
		await expect(
			page.getByRole("heading", {
				name: /^links$/i,
				level: 1,
			})
		).toBeVisible();

		// Shortening form inputs
		await expect(page.locator("#long-url")).toBeVisible();
		await expect(page.locator("#alias")).toBeVisible();
		await expect(
			page.getByRole("button", { name: /shorten/i })
		).toBeVisible();

		// Quick stats
		await expect(page.getByText(/total links/i).first()).toBeVisible();
		await expect(page.getByText(/active links/i).first()).toBeVisible();

		// Table or empty state container
		await expect(
			page.locator(".links-table, .links-empty-state")
		).toBeVisible();
	});

	test("full link lifecycle: create, search, stats drawer, edit, QR, and delete", async ({
		authenticatedPage: page,
	}) => {
		await page.locator("a[href='/dashboard/links']").first().click();
		await page.waitForURL("**/dashboard/links", { timeout: 15000 });

		const uniqueId = generateTestId("lnk");
		const destinationUrl = `https://example.com/dest-${uniqueId}`;
		const customAlias = `al-${uniqueId}`;
		const updatedTitle = `Updated Title ${uniqueId}`;

		// 1. Create Link
		const createPromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/urls") &&
				res.request().method() === "POST"
		);

		await page.locator("#long-url").fill(destinationUrl);
		await page.locator("#alias").fill(customAlias);
		await page.getByRole("button", { name: /shorten/i }).click();

		const createResponse = await createPromise;
		expect([200, 201]).toContain(createResponse.status());

		// Success feedback appears
		await expect(
			page.getByText(/link shortened successfully/i)
		).toBeVisible();

		// 2. Locate created row in table
		const linkRow = page.locator(".links-row", { hasText: customAlias });
		await expect(linkRow).toBeVisible({ timeout: 15000 });

		// 3. Stats Drawer (Analytics)
		const statsButton = linkRow.locator(".links-row-action-stats");
		await statsButton.click();

		// Drawer displays Link Analytics and closes on Escape
		const statsHeading = page.getByRole("heading", {
			name: /link analytics/i,
		});
		await expect(statsHeading).toBeVisible();
		await page.keyboard.press("Escape");
		await expect(statsHeading).not.toBeVisible({ timeout: 10000 });

		// 4. Edit Link & Persistence Across Reload
		const editButton = linkRow.locator(".links-row-action-edit");
		await editButton.click();

		const editHeading = page.getByRole("heading", { name: /edit link/i });
		await expect(editHeading).toBeVisible();

		const titleInput = page.getByLabel(/title/i);
		await titleInput.fill(updatedTitle);

		const updatePromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/urls") &&
				(res.request().method() === "PUT" ||
					res.request().method() === "PATCH")
		);

		await page.getByRole("button", { name: /save changes/i }).click();
		const updateResponse = await updatePromise;
		expect(updateResponse.status()).toBe(200);

		// Wait for edit drawer to close
		await expect(editHeading).not.toBeVisible({ timeout: 10000 });

		// Verify persisted state by navigating fresh to links page
		await page.goto("/dashboard/links", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", {
				name: /^links$/i,
				level: 1,
			})
		).toBeVisible({ timeout: 25000 });

		// Title must remain updated in table
		await expect(
			page.locator(".links-row", { hasText: updatedTitle })
		).toBeVisible({ timeout: 15000 });

		// 5. QR Code Modal
		const reloadedRow = page.locator(".links-row", {
			hasText: updatedTitle,
		});
		const qrButton = reloadedRow.locator(".links-row-action-qr");
		await qrButton.click();

		const qrHeading = page.getByRole("heading", { name: /qr code/i });
		await expect(qrHeading).toBeVisible();

		// QR Image contains data URL
		const qrImage = page.locator("img[alt='QR Code']");
		await expect(qrImage).toBeVisible();
		const imageSrc = await qrImage.getAttribute("src");
		expect(imageSrc).toMatch(/^data:image\/png;base64,/);

		// Close modal
		await page.keyboard.press("Escape");
		await expect(qrHeading).not.toBeVisible();

		// 6. Delete Link & Cancellation
		const deleteButton = reloadedRow.locator(".links-row-action-delete");
		await deleteButton.click();

		const deleteHeading = page.getByRole("heading", {
			name: /move to trash|delete/i,
		});
		await expect(deleteHeading).toBeVisible();

		// Cancel first
		await page.getByRole("button", { name: /cancel/i }).click();
		await expect(deleteHeading).not.toBeVisible();
		await expect(
			page.locator(".links-row", { hasText: updatedTitle })
		).toBeVisible();

		// Delete for real
		await deleteButton.click();
		await expect(deleteHeading).toBeVisible();

		const deletePromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/urls") &&
				res.request().method() === "DELETE"
		);

		// Click Move to Trash or Delete
		const confirmDeleteBtn = page.locator(".links-modal-button-danger");
		await confirmDeleteBtn.click();

		const deleteResponse = await deletePromise;
		expect(deleteResponse.status()).toBe(200);

		// Row should no longer be visible in active links table
		await expect(
			page.locator(".links-row", { hasText: updatedTitle })
		).not.toBeVisible({
			timeout: 10000,
		});
	});

	test("administrator destructive workflows: delete all links and empty trash with confirmation and persistence", async ({
		authenticatedPage: page,
	}) => {
		// Explicitly verify administrator precondition at the API boundary
		const meResponse = await page.request.get("/api/v1/users/me");
		expect(meResponse.ok()).toBe(true);
		const meJson = await meResponse.json();
		expect(meJson.data?.role).toBe("admin");

		await page.locator("a[href='/dashboard/links']").first().click();
		await page.waitForURL("**/dashboard/links", { timeout: 15000 });

		const uniqueId = generateTestId("destruct");
		const alias1 = `d1-${uniqueId}`;
		const alias2 = `d2-${uniqueId}`;

		// 1. Create first link
		await page.locator("#long-url").fill(`https://example.com/${alias1}`);
		await page.locator("#alias").fill(alias1);
		const createP1 = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/urls") &&
				res.request().method() === "POST"
		);
		await page.getByRole("button", { name: /shorten/i }).click();
		const res1 = await createP1;
		expect([200, 201]).toContain(res1.status());

		// 2. Create second link
		await page.locator("#long-url").fill(`https://example.com/${alias2}`);
		await page.locator("#alias").fill(alias2);
		const createP2 = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/urls") &&
				res.request().method() === "POST"
		);
		await page.getByRole("button", { name: /shorten/i }).click();
		const res2 = await createP2;
		expect([200, 201]).toContain(res2.status());

		// Verify both links appear in active table
		await expect(
			page.locator(".links-row", { hasText: alias1 })
		).toBeVisible({ timeout: 10000 });
		await expect(
			page.locator(".links-row", { hasText: alias2 })
		).toBeVisible({ timeout: 10000 });

		// 3. Select all links in table
		const selectAllCheckbox = page.locator(
			"input[aria-label='Select all links']"
		);
		await selectAllCheckbox.click();

		// 4. "Delete all" action button appears
		const deleteAllBtn = page.locator(".links-table-header-delete-all");
		await expect(deleteAllBtn).toBeVisible();

		// 5. Test cancellation of Delete All
		await deleteAllBtn.click();
		const deleteAllModal = page.locator(".confirm-dialog-panel");
		await expect(deleteAllModal).toBeVisible();
		await expect(
			deleteAllModal.getByRole("heading", { name: /delete all links/i })
		).toBeVisible();

		const cancelBtn = deleteAllModal.getByRole("button", {
			name: /cancel/i,
		});
		await cancelBtn.click();
		await expect(deleteAllModal).not.toBeVisible();

		// Both links must still be in the table after cancellation
		await expect(
			page.locator(".links-row", { hasText: alias1 })
		).toBeVisible();
		await expect(
			page.locator(".links-row", { hasText: alias2 })
		).toBeVisible();

		// 6. Perform real Delete All
		await deleteAllBtn.click();
		await expect(deleteAllModal).toBeVisible();

		const deleteAllPromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/urls") &&
				res.request().method() === "DELETE" &&
				!res.url().includes("/trash")
		);

		const confirmDeleteAllBtn = deleteAllModal.getByRole("button", {
			name: /delete all links/i,
		});
		await confirmDeleteAllBtn.click();

		const deleteAllResponse = await deleteAllPromise;
		expect(deleteAllResponse.status()).toBe(200);

		// Verify visible state: links removed from active table
		await expect(
			page.locator(".links-row", { hasText: alias1 })
		).not.toBeVisible({ timeout: 10000 });
		await expect(
			page.locator(".links-row", { hasText: alias2 })
		).not.toBeVisible({ timeout: 10000 });

		// 7. Refresh to verify persisted deletion in active view
		await page.goto("/dashboard/links", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", {
				name: /^links$/i,
				level: 1,
			})
		).toBeVisible({ timeout: 25000 });
		await expect(
			page.locator(".links-row", { hasText: alias1 })
		).not.toBeVisible();
		await expect(
			page.locator(".links-row", { hasText: alias2 })
		).not.toBeVisible();

		// 8. Create and trash a link to exercise the Empty Trash workflow
		const trashAlias = `trash-${uniqueId}`;
		await page
			.locator("#long-url")
			.fill(`https://example.com/${trashAlias}`);
		await page.locator("#alias").fill(trashAlias);
		const createTrashPromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/urls") &&
				res.request().method() === "POST"
		);
		await page.getByRole("button", { name: /shorten/i }).click();
		const createTrashRes = await createTrashPromise;
		expect([200, 201]).toContain(createTrashRes.status());

		// Verify the created link appears in active table
		const trashRow = page.locator(".links-row", { hasText: trashAlias });
		await expect(trashRow).toBeVisible({ timeout: 10000 });

		// Trash the link by triggering row delete
		await trashRow.locator(".links-row-action-delete").click();
		const deleteHeading = page.getByRole("heading", {
			name: /move to trash|delete/i,
		});
		await expect(deleteHeading).toBeVisible();
		const trashPromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/urls/") &&
				res.request().method() === "DELETE"
		);
		await page.locator(".links-modal-button-danger").click();
		await trashPromise;
		await expect(trashRow).not.toBeVisible({ timeout: 10000 });

		// 9. Filter by Trashed status in footer
		const statusFilterBtn = page.locator(
			"button[aria-label='Filter links by status']"
		);
		await statusFilterBtn.click();
		const trashOption = page.getByRole("option", { name: /trash/i });
		await trashOption.click();

		// Trashed links must now be visible in table
		await expect(
			page.locator(".links-row", { hasText: trashAlias })
		).toBeVisible({ timeout: 15000 });

		// 10. Select all trashed links
		await page.locator("input[aria-label='Select all links']").click();

		// 11. "Empty trash" action button appears
		const emptyTrashBtn = page.locator(".links-table-header-delete-all");
		await expect(emptyTrashBtn).toBeVisible();

		// 12. Test cancellation of Empty Trash
		await emptyTrashBtn.click();
		const emptyTrashModal = page.locator(".confirm-dialog-panel");
		await expect(emptyTrashModal).toBeVisible();
		await expect(
			emptyTrashModal.getByRole("heading", { name: /empty trash/i })
		).toBeVisible();

		await emptyTrashModal.getByRole("button", { name: /cancel/i }).click();
		await expect(emptyTrashModal).not.toBeVisible();
		await expect(
			page.locator(".links-row", { hasText: trashAlias })
		).toBeVisible();

		// 13. Perform real Empty Trash
		await emptyTrashBtn.click();
		await expect(emptyTrashModal).toBeVisible();

		const emptyTrashPromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/urls/trash") &&
				res.request().method() === "DELETE"
		);

		const confirmEmptyTrashBtn = emptyTrashModal.getByRole("button", {
			name: /empty trash/i,
		});
		await confirmEmptyTrashBtn.click();

		const emptyTrashResponse = await emptyTrashPromise;
		expect(emptyTrashResponse.status()).toBe(200);

		// Verify visible state: trashed link removed
		await expect(
			page.locator(".links-row", { hasText: trashAlias })
		).not.toBeVisible({ timeout: 10000 });

		// 14. Reload to verify persisted emptiness of trash across navigation
		await page.goto("/dashboard/links", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", {
				name: /^links$/i,
				level: 1,
			})
		).toBeVisible({ timeout: 25000 });

		// Confirm trashAlias is absent from all active views
		await expect(
			page.locator(".links-row", { hasText: trashAlias })
		).not.toBeVisible();

		// Verify trash filter now reflects zero trashed items (disabled or empty state)
		const reloadedFilterBtn = page.locator(
			"button[aria-label='Filter links by status']"
		);
		await reloadedFilterBtn.click();
		const reloadedTrashOption = page.getByRole("option", {
			name: /trash/i,
		});
		await expect(reloadedTrashOption).toHaveAttribute("data-disabled", "");
	});
	test("editor ownership boundaries: manage own links, cannot mutate admin links, and denied global empty trash", async ({
		authenticatedEditorPage: page,
		playwright,
	}) => {
		const uniqueId = generateTestId("ed-own");
		const adminAlias = `adm-${uniqueId}`;
		const editorAlias = `ed-${uniqueId}`;

		// 1. Seed an admin link via an isolated API context without affecting the browser cookie jar
		const adminCreds = {
			identifier: process.env.PEAKURL_TEST_IDENTIFIER || "admin",
			password:
				process.env.PEAKURL_TEST_PASSWORD ||
				process.env.PEAKURL_E2E_PASSWORD ||
				"password",
		};
		const adminApiContext = await playwright.request.newContext({
			baseURL: process.env.PEAKURL_TEST_URL || "https://peakurl.dev",
			ignoreHTTPSErrors: true,
		});

		const adminLoginRes = await adminApiContext.post("/api/v1/auth/login", {
			data: adminCreds,
		});
		expect([200, 204]).toContain(adminLoginRes.status());

		const createAdminLinkRes = await adminApiContext.post("/api/v1/urls", {
			data: {
				destinationUrl: `https://example.com/${adminAlias}`,
				alias: adminAlias,
				title: `Admin Link ${adminAlias}`,
			},
		});
		expect([200, 201]).toContain(createAdminLinkRes.status());
		const adminLinkData = await createAdminLinkRes.json();
		const adminLinkId = adminLinkData.data.id;
		await adminApiContext.dispose();

		// 2. Return to Editor session in browser
		await page.goto("/dashboard/links", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", {
				name: /^links$/i,
				level: 1,
			})
		).toBeVisible({ timeout: 25000 });

		// Verify visibility: Editor can view Administrator-created links
		const adminRow = page.locator(".links-row", { hasText: adminAlias });
		await expect(adminRow).toBeVisible({ timeout: 15000 });

		// Verify Editor cannot trash Administrator-owned link in UI
		await expect(
			adminRow.locator(".links-row-action-delete")
		).not.toBeVisible();

		// 3. Editor creates own link
		await page
			.locator("#long-url")
			.fill(`https://example.com/${editorAlias}`);
		await page.locator("#alias").fill(editorAlias);
		const createEditorLinkPromise = page.waitForResponse(
			(res) =>
				res.url().includes("/api/v1/urls") &&
				res.request().method() === "POST"
		);
		await page.getByRole("button", { name: /shorten/i }).click();
		const createEditorRes = await createEditorLinkPromise;
		expect([200, 201]).toContain(createEditorRes.status());
		const editorLinkData = await createEditorRes.json();
		const editorLinkId = editorLinkData.data.id;

		// Verify Editor's link is visible in table and has Delete button
		const editorRow = page.locator(".links-row", { hasText: editorAlias });
		await expect(editorRow).toBeVisible({ timeout: 10000 });
		await expect(
			editorRow.locator(".links-row-action-delete")
		).toBeVisible();

		// 4. Editor can trash own link
		await editorRow.locator(".links-row-action-delete").click();
		// Verify Editor is NOT presented with the "Delete Permanently" action in the Move to Trash dialog
		await expect(
			page.getByRole("button", { name: /delete permanently/i })
		).not.toBeVisible();
		await page.locator(".links-modal-button-danger").click();
		await expect(editorRow).not.toBeVisible({ timeout: 10000 });

		// 5. Filter by Trashed status: Editor sees own trashed link
		const statusFilterBtn = page.locator(
			"button[aria-label='Filter links by status']"
		);
		await statusFilterBtn.click();
		const trashOption = page.getByRole("option", { name: /trash/i });
		await trashOption.click();

		const trashedEditorRow = page.locator(".links-row", {
			hasText: editorAlias,
		});
		await expect(trashedEditorRow).toBeVisible({ timeout: 15000 });

		// 6. Trashed link cannot be permanently deleted by Editor in the UI
		await expect(
			trashedEditorRow.locator(".links-row-action-delete")
		).not.toBeVisible();

		// Global Empty Trash is NOT available to Editor in the UI
		await expect(
			page.locator(".links-table-header-delete-all")
		).not.toBeVisible();

		// 7. Security IDOR boundary: Editor attempts direct API deletion of Admin link
		const idorDeleteAdminRes = await page.request.delete(
			`/api/v1/urls/${adminLinkId}`
		);
		expect(idorDeleteAdminRes.status()).toBe(403);

		// 8. Security IDOR boundary: Editor attempts direct API permanent delete of own trashed link
		const idorPermanentDeleteRes = await page.request.delete(
			`/api/v1/urls/${editorLinkId}`
		);
		expect(idorPermanentDeleteRes.status()).toBe(403);

		// 9. Security IDOR boundary: Editor attempts direct API call to empty trash globally
		const idorEmptyTrashRes =
			await page.request.delete("/api/v1/urls/trash");
		expect(idorEmptyTrashRes.status()).toBe(403);

		// 10. Verify persisted state after reload
		await page.goto("/dashboard/links", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", {
				name: /^links$/i,
				level: 1,
			})
		).toBeVisible({ timeout: 25000 });

		// Admin link remains active and visible to Editor
		await expect(
			page.locator(".links-row", { hasText: adminAlias })
		).toBeVisible();
	});

	test("link stats drawer loads traffic location and traffic history with filter switching", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/links", { waitUntil: "commit" });
		await expect(
			page.getByRole("heading", {
				name: /^links$/i,
				level: 1,
			})
		).toBeVisible({ timeout: 25000 });

		const uniqueId = generateTestId("stat-flow");
		const customAlias = `stats-${uniqueId}`;

		// Create a link
		await page
			.locator("#long-url")
			.fill(`https://example.com/${customAlias}`);
		await page.locator("#alias").fill(customAlias);
		await page.getByRole("button", { name: /shorten/i }).click();

		const linkRow = page.locator(".links-row", { hasText: customAlias });
		await expect(linkRow).toBeVisible({ timeout: 15000 });

		// Open Stats Drawer
		const statsButton = linkRow.locator(".links-row-action-stats");
		await statsButton.click();

		const statsHeading = page.getByRole("heading", {
			name: /link analytics/i,
		});
		await expect(statsHeading).toBeVisible();

		// Verify Traffic Statistics tab cards
		await expect(page.getByText(/total clicks/i).first()).toBeVisible();
		await expect(page.getByText(/quick insights/i).first()).toBeVisible();
		await expect(page.getByText(/traffic history/i).first()).toBeVisible();

		// Switch ranges in Traffic History: 24h, 7d, 30d
		const range24hBtn = page.locator(".links-traffic-history-range", {
			hasText: "24h",
		});
		await range24hBtn.click();
		await expect(range24hBtn).toHaveClass(
			/links-traffic-history-range-current/
		);

		const range30dBtn = page.locator(".links-traffic-history-range", {
			hasText: "30d",
		});
		await range30dBtn.click();
		await expect(range30dBtn).toHaveClass(
			/links-traffic-history-range-current/
		);

		// Switch series modes: Clicks, Visitors, Both
		const clicksSeriesBtn = page.locator(
			".links-traffic-history-tool-button",
			{
				hasText: /clicks/i,
			}
		);
		await clicksSeriesBtn.click();
		await expect(clicksSeriesBtn).toHaveClass(
			/links-traffic-history-tool-button-current/
		);

		// Switch to Traffic Location Tab
		const locationTabBtn = page.getByRole("tab", {
			name: /traffic location/i,
		});
		await locationTabBtn.click();

		// Verify Traffic Location panel renders successfully without 500 error
		await expect(
			page.getByRole("heading", { name: /top countries/i })
		).toBeVisible();
		await expect(
			page
				.getByText(
					/no location data available|no link data available|direct, private, or local network/i
				)
				.first()
		).toBeVisible();

		// Close drawer
		await page.keyboard.press("Escape");
		await expect(statsHeading).not.toBeVisible();
	});
});
