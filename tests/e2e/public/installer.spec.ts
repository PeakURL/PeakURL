import { execFileSync } from "node:child_process";
import { expect, test } from "@playwright/test";

const origin = "https://installer-preview.invalid";
const states = [
	[0, "Welcome to PeakURL", "installation"],
	[1, "Database connection", "database-setup"],
	[2, "Create your account", "administrator"],
] as const;

for (const theme of ["light", "dark"] as const) {
	test(`installer steps fit desktop and mobile in ${theme} mode`, async ({
		page,
	}) => {
		await page.emulateMedia({ colorScheme: theme });
		await page.route("**/*", (route) => route.abort());
		for (const [step, title, illustration] of states) {
			const html = execFileSync(
				"php",
				["tests/e2e/fixtures/installer-page.php", String(step)],
				{ encoding: "utf8" }
			);
			await page.route(`${origin}/**`, (route) =>
				route.fulfill({ contentType: "text/html", body: html })
			);
			await page.goto(`${origin}/${step}`);
			await expect(page.locator("html")).toHaveAttribute(
				"data-status-theme",
				theme
			);
			await expect(
				page.locator(
					`.installer-theme-icon-${theme === "dark" ? "moon" : "sun"}`
				)
			).toBeVisible();
			await expect(
				page.locator(
					`.installer-theme-icon-${theme === "dark" ? "sun" : "moon"}`
				)
			).toBeHidden();
			await expect(
				page.getByRole("heading", { name: title, exact: true })
			).toBeVisible();
			await expect(page.locator("[aria-current='step']")).toContainText(
				["Welcome", "Database", "Admin account"][step]
			);
			for (const width of [1440, 320]) {
				await page.setViewportSize({ width, height: 900 });
				const artwork = page.locator(
					`[data-status-illustration='${illustration}']`
				);
				await expect(artwork).toBeVisible();
				await expect
					.poll(() =>
						artwork.evaluate(
							(image: HTMLImageElement) =>
								image.complete && image.naturalWidth > 0
						)
					)
					.toBe(true);
				expect(
					await page.evaluate(
						() => document.documentElement.scrollWidth <= innerWidth
					)
				).toBe(true);
				await expect(
					page.getByRole("link", { name: "Powered by PeakURL" })
				).toBeVisible();
				if (step > 0) {
					await expect(
						page.locator("input:not([type=hidden])").first()
					).toBeEditable();
					await expect(
						page.locator("#browser_timezone")
					).not.toHaveValue("");
				}
				if (process.env.PEAKURL_UI_PREVIEW)
					await page.screenshot({
						path: `/tmp/peakurl-installer-${step}-${theme}-${width}.png`,
						fullPage: true,
					});
			}
			await page
				.getByRole("button", { name: "Toggle dark mode" })
				.click();
			await expect(page.locator("html")).toHaveAttribute(
				"data-status-theme",
				theme === "dark" ? "light" : "dark"
			);
			await expect(
				page.locator(
					`.installer-theme-icon-${theme === "dark" ? "sun" : "moon"}`
				)
			).toBeVisible();
			await page.reload();
			await expect(page.locator("html")).toHaveAttribute(
				"data-status-theme",
				theme === "dark" ? "light" : "dark"
			);
			await page
				.getByRole("button", { name: "Toggle dark mode" })
				.click();
		}
	});
}

test("installer forms retain local actions, entered values, and readable errors in RTL", async ({
	page,
}) => {
	await page.route("**/*", (route) => route.abort());
	for (const step of [1, 2]) {
		const html = execFileSync(
			"php",
			[
				"tests/e2e/fixtures/installer-page.php",
				String(step),
				"ur_PK",
				"Check your settings <script>alert(1)</script>",
			],
			{ encoding: "utf8" }
		);
		await page.route(`${origin}/**`, async (route) => {
			if (route.request().method() === "POST")
				return route.fulfill({
					contentType: "text/html",
					body: "<h1>Submitted</h1>",
				});
			await route.fulfill({ contentType: "text/html", body: html });
		});
		await page.goto(`${origin}/${step}`);
		await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
		await expect(page.getByRole("alert")).toContainText(
			"<script>alert(1)</script>"
		);
		for (const width of [1440, 320]) {
			await page.setViewportSize({ width, height: 900 });
			expect(
				await page.evaluate(
					() => document.documentElement.scrollWidth <= innerWidth
				)
			).toBe(true);
		}
		if (step === 1) {
			await page.locator("#db_name").fill("test_database");
			await page.locator("#db_user").fill("test_user");
			await page.locator("#db_password").fill("test_password");
		} else {
			await page.locator("#workspace_name").fill("Test site");
			await page.locator("#owner_username").fill("test_admin");
			await page.locator("#owner_email").fill("owner@example.com");
			await page.locator("#owner_password").fill("test_password");
		}
		const post = page.waitForRequest(
			(request) => request.method() === "POST"
		);
		await page.locator("button[type=submit]").click();
		const request = await post;
		expect(new URL(request.url()).pathname).toBe(
			step === 1
				? "/installed/setup-config.php"
				: "/installed/install.php"
		);
		const fields = new URLSearchParams(request.postData() ?? "");
		expect(fields.get(step === 1 ? "db_password" : "owner_password")).toBe(
			"test_password"
		);
		expect(fields.get("site_language")).toBe("ur_PK");
		expect(fields.get("browser_timezone")).toBeTruthy();
	}
});

for (const theme of ["light", "dark"] as const) {
	test(`installer language menu supports keyboard navigation and fits both directions in ${theme}`, async ({
		page,
	}) => {
		await page.emulateMedia({ colorScheme: theme });
		await page.route("**/*", (route) => route.abort());
		await page.route(`${origin}/**`, (route) => {
			const locale =
				new URL(route.request().url()).searchParams.get(
					"site_language"
				) ?? "en_US";
			const html = execFileSync(
				"php",
				["tests/e2e/fixtures/installer-page.php", "0", locale],
				{ encoding: "utf8" }
			);
			return route.fulfill({ contentType: "text/html", body: html });
		});
		await page.setViewportSize({ width: 1440, height: 900 });
		await page.goto(`${origin}/installed/setup-config.php`);
		const trigger = page.getByRole("combobox");
		const popup = page.getByRole("listbox");
		await expect(page.locator("select[name=site_language]")).toBeHidden();
		await expect(trigger).toHaveAccessibleName("Site language");
		await trigger.click();
		await expect(popup).toBeVisible();
		await expect(popup.getByRole("option", { selected: true })).toHaveText(
			"English (United States)"
		);
		await trigger.press("End");
		await expect(trigger).toHaveAttribute(
			"aria-activedescendant",
			(await popup.getByRole("option").last().getAttribute("id")) ?? ""
		);
		await trigger.press("Escape");
		await expect(popup).toBeHidden();
		await expect(trigger).toBeFocused();
		await expect(page.locator("#site_language")).toHaveValue("en_US");
		await trigger.press("ArrowDown");
		await trigger.press("f");
		await trigger.press("r");
		await expect(
			page.locator(".installer-language-option[data-active]")
		).toHaveText("Français");
		await trigger.press("Escape");
		for (const direction of ["ltr", "rtl"]) {
			await page.evaluate(
				(direction) => (document.documentElement.dir = direction),
				direction
			);
			for (const width of [1440, 320]) {
				await page.setViewportSize({ width, height: 900 });
				await trigger.click();
				const bounds = await popup.boundingBox();
				if (!bounds) throw new Error("Language menu is missing");
				expect(bounds.x).toBeGreaterThanOrEqual(0);
				expect(bounds.x + bounds.width).toBeLessThanOrEqual(width);
				expect(bounds.y + bounds.height).toBeLessThanOrEqual(900);
				if (process.env.PEAKURL_UI_PREVIEW)
					await page.screenshot({
						path: `/tmp/peakurl-language-${theme}-${direction}-${width}.png`,
						fullPage: true,
					});
				await page.locator(".installer-header .status-brand").click();
				await expect(popup).toBeHidden();
			}
		}
		await trigger.press("f");
		await trigger.press("r");
		await trigger.press("Enter");
		await expect(page).toHaveURL(
			/\/installed\/setup-config.php\?site_language=fr_FR$/
		);
		await expect(page.locator("#site_language")).toHaveValue("fr_FR");
		await expect(page.locator(".actions a.btn-primary")).toHaveAttribute(
			"href",
			"/installed/setup-config.php?step=1&site_language=fr_FR"
		);
	});
}

test("installer language selection works with touch and without JavaScript", async ({
	browser,
}) => {
	for (const javaScriptEnabled of [true, false]) {
		const context = await browser.newContext({
			hasTouch: true,
			isMobile: true,
			javaScriptEnabled,
			viewport: { width: 390, height: 844 },
		});
		try {
			const page = await context.newPage();
			await page.route("**/*", (route) => route.abort());
			await page.route(`${origin}/**`, (route) => {
				const locale =
					new URL(route.request().url()).searchParams.get(
						"site_language"
					) ?? "en_US";
				return route.fulfill({
					contentType: "text/html",
					body: execFileSync(
						"php",
						["tests/e2e/fixtures/installer-page.php", "0", locale],
						{ encoding: "utf8" }
					),
				});
			});
			await page.goto(`${origin}/installed/setup-config.php`);
			if (javaScriptEnabled) {
				await page.getByRole("combobox").tap();
				await page
					.getByRole("option", { name: "Français", exact: true })
					.tap();
			} else {
				await page.getByRole("combobox").selectOption("fr_FR");
				await page
					.getByRole("button", { name: "Apply language" })
					.tap();
			}
			await expect(page).toHaveURL(/site_language=fr_FR$/);
			await expect(page.locator("#site_language")).toHaveValue("fr_FR");
		} finally {
			await context.close();
		}
	}
});
