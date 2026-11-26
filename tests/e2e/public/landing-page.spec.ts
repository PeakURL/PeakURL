import { execFileSync } from "node:child_process";

import { expect, test } from "@playwright/test";

const origin = "https://landing-preview.invalid";

test.use({ javaScriptEnabled: false });

for (const [locale, colorScheme, direction] of [
	["en_US", "light", "ltr"],
	["ar", "dark", "rtl"],
] as const) {
	test(`the ${direction} landing page loads in ${colorScheme} mode without remote assets or scripts`, async ({
		page,
	}) => {
		const html = execFileSync(
			"php",
			["tests/e2e/fixtures/public-landing-page.php", locale],
			{ encoding: "utf8" }
		);
		const attemptedRequests: string[] = [];
		await page.emulateMedia({ colorScheme, reducedMotion: "reduce" });
		await page.route("**/*", (route) => {
			if (route.request().url() === `${origin}/installed/`) {
				return route.fulfill({ contentType: "text/html", body: html });
			}
			attemptedRequests.push(route.request().url());
			return route.abort();
		});
		await page.goto(`${origin}/installed/`);
		await expect(page.locator("html")).toHaveAttribute("dir", direction);
		await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
		await expect(page.locator(".action-primary")).toHaveAttribute(
			"href",
			"/installed/login"
		);
		await expect(page.locator(".brand-logo .brand-wordmark")).toBeVisible();
		const artwork = page.locator(".hero-artwork");
		await expect(artwork).toHaveAttribute("alt", "");
		expect(
			await artwork.evaluate(
				(image: HTMLImageElement) =>
					image.complete && image.naturalWidth > 0
			)
		).toBe(true);
		await expect(page.locator("body")).toHaveCSS(
			"background-color",
			colorScheme === "dark" ? "rgb(14, 19, 18)" : "rgb(243, 248, 247)"
		);
		for (const width of [320, 390, 768, 1024, 1440, 1920]) {
			await page.setViewportSize({ width, height: 900 });
			expect(
				await page.evaluate(() => document.documentElement.scrollWidth)
			).toBe(width);
		}
		expect(attemptedRequests).toEqual([]);
	});
}

test("the landing page theme toggle switches between light and dark mode", async ({
	browser,
}) => {
	const context = await browser.newContext({
		javaScriptEnabled: true,
		colorScheme: "light",
	});
	try {
		const page = await context.newPage();
		const html = execFileSync(
			"php",
			["tests/e2e/fixtures/public-landing-page.php", "en_US"],
			{ encoding: "utf8" }
		);
		await page.route("**/*", (route) => {
			if (route.request().url() === `${origin}/installed/`) {
				return route.fulfill({ contentType: "text/html", body: html });
			}
			return route.abort();
		});
		await page.goto(`${origin}/installed/`);
		const toggle = page.getByRole("button", { name: "Toggle theme" });
		await expect(toggle).toHaveAttribute("aria-pressed", "false");
		await toggle.click();
		await expect(page.locator("html")).toHaveAttribute(
			"data-status-theme",
			"dark"
		);
		await expect(toggle).toHaveAttribute("aria-pressed", "true");
		await page.reload();
		await expect(page.locator("html")).toHaveAttribute(
			"data-status-theme",
			"dark"
		);
	} finally {
		await context.close();
	}
});
