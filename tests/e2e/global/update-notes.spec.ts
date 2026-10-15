import { test, expect } from "@playwright/test";
import { mockDashboardSession } from "../fixtures/dashboard.fixture";

test.beforeEach(async ({ page }) => {
	await mockDashboardSession(page, {
		capabilities: { manage_updates: true },
	});
	await page.route("**/api/v1/system/notices", (route) =>
		route.fulfill({ json: { data: { items: [] } } })
	);
});

test("installed release notes open in About instead of a modal", async ({
	page,
}) => {
	await page.route("**/api/v1/system/update", (route) =>
		route.fulfill({
			json: {
				data: {
					currentVersion: "4.2.0",
					latestVersion: "4.2.0",
					updateAvailable: false,
				},
			},
		})
	);
	await page.route("https://api.peakurl.org/v1/release-notes", (route) =>
		route.fulfill({
			json: {
				data: {
					releases: [
						{
							version: "4.2.0",
							title: "Installed release",
							summary: "Notes for this installation.",
							highlights: [],
							releaseDate: "2026-10-01",
						},
					],
				},
			},
		})
	);
	await page.goto("/dashboard/settings/updates");
	await expect(
		page.getByRole("heading", { name: "PeakURL Updates", exact: true })
	).toHaveCount(0);
	await expect(
		page.getByRole("heading", {
			name: "PeakURL 4.2.0 is the latest version",
			level: 2,
		})
	).toBeVisible();
	await expect(
		page.locator(".settings-update-status-prominent .lucide-package-check")
	).toBeVisible();
	const notes = page.getByRole("link", {
		name: "Release notes",
		exact: true,
	});
	await expect(notes).toHaveAttribute(
		"href",
		"/dashboard/about?tab=releases"
	);
	await notes.click();
	await expect(
		page.getByRole("heading", { name: "Installed release", exact: true })
	).toBeVisible();
	await expect(page.getByRole("dialog")).toHaveCount(0);
});

for (const releaseNotesUrl of ["https://peakurl.org/releases/4.3.0", ""]) {
	test(`an available update opens ${releaseNotesUrl ? "its published notes" : "release history when no notes URL is published"}`, async ({
		page,
	}) => {
		await page.route("**/api/v1/system/update", (route) =>
			route.fulfill({
				json: {
					data: {
						currentVersion: "4.2.0",
						latestVersion: "4.3.0",
						updateAvailable: true,
						releaseNotesUrl,
					},
				},
			})
		);
		await page.goto("/dashboard/settings/updates");
		await expect(
			page.getByRole("heading", { name: "PeakURL Updates", exact: true })
		).toHaveCount(0);
		await expect(
			page.getByRole("heading", {
				name: "PeakURL 4.3.0 is available",
				level: 2,
			})
		).toBeVisible();
		await expect(
			page.locator(
				".settings-update-status-prominent .lucide-package-plus"
			)
		).toBeVisible();
		const notes = page.getByRole("link", {
			name: "Release notes",
			exact: true,
		});
		await expect(notes).toHaveAttribute(
			"href",
			releaseNotesUrl || "https://go.peakurl.org/release-notes"
		);
		await expect(notes).toHaveAttribute("target", "_blank");
		await expect(page.getByRole("dialog")).toHaveCount(0);
	});
}
