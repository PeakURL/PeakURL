import { test, expect } from "../fixtures/auth.fixture";

test.describe("Tools & Utilities Journeys", () => {
	test("tools export page loads and offers CSV and JSON export options", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/tools/export", { waitUntil: "commit" });

		// Page heading & badge
		await expect(
			page.getByRole("heading", { name: /^export$/i })
		).toBeVisible({ timeout: 25000 });
		await expect(page.getByText(/data export/i).first()).toBeVisible();

		// Export format cards
		await expect(
			page.getByRole("heading", { name: /csv export/i })
		).toBeVisible();
		await expect(
			page.getByRole("heading", { name: /json export/i })
		).toBeVisible();

		// Export buttons are available
		const exportButtons = page.getByRole("button", { name: /^export$/i });
		await expect(exportButtons.first()).toBeVisible();
	});

	test("tools import surfaces load file dropzone and paste imports", async ({
		authenticatedPage: page,
	}) => {
		// Import File
		await page.goto("/dashboard/tools/import/file", {
			waitUntil: "commit",
		});
		await expect(
			page.getByRole("heading", { name: /import/i })
		).toBeVisible({ timeout: 25000 });

		// Dropzone and format requirements
		await expect(
			page.getByText(/drop your file here|choose file/i).first()
		).toBeVisible();
		await expect(
			page
				.getByText(/file format requirements|sample data structure/i)
				.first()
		).toBeVisible();

		// Import Paste tab
		await page.goto("/dashboard/tools/import/paste", {
			waitUntil: "commit",
		});
		await expect(
			page.getByRole("heading", { name: /import/i })
		).toBeVisible({ timeout: 25000 });

		// Paste textarea
		const pasteInput = page.locator(
			"textarea.import-paste-textarea, textarea"
		);
		await expect(pasteInput).toBeVisible();
		await pasteInput.fill(
			"https://example.com/paste-import-test-1\nhttps://example.com/paste-import-test-2"
		);

		// Import button is enabled
		const importBtn = page.getByRole("button", { name: /create links/i });
		await expect(importBtn).toBeEnabled();
	});

	test("system status page renders site health diagnostics and service checks", async ({
		authenticatedPage: page,
	}) => {
		await page.goto("/dashboard/tools/system-status", {
			waitUntil: "commit",
		});

		// Hero title & badge
		await expect(
			page.getByRole("heading", { name: /system status/i })
		).toBeVisible({ timeout: 25000 });
		await expect(page.getByText(/site health/i).first()).toBeVisible();

		// Refresh health check button
		const refreshBtn = page.getByRole("button", {
			name: /refresh site health status/i,
		});
		await expect(refreshBtn).toBeVisible();
	});
});
