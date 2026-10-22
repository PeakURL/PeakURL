import { execFileSync } from "node:child_process";
import { expect, test, type Page } from "@playwright/test";

const origin = "https://captcha-preview.invalid";
const turnstileScript =
	"https://challenges.cloudflare.com/turnstile/v0/api.js*";
const recaptchaScript = "https://www.google.com/recaptcha/api.js*";

async function mockPublicPage(
	page: Page,
	provider = "turnstile",
	error = "",
	basePath = ""
) {
	const html = execFileSync(
		"php",
		["tests/e2e/fixtures/public-link-page.php", provider, error, basePath],
		{ encoding: "utf8" }
	);
	await page.route("**/*", (route) => route.abort());
	await page.route(`${origin}/**`, (route) => {
		if (route.request().method() === "POST")
			return route.fulfill({
				contentType: "text/html",
				body: "<h1>Link opened</h1>",
			});
		return route.fulfill({ contentType: "text/html", body: html });
	});
}

// Real page code runs against a small provider contract; no external challenges or live links.
const turnstileMock = `
window.widgetAttempts=[];
window.turnstile={
 render(selector,options){
  window.widgetAttempts.push(options);
  const frame=document.createElement('iframe');
  frame.width=options.size==='compact'?'150':String(document.querySelector(selector).clientWidth);
  frame.height=options.size==='compact'?'140':'65';
  frame.title='Security check';
  frame.srcdoc='<p style="font:14px system-ui;text-align:center">Security check</p>';
  document.querySelector(selector).replaceChildren(frame);
  window.widgetSize=options.size;
  return String(window.widgetAttempts.length);
 },
 reset(){throw new Error('A fresh widget must not be reset');},
 remove(){},
 execute(id){window.activeWidget=Number(id)-1;}
};
window.PeakURLRenderCaptcha();`;

for (const width of [1440, 390, 320]) {
	test(`Turnstile completes once without resetting or clipping at ${width}px`, async ({
		page,
	}) => {
		await page.setViewportSize({ width, height: 900 });
		await page.emulateMedia({
			colorScheme: width === 320 ? "dark" : "light",
		});
		await mockPublicPage(page, "turnstile", "", "/installed");
		await page.route(turnstileScript, (route) =>
			route.fulfill({
				contentType: "application/javascript",
				body: turnstileMock,
			})
		);
		await page.goto(`${origin}/installed/check`);
		await expect(
			page.getByRole("heading", { name: "Checking your browser" })
		).toBeVisible();
		await expect(page.locator(".status-artwork")).toHaveCount(0);
		await expect(page.locator(".status-access-artwork")).toHaveAttribute(
			"data-status-illustration",
			"verification"
		);
		await expect(page.locator("#captcha-widget")).toBeVisible();
		expect(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= innerWidth
			)
		).toBe(true);
		const mainBounds = await page.locator(".status-main").boundingBox();
		const heroBounds = await page.locator(".status-hero").boundingBox();
		if (!mainBounds || !heroBounds)
			throw new Error("Security verification layout is missing");
		expect(heroBounds.y + heroBounds.height / 2).toBeCloseTo(
			mainBounds.y + mainBounds.height / 2,
			0
		);
		const artworkBounds = await page
			.locator(".status-access-artwork")
			.boundingBox();
		const headingBounds = await page
			.getByRole("heading", { name: "Checking your browser" })
			.boundingBox();
		const formBounds = await page.locator("#captcha-form").boundingBox();
		if (!artworkBounds || !headingBounds || !formBounds)
			throw new Error("Security verification content is missing");
		expect(artworkBounds.y + artworkBounds.height).toBeLessThanOrEqual(
			headingBounds.y
		);
		expect(artworkBounds.width).toBeLessThanOrEqual(144);
		expect(formBounds.x + formBounds.width / 2).toBeCloseTo(width / 2, 0);
		const badgeBounds = await page
			.getByRole("link", { name: "Powered by PeakURL" })
			.boundingBox();
		const footerBounds = await page.locator(".status-footer").boundingBox();
		if (!badgeBounds || !footerBounds)
			throw new Error("Public footer is missing");
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
			await page.evaluate(() => Reflect.get(window, "widgetSize"))
		).toBe(width === 320 ? "compact" : "normal");
		const frameBounds = await page
			.getByTitle("Security check", { exact: true })
			.boundingBox();
		if (!frameBounds) throw new Error("The challenge iframe is missing");
		expect(frameBounds.x).toBeGreaterThanOrEqual(0);
		expect(frameBounds.x + frameBounds.width).toBeLessThanOrEqual(width);
		expect(frameBounds.width).toBe(width === 320 ? 150 : 300);
		expect(frameBounds.x + frameBounds.width / 2).toBeCloseTo(width / 2, 0);
		if (process.env.PEAKURL_UI_PREVIEW)
			await page.screenshot({
				path: `/tmp/peakurl-captcha-${width}.png`,
				fullPage: true,
			});
		const post = page.waitForRequest(
			(request) => request.method() === "POST"
		);
		await page.evaluate(() => {
			const options = Reflect.get(window, "widgetAttempts")[0];
			options.callback("verified-test-token");
			options.callback("duplicate-token");
		});
		const request = await post;
		expect(request.url()).toBe(`${origin}/installed/check`);
		expect(
			new URLSearchParams(request.postData() || "").get(
				"cf-turnstile-response"
			)
		).toBe("verified-test-token");
		await expect(
			page.getByRole("heading", { name: "Link opened" })
		).toBeVisible();
	});
}

test("blocked provider scripts show recovery and retry can complete", async ({
	page,
}) => {
	await mockPublicPage(page);
	await page.route(turnstileScript, (route) => route.abort());
	await page.goto(`${origin}/check`);
	await expect(page.getByRole("status")).toContainText("couldn’t load");
	await expect(page.getByRole("button", { name: "Try again" })).toBeVisible();
	await page.route(turnstileScript, (route) =>
		route.fulfill({
			contentType: "application/javascript",
			body: turnstileMock,
		})
	);
	await page.getByRole("button", { name: "Try again" }).click();
	await expect(page.locator("#captcha-widget")).toBeVisible();
	await page.evaluate(() =>
		Reflect.get(window, "widgetAttempts")[0].callback("retry-token")
	);
	await expect(
		page.getByRole("heading", { name: "Link opened" })
	).toBeVisible();
});

test("a provider that never loads times out instead of waiting forever", async ({
	page,
}) => {
	await page.clock.install();
	await mockPublicPage(page, "recaptcha");
	await page.route(recaptchaScript, (route) =>
		route.fulfill({
			contentType: "application/javascript",
			body: "/* SDK never initializes */",
		})
	);
	await page.goto(`${origin}/check`);
	await expect(page.getByRole("status")).toContainText(
		"Loading security check"
	);
	await page.clock.fastForward(20001);
	await expect(page.getByRole("status")).toContainText("couldn’t load");
	await expect(page.getByRole("button", { name: "Try again" })).toBeVisible();
});

test("failed verification waits for a fresh attempt and ignores stale callbacks", async ({
	page,
}) => {
	await page.clock.install();
	await mockPublicPage(page);
	await page.route(turnstileScript, (route) =>
		route.fulfill({
			contentType: "application/javascript",
			body: turnstileMock,
		})
	);
	await page.goto(`${origin}/check`);
	await expect(page.locator("#captcha-widget")).toBeVisible();
	await page.clock.fastForward(60001);
	await expect(page.getByRole("status")).toContainText(
		"longer than expected"
	);
	await page.getByRole("button", { name: "Try again" }).click();
	await page.evaluate(() =>
		Reflect.get(window, "widgetAttempts")[0].callback("stale-token")
	);
	await expect(page.locator("#captcha-token")).toHaveValue("");
	await page.evaluate(() =>
		Reflect.get(window, "widgetAttempts")[1].callback("fresh-token")
	);
	await expect(
		page.getByRole("heading", { name: "Link opened" })
	).toBeVisible();
});

test("reCAPTCHA waits for its SDK and posts its callback token on mobile", async ({
	page,
}) => {
	await page.setViewportSize({ width: 320, height: 800 });
	await mockPublicPage(page, "recaptcha");
	await page.route(recaptchaScript, (route) =>
		route.fulfill({
			contentType: "application/javascript",
			body: `window.grecaptcha={ready(callback){setTimeout(callback,30);},execute(){return Promise.resolve('recaptcha-test-token');}};window.PeakURLRenderCaptcha();`,
		})
	);
	const post = page.waitForRequest((request) => request.method() === "POST");
	await page.goto(`${origin}/check`);
	const request = await post;
	expect(
		new URLSearchParams(request.postData() || "").get(
			"g-recaptcha-response"
		)
	).toBe("recaptcha-test-token");
	await expect(
		page.getByRole("heading", { name: "Link opened" })
	).toBeVisible();
});

test("a server-rejected token requires an explicit retry", async ({ page }) => {
	await mockPublicPage(
		page,
		"turnstile",
		"Verification failed. Please try again."
	);
	let scriptRequests = 0;
	await page.route(turnstileScript, (route) => {
		scriptRequests++;
		return route.fulfill({
			contentType: "application/javascript",
			body: turnstileMock,
		});
	});
	await page.goto(`${origin}/check`);
	await expect(page.getByRole("status")).toContainText("Verification failed");
	expect(scriptRequests).toBe(0);
	await page.getByRole("button", { name: "Try again" }).click();
	await expect(page.locator("#captcha-widget")).toBeVisible();
	expect(scriptRequests).toBe(1);
});

test("interactive challenges remain available while the visitor responds", async ({
	page,
}) => {
	await page.clock.install();
	await mockPublicPage(page);
	await page.route(turnstileScript, (route) =>
		route.fulfill({
			contentType: "application/javascript",
			body: turnstileMock,
		})
	);
	await page.goto(`${origin}/check`);
	await expect(page.locator("#captcha-widget")).toBeVisible();
	await page.evaluate(() =>
		Reflect.get(window, "widgetAttempts")[0][
			"before-interactive-callback"
		]()
	);
	await page.clock.fastForward(120001);
	await expect(page.locator("#captcha-widget")).toBeVisible();
	await expect(
		page.getByRole("button", { name: "Try again" })
	).not.toBeVisible();
	await page.evaluate(() =>
		Reflect.get(window, "widgetAttempts")[0].callback("interactive-token")
	);
	await expect(
		page.getByRole("heading", { name: "Link opened" })
	).toBeVisible();
});

test("reCAPTCHA execution failures provide retry feedback", async ({
	page,
}) => {
	await mockPublicPage(page, "recaptcha");
	await page.route(recaptchaScript, (route) =>
		route.fulfill({
			contentType: "application/javascript",
			body: `window.grecaptcha={ready(callback){setTimeout(callback,0);},execute(){throw new Error('Provider unavailable');}};window.PeakURLRenderCaptcha();`,
		})
	);
	await page.goto(`${origin}/check`);
	await expect(page.getByRole("status")).toContainText(
		"could not be completed"
	);
	await expect(page.getByRole("button", { name: "Try again" })).toBeVisible();
});
