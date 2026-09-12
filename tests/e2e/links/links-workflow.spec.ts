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
});
