import { expect, test } from "@playwright/test";

import { mockDrawerPages } from "../fixtures/drawer.fixture";

for (const theme of ["light", "dark"] as const) {
	test(`missing pages and auth attribution share branding in ${theme} mode`, async ({
		page,
	}) => {
		await page.addInitScript(
			(theme) => localStorage.setItem("theme", theme),
			theme
		);
		await mockDrawerPages(page, theme === "dark" ? "rtl" : "ltr");
		await page.goto("/dashboard/missing-page");
		await expect(
			page.getByRole("heading", { name: "Page not found", exact: true })
		).toBeVisible();
		await expect(page.locator(".status-tagline")).toHaveText(
			"Shorten, track, and own every link."
		);
		for (const width of [1440, 320]) {
			await page.setViewportSize({ width, height: 1000 });
			const badge = page.locator(".powered-by:visible");
			await expect(badge).toHaveCount(1);
			await expect(badge).toHaveAccessibleName("Powered by PeakURL");
			expect(
				await badge
					.locator("img")
					.evaluate(
						(img: HTMLImageElement) =>
							img.complete && img.naturalWidth > 0
					)
			).toBe(true);
			expect(
				await page.evaluate(
					() => document.documentElement.scrollWidth <= innerWidth
				)
			).toBe(true);
			if (process.env.PEAKURL_UI_PREVIEW)
				await page.screenshot({
					path: `/tmp/peakurl-dashboard-404-${theme}-${width}.png`,
					fullPage: true,
					animations: "disabled",
				});
		}
		await page.getByRole("button", { name: "Go to Dashboard" }).click();
		await expect(
			page.getByRole("heading", { name: "Dashboard", exact: true })
		).toBeVisible();

		await page.route("**/api/v1/users/me", (route) =>
			route.fulfill({
				status: 401,
				json: { message: "Sign in required" },
			})
		);
		for (const pathname of ["/login", "/forgot-password"]) {
			await page.goto(pathname);
			for (const width of [1440, 320]) {
				await page.setViewportSize({ width, height: 1000 });
				await expect(
					page.locator(".powered-by:visible")
				).toHaveAccessibleName("Powered by PeakURL");
				expect(
					await page.evaluate(
						() => document.documentElement.scrollWidth <= innerWidth
					)
				).toBe(true);
				if (process.env.PEAKURL_UI_PREVIEW)
					await page.screenshot({
						path: `/tmp/peakurl-auth-${pathname.slice(1)}-${theme}-${width}.png`,
						fullPage: true,
						animations: "disabled",
					});
			}
		}
	});
}
