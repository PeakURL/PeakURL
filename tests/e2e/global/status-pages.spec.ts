import { expect, test } from "@playwright/test";

import { mockDrawerPages } from "../fixtures/drawer.fixture";

for (const theme of ["light", "dark"] as const) {
	test(`dashboard 404 stays in the dashboard and auth layouts fit in ${theme} mode`, async ({
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
		await expect(
			page.locator(".dashboard-header-user-trigger")
		).toBeVisible();
		await expect(page.locator(".dashboard-not-found")).toBeVisible();
		expect(
			await page
				.locator(".dashboard-not-found-artwork")
				.evaluate((element) => {
					const event = new MouseEvent("contextmenu", {
						bubbles: true,
						cancelable: true,
					});
					element.dispatchEvent(event);
					return event.defaultPrevented;
				})
		).toBe(true);
		await expect(
			page.locator(".status-header, .status-tagline, .powered-by")
		).toHaveCount(0);
		for (const width of [1440, 320]) {
			await page.setViewportSize({ width, height: 1000 });
			await expect(
				page.locator(".dashboard-not-found-artwork")
			).toBeVisible();
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
		await page.route(
			"**/api/v1/auth/reset-password/preview-token",
			(route) => route.fulfill({ json: { data: { valid: true } } })
		);
		for (const [pathname, illustration] of [
			["/login", "sign-in"],
			["/forgot-password", "account-recovery"],
			["/reset-password/preview-token", "password-reset"],
		] as const) {
			await page.goto(pathname);
			for (const width of [1440, 320]) {
				await page.setViewportSize({ width, height: 1000 });
				const artwork = page.locator(
					`[data-page-illustration="${illustration}"]`
				);
				if (width === 1440) {
					await expect(artwork).toBeVisible();
					expect(
						await artwork.evaluate((element) => {
							const event = new MouseEvent("contextmenu", {
								bubbles: true,
								cancelable: true,
							});
							element.dispatchEvent(event);
							return event.defaultPrevented;
						})
					).toBe(true);
					await expect
						.poll(() =>
							artwork.evaluate(
								(image: HTMLImageElement) =>
									image.complete && image.naturalWidth > 0
							)
						)
						.toBe(true);
				} else {
					await expect(artwork).toBeHidden();
				}
				await expect(
					page.locator(".form-control-base").first()
				).toBeEditable();
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
						path: `/tmp/peakurl-auth-${pathname.split("/")[1]}-${theme}-${width}.png`,
						fullPage: true,
						animations: "disabled",
					});
			}
		}

		await page.route("**/api/v1/auth/login", (route) =>
			route.fulfill({ json: { data: { requiresTwoFactor: true } } })
		);
		await page.setViewportSize({ width: 1440, height: 1000 });
		await page.goto("/login");
		await page.getByLabel("Email or username").fill("preview");
		await page.getByLabel(/^Password/).fill("preview-password");
		await page
			.getByRole("button", { name: "Sign in", exact: true })
			.click();
		await expect(
			page.getByRole("heading", { name: "Verify your identity" })
		).toBeVisible();
		await expect(
			page.locator('[data-page-illustration="verification"]')
		).toBeVisible();
		await page.getByRole("button", { name: "Back to sign-in" }).click();
		await expect(
			page.locator('[data-page-illustration="sign-in"]')
		).toBeVisible();
	});
}

for (const theme of ["light", "dark"] as const) {
	test(`auth CAPTCHA fits narrow forms and adapts when resized in ${theme} mode`, async ({
		page,
	}) => {
		await page.addInitScript((theme) => {
			localStorage.setItem("theme", theme);
			Reflect.set(window, "__PEAKURL__", {
				captcha: {
					provider: "turnstile",
					siteKey: "preview-key",
					responseField: "cf-turnstile-response",
					scriptUrl:
						"https://challenges.cloudflare.com/turnstile/v0/api.js",
				},
			});
		}, theme);
		await mockDrawerPages(page);
		await page.route("**/api/v1/users/me", (route) =>
			route.fulfill({
				status: 401,
				json: { message: "Sign in required" },
			})
		);
		await page.route(
			"**/api/v1/auth/reset-password/preview-token",
			(route) => route.fulfill({ json: { data: { valid: true } } })
		);
		await page.route(
			"https://challenges.cloudflare.com/turnstile/v0/api.js*",
			(route) =>
				route.fulfill({
					contentType: "application/javascript",
					body: `window.turnstile = {
			 render(container, options) {
			  const frame = document.createElement('iframe');
			  frame.title = 'Security check';
			  frame.width = options.size === 'compact' ? '150' : String(Math.max(300, container.clientWidth));
			  frame.height = options.size === 'compact' ? '140' : '65';
			  frame.srcdoc = '<style>body{margin:0;padding:16px;box-sizing:border-box;background:${theme === "dark" ? "#151923" : "#f7f8fa"};color:${theme === "dark" ? "#b1bbcc" : "#596579"};font:14px system-ui}p{margin:0}</style><p>✓ Security check complete</p>';
			  container.replaceChildren(frame);
			  return 'preview-widget';
			 },
			 remove() { document.querySelector('.captcha-widget-control')?.replaceChildren(); }
			};`,
				})
		);
		for (const path of [
			"/login",
			"/forgot-password",
			"/reset-password/preview-token",
		]) {
			await page.setViewportSize({ width: 1440, height: 900 });
			await page.goto(path);
			await expect(
				page.locator(".login-page-form, .auth-page-form")
			).toBeVisible();
			for (const width of [1440, 320, 1024]) {
				await page.setViewportSize({ width, height: 900 });
				const widget = page.getByTitle("Security check", {
					exact: true,
				});
				await expect(widget).toBeVisible();
				await expect(widget).toHaveAttribute(
					"height",
					width === 320 ? "140" : "65"
				);
				const bounds = await widget.boundingBox();
				if (!bounds) throw new Error("Security check is missing");
				expect(bounds.x).toBeGreaterThanOrEqual(0);
				expect(bounds.x + bounds.width).toBeLessThanOrEqual(width);
				expect(
					await page.evaluate(
						() => document.documentElement.scrollWidth <= innerWidth
					)
				).toBe(true);
				if (process.env.PEAKURL_UI_PREVIEW)
					await page.screenshot({
						path: `/tmp/peakurl-auth-captcha-${path.split("/")[1]}-${theme}-${width}.png`,
						fullPage: true,
						animations: "disabled",
					});
			}
		}
	});
}
