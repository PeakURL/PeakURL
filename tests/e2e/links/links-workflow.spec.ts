import { test, expect, generateTestId } from "../fixtures/auth.fixture";

test.describe("Links Workflow Journeys", () => {
	test("links page loads with list, quick stats, and shortening form", async ({
		authenticatedPage: page,
	}) => {
		await page.locator("a[href='/dashboard/links']").first().click();
		await page.waitForURL("**/dashboard/links", { timeout: 15000 });

		// Heading
		await expect(
			page.getByRole("heading", { name: /^links$/i })
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

		// Table
		await expect(page.locator(".links-table")).toBeVisible();
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
			page.getByRole("heading", { name: /^links$/i })
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
			page.getByRole("heading", { name: /^links$/i })
		).toBeVisible({ timeout: 25000 });
		await expect(
			page.locator(".links-row", { hasText: alias1 })
		).not.toBeVisible();
		await expect(
			page.locator(".links-row", { hasText: alias2 })
		).not.toBeVisible();

		// 8. Filter by Trashed status in footer
		const statusFilterBtn = page.locator(
			"button[aria-label='Filter links by status']"
		);
		await statusFilterBtn.click();
		const trashOption = page.getByRole("option", { name: /trash/i });
		await trashOption.click();

		// Trashed links must now be visible in table
		await expect(
			page.locator(".links-row", { hasText: alias1 })
		).toBeVisible({ timeout: 15000 });

		// 9. Select all trashed links
		await page.locator("input[aria-label='Select all links']").click();

		// 10. "Empty trash" action button appears
		const emptyTrashBtn = page.locator(".links-table-header-delete-all");
		await expect(emptyTrashBtn).toBeVisible();

		// 11. Test cancellation of Empty Trash
		await emptyTrashBtn.click();
		const emptyTrashModal = page.locator(".confirm-dialog-panel");
		await expect(emptyTrashModal).toBeVisible();
		await expect(
			emptyTrashModal.getByRole("heading", { name: /empty trash/i })
		).toBeVisible();

		await emptyTrashModal.getByRole("button", { name: /cancel/i }).click();
		await expect(emptyTrashModal).not.toBeVisible();
		await expect(
			page.locator(".links-row", { hasText: alias1 })
		).toBeVisible();

		// 12. Perform real Empty Trash
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

		// Verify visible state: trashed links removed
		await expect(
			page.locator(".links-row", { hasText: alias1 })
		).not.toBeVisible({ timeout: 10000 });
	});
});
