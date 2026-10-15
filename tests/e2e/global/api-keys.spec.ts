import { test, expect } from "@playwright/test";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";
import type { ApiKeySummary } from "../../../client/api/types/users";

for (const width of [320, 768, 1440]) {
	test(`API keys stay masked and deletion requires confirmation at ${width}px`, async ({
		page,
	}) => {
		await page.route("**/api/v1/**", (route) =>
			route.fulfill({
				status: 503,
				json: { message: "Preview unavailable" },
			})
		);
		await mockDashboardSession(page, {
			capabilities: { manage_api_keys: true },
		});
		await page.route("**/api/v1/system/notices", (route) =>
			route.fulfill({ json: { data: { items: [] } } })
		);
		let keys: ApiKeySummary[] = [
			{
				id: "preview-production",
				label: "Production automation with a descriptive integration name",
				maskedKey: "preview••••1234",
				createdAt: "2026-10-15T10:00:00Z",
			},
			{
				id: "preview-reports",
				label: "Reports",
				maskedKey: "reports••••5678",
				createdAt: "2026-10-14T10:00:00Z",
			},
		];
		let deletions = 0;
		await page.route("**/api/v1/users/me", (route) =>
			route.fulfill({
				json: {
					data: {
						id: "dashboard-preview",
						username: "preview",
						firstName: "Preview",
						lastName: "User",
						email: "preview@example.com",
						role: "admin",
						capabilities: { manage_api_keys: true },
						apiKeys: keys,
					},
				},
			})
		);
		await page.route("**/api/v1/auth/api-key/*", (route) => {
			expect(route.request().method()).toBe("DELETE");
			deletions++;
			const id = route.request().url().split("/").at(-1);
			keys = keys.filter((key) => key.id !== id);
			return route.fulfill({ json: { success: true } });
		});
		await page.setViewportSize({ width, height: 900 });
		await page.goto("/dashboard/settings/api");
		const list = page.getByRole("list", { name: "Saved API keys" });
		await expect(list.getByRole("listitem")).toHaveCount(2);
		await expect(
			list.getByText("preview••••1234", { exact: true })
		).toBeVisible();
		for (const row of await list.getByRole("listitem").all()) {
			const token = row.locator("code");
			const deleteButton = row.getByRole("button");
			const bounds = await row.boundingBox();
			const tokenBounds = await token.boundingBox();
			const deleteBounds = await deleteButton.boundingBox();
			if (!bounds || !tokenBounds || !deleteBounds)
				throw new Error("Saved key controls are missing");
			expect(tokenBounds.x).toBeGreaterThanOrEqual(bounds.x);
			expect(tokenBounds.x + tokenBounds.width).toBeLessThanOrEqual(
				bounds.x + bounds.width
			);
			expect(deleteBounds.x + deleteBounds.width).toBeLessThanOrEqual(
				bounds.x + bounds.width
			);
		}
		await list.getByRole("button").first().click();
		const dialog = page.getByRole("dialog");
		await expect(
			dialog.getByRole("heading", { name: "Delete API key", exact: true })
		).toBeVisible();
		expect(deletions).toBe(0);
		await dialog
			.getByRole("button", { name: "Keep key", exact: true })
			.click();
		await expect(list.getByRole("listitem")).toHaveCount(2);
		for (let remaining = 1; remaining >= 0; remaining--) {
			await list.getByRole("button").first().click();
			await page
				.getByRole("dialog")
				.getByRole("button", { name: "Delete key", exact: true })
				.click();
			await expect(page.locator(".settings-api-key-row")).toHaveCount(
				remaining
			);
		}
		expect(deletions).toBe(2);
		await expect(
			page.getByRole("heading", { name: "No API keys yet", exact: true })
		).toBeVisible();
		await page
			.getByRole("button", { name: "Create New Key", exact: true })
			.click();
		await expect(
			page
				.getByRole("dialog")
				.getByRole("textbox", { name: "Label (Optional)", exact: true })
		).toBeVisible();
		await page
			.getByRole("dialog")
			.getByRole("button", { name: "Cancel", exact: true })
			.click();
		expect(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= innerWidth
			)
		).toBe(true);
	});
}
