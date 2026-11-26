import { execFileSync } from "node:child_process";
import { expect, test } from "@playwright/test";

const origin = "https://status-preview.invalid";
const states = [
	["missing-link", "Link not found", "missing-link"],
	["expired", "Link expired", "expired"],
	["404", "Page not found", "missing-page"],
	["403", "Access to this page is restricted", "forbidden"],
	["429", "Please wait a moment", "rate-limit"],
	["500", "Unable to complete your request", "server-error"],
	["database", "Unable to connect to the database", "database"],
	["maintenance", "Briefly unavailable", "maintenance"],
	["password", "Password required", "password"],
] as const;

test("the standalone readme loads local artwork and wraps long links at 320px", async ({
	browser,
}) => {
	const context = await browser.newContext({
		javaScriptEnabled: false,
		colorScheme: "dark",
		viewport: { width: 320, height: 900 },
	});
	try {
		const page = await context.newPage();
		await page.route("**/*", (route) => {
			const url = new URL(route.request().url());
			if (
				url.origin === origin &&
				[
					"/readme.html",
					"/server/services/brand-tokens.css",
					"/client/assets/logo/peakurl.svg",
					"/client/assets/logo/peakurl-wordmark.svg",
				].includes(url.pathname)
			) {
				return route.fulfill({ path: `.${url.pathname}` });
			}
			return route.abort();
		});
		await page.goto(`${origin}/readme.html`);
		await expect(
			page.getByRole("heading", { level: 1 })
		).toHaveAccessibleName("PeakURL");
		await expect(page.locator("body")).toHaveCSS(
			"background-color",
			"rgb(14, 19, 18)"
		);
		expect(
			await page.evaluate(() => document.documentElement.scrollWidth)
		).toBe(320);
		expect(
			await page
				.locator(".header-wordmark")
				.evaluate(
					(image: HTMLImageElement) =>
						image.complete && image.naturalWidth > 0
				)
		).toBe(true);
	} finally {
		await context.close();
	}
});

for (const theme of ["light", "dark"] as const) {
	test(`public recovery pages balance illustrations and stay readable in ${theme} mode`, async ({
		page,
	}) => {
		await page.emulateMedia({ colorScheme: theme });
		await page.route("**/*", (route) => route.abort());
		for (const [state, title, illustration] of states) {
			const html = execFileSync(
				"php",
				["tests/e2e/fixtures/public-status-page.php", state],
				{ encoding: "utf8" }
			);
			await page.route(`${origin}/**`, (route) =>
				route.fulfill({ contentType: "text/html", body: html })
			);
			await page.goto(`${origin}/${state}`);
			for (const width of [1440, 320]) {
				await page.setViewportSize({ width, height: 900 });
				const heading = page.getByRole("heading", {
					name: title,
					exact: true,
				});
				const artwork = page.locator(
					`[data-status-illustration="${illustration}"]`
				);
				await expect(heading).toBeVisible();
				await expect(artwork).toBeVisible();
				await expect
					.poll(() =>
						artwork.evaluate(
							(image: HTMLImageElement) =>
								image.complete && image.naturalWidth > 0
						)
					)
					.toBe(true);
				const imageBounds = await artwork.boundingBox();
				const headingBounds = await heading.boundingBox();
				if (!imageBounds || !headingBounds)
					throw new Error("Public recovery content is missing");
				if (width > 700 && state !== "password") {
					expect(imageBounds.x).toBeGreaterThanOrEqual(
						headingBounds.x + headingBounds.width
					);
				} else {
					expect(
						imageBounds.y + imageBounds.height
					).toBeLessThanOrEqual(headingBounds.y);
				}
				if (state === "password") {
					const mainBounds = await page
						.locator(".status-main")
						.boundingBox();
					const heroBounds = await page
						.locator(".status-hero")
						.boundingBox();
					if (!mainBounds || !heroBounds)
						throw new Error("Password layout is missing");
					expect(heroBounds.y + heroBounds.height / 2).toBeCloseTo(
						mainBounds.y + mainBounds.height / 2,
						0
					);
					const formBounds = await page.locator("form").boundingBox();
					if (!formBounds)
						throw new Error("Password form is missing");
					expect(formBounds.x + formBounds.width / 2).toBeCloseTo(
						width / 2,
						0
					);
					expect(imageBounds.width).toBeLessThanOrEqual(144);
					expect(imageBounds.x + imageBounds.width / 2).toBeCloseTo(
						width / 2,
						0
					);
					expect(
						headingBounds.y - imageBounds.y - imageBounds.height
					).toBeLessThanOrEqual(24);
				}
				expect(
					await page.evaluate(
						() => document.documentElement.scrollWidth <= innerWidth
					)
				).toBe(true);
				const badge = page.getByRole("link", {
					name: "Powered by PeakURL",
				});
				await expect(badge).toBeVisible();
				const badgeBounds = await badge.boundingBox();
				const footerBounds = await page
					.locator(".status-footer")
					.boundingBox();
				if (!badgeBounds || !footerBounds)
					throw new Error("Public page footer is missing");
				if (width <= 700) {
					expect(badgeBounds.x + badgeBounds.width / 2).toBeCloseTo(
						width / 2,
						0
					);
				} else {
					expect(badgeBounds.x + badgeBounds.width).toBeCloseTo(
						footerBounds.x + footerBounds.width,
						0
					);
				}
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
				await expect(
					page.getByRole("heading", { name: "Error details" })
				).toHaveCount(0);
				if (process.env.PEAKURL_UI_PREVIEW)
					await page.screenshot({
						path: `/tmp/peakurl-public-${state}-${theme}-${width}.png`,
						fullPage: true,
					});
			}
		}
	});
}

test("public server diagnostics appear immediately only when supplied", async ({
	page,
}) => {
	await page.route("**/*", (route) => route.abort());
	const html = execFileSync(
		"php",
		[
			"tests/e2e/fixtures/public-status-page.php",
			"500",
			"Preview diagnostic",
		],
		{ encoding: "utf8" }
	);
	await page.route(`${origin}/**`, (route) =>
		route.fulfill({ contentType: "text/html", body: html })
	);
	await page.goto(`${origin}/500`);
	await expect(
		page.getByRole("heading", { name: "Error details" })
	).toBeVisible();
	await expect(
		page.getByText("Preview diagnostic", { exact: true })
	).toBeVisible();
	await expect(page.locator("details")).toHaveCount(0);
});

test("protected links retain password submission and validation in the shared layout", async ({
	page,
}) => {
	const renderPassword = (error = "") =>
		execFileSync(
			"php",
			["tests/e2e/fixtures/public-status-page.php", "password", error],
			{ encoding: "utf8" }
		);
	const submissions: string[] = [];
	await page.route("**/*", (route) => route.abort());
	await page.route(`${origin}/**`, async (route) => {
		if (route.request().method() === "POST") {
			expect(new URL(route.request().url()).pathname).toBe(
				"/installed/protected"
			);
			const password = new URLSearchParams(
				route.request().postData() ?? ""
			).get("link_password");
			submissions.push(password ?? "");
			await route.fulfill({
				contentType: "text/html",
				body:
					password === "correct-password"
						? "<h1>Link opened</h1>"
						: renderPassword(
								"Incorrect password. Please try again."
							),
			});
			return;
		}
		await route.fulfill({
			contentType: "text/html",
			body: renderPassword(),
		});
	});
	await page.goto(`${origin}/installed/protected`);
	const password = page.getByLabel("Password", { exact: true });
	await password.focus();
	await expect(password).toHaveCSS("outline-style", "none");
	await expect(password).toHaveCSS("border-color", "rgb(18, 110, 104)");
	await expect(password).toHaveCSS(
		"box-shadow",
		"rgb(18, 110, 104) 0px 0px 0px 1px inset"
	);
	await password.fill("wrong-password");
	await page.getByRole("button", { name: "Continue" }).click();
	await expect(
		page.getByText("Incorrect password. Please try again.")
	).toBeVisible();
	await expect(password).toHaveAttribute("aria-invalid", "true");
	await password.focus();
	await expect(password).toHaveCSS("outline-style", "none");
	await expect(password).toHaveCSS("border-color", "rgb(185, 28, 28)");
	await expect(password).toHaveCSS(
		"box-shadow",
		"rgb(185, 28, 28) 0px 0px 0px 1px inset"
	);
	await expect(password).toHaveAccessibleDescription(
		"Incorrect password. Please try again."
	);
	await password.fill("correct-password");
	await page.getByRole("button", { name: "Continue" }).click();
	await expect(
		page.getByRole("heading", { name: "Link opened" })
	).toBeVisible();
	expect(submissions).toEqual(["wrong-password", "correct-password"]);
});

for (const theme of ["light", "dark"] as const) {
	test(`public status and error pages toggle between light and dark mode from ${theme}`, async ({
		page,
	}) => {
		await page.emulateMedia({ colorScheme: theme });
		await page.route("**/*", (route) => route.abort());
		const html = execFileSync(
			"php",
			["tests/e2e/fixtures/public-status-page.php", "missing-link"],
			{ encoding: "utf8" }
		);
		await page.route(`${origin}/**`, (route) =>
			route.fulfill({ contentType: "text/html", body: html })
		);
		await page.goto(`${origin}/missing-link`);

		const toggle = page.getByRole("button", { name: "Toggle theme" });
		const expectedNext = theme === "dark" ? "light" : "dark";
		await expect(toggle).toHaveAttribute(
			"aria-pressed",
			String(theme === "dark")
		);
		await toggle.click();
		await expect(page.locator("html")).toHaveAttribute(
			"data-status-theme",
			expectedNext
		);
		await expect(toggle).toHaveAttribute(
			"aria-pressed",
			String(expectedNext === "dark")
		);
		await expect(
			page.locator(
				`.status-theme-icon-${expectedNext === "dark" ? "moon" : "sun"}`
			)
		).toBeVisible();

		await page.reload();
		await expect(page.locator("html")).toHaveAttribute(
			"data-status-theme",
			expectedNext
		);
		await toggle.click();
		await expect(page.locator("html")).toHaveAttribute(
			"data-status-theme",
			theme
		);
	});
}
