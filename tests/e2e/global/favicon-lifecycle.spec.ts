import fs from "node:fs";
import path from "node:path";
import { test, expect } from "../fixtures/auth.fixture";

const SAMPLE_FAVICON_PNG = fs.readFileSync(
	path.resolve(
		import.meta.dirname,
		"../../../server/public/default-favicon.png"
	)
);

test.describe("Default Light/Dark Favicon & Custom Override Lifecycle", () => {
	test("serves contrasting light/dark SVGs by default, suppresses them on custom upload, and restores cleanly on delete", async ({
		authenticatedPage: page,
	}) => {
		const initialGeneralRes = await page.request.get(
			"/api/v1/system/general"
		);
		expect(initialGeneralRes.status()).toBe(200);
		const currentGeneral = (await initialGeneralRes.json())?.data ?? {};
		const baseFields = {
			siteName: String(currentGeneral.siteName ?? "Workspace"),
			siteTagline: String(currentGeneral.siteTagline ?? ""),
			siteLanguage: String(currentGeneral.siteLanguage ?? "en_US"),
			siteTimezone: String(currentGeneral.siteTimezone ?? "UTC"),
			siteTimeFormat: String(currentGeneral.siteTimeFormat ?? "12"),
			landingPageMode: String(currentGeneral.landingPageMode ?? "html"),
			landingPageUrl: String(currentGeneral.landingPageUrl ?? ""),
		};

		await page.goto("/dashboard/settings/general", {
			waitUntil: "domcontentloaded",
		});

		const heading = page.getByRole("heading", {
			name: "General",
			exact: true,
			level: 1,
		});
		const svgSelector =
			'head link[data-peakurl-favicon="1"][type="image/svg+xml"]';
		const pngSelector =
			'head link[data-peakurl-favicon="1"][rel="icon"][type="image/png"]';

		try {
			// Ensure clean default state first
			await page.request.post("/api/v1/system/general", {
				multipart: { ...baseFields, removeFavicon: "1" },
			});
			await page.reload({ waitUntil: "domcontentloaded" });
			await expect(heading).toBeVisible();
			await expect(page.locator(svgSelector)).toHaveCount(3);

			// Default <head> emits adaptive + light/dark SVG links alongside PNG/App/Manifest fallbacks
			const defaultLinks = await page
				.locator('head link[data-peakurl-favicon="1"]')
				.evaluateAll((nodes) =>
					nodes.map((node) => ({
						rel: node.getAttribute("rel"),
						type: node.getAttribute("type"),
						media: node.getAttribute("media"),
						href: node.getAttribute("href"),
					}))
				);

			expect(defaultLinks).toEqual(
				expect.arrayContaining([
					expect.objectContaining({
						rel: "icon",
						type: "image/svg+xml",
						media: null,
						href: expect.stringContaining("/favicon.svg"),
					}),
					expect.objectContaining({
						rel: "icon",
						type: "image/svg+xml",
						media: "(prefers-color-scheme: light)",
						href: expect.stringContaining("/favicon-light.svg"),
					}),
					expect.objectContaining({
						rel: "icon",
						type: "image/svg+xml",
						media: "(prefers-color-scheme: dark)",
						href: expect.stringContaining("/favicon-dark.svg"),
					}),
					expect.objectContaining({
						rel: "apple-touch-icon",
						href: expect.stringContaining("/apple-touch-icon.png"),
					}),
				])
			);

			const [svgRes, lightRes, darkRes, pngRes, appIconRes] =
				await Promise.all([
					page.request.get("/favicon.svg"),
					page.request.get("/favicon-light.svg"),
					page.request.get("/favicon-dark.svg"),
					page.request.get("/favicon.png"),
					page.request.get("/apple-touch-icon.png"),
				]);
			expect(svgRes.status()).toBe(200);
			expect(lightRes.status()).toBe(200);
			expect(darkRes.status()).toBe(200);
			expect(pngRes.status()).toBe(200);
			expect(appIconRes.status()).toBe(200);
			expect(await lightRes.text()).toContain("#126e68");
			expect(await darkRes.text()).toContain("#83dccc");

			// Upload custom favicon and confirm default SVG tags & endpoints are suppressed
			const uploadRes = await page.request.post(
				"/api/v1/system/general",
				{
					multipart: {
						...baseFields,
						removeFavicon: "0",
						favicon: {
							name: "custom-favicon.png",
							mimeType: "image/png",
							buffer: SAMPLE_FAVICON_PNG,
						},
					},
				}
			);
			expect(uploadRes.status()).toBe(200);
			const uploadJson = await uploadRes.json();
			expect(uploadJson?.data?.favicon?.isCustom).toBe(true);
			expect(uploadJson?.data?.favicon?.svgUrl).toBeNull();
			expect(uploadJson?.data?.favicon?.lightSvgUrl).toBeNull();
			expect(uploadJson?.data?.favicon?.darkSvgUrl).toBeNull();

			await page.reload({ waitUntil: "domcontentloaded" });
			await expect(heading).toBeVisible();
			await expect(page.locator(pngSelector)).toHaveCount(1);
			await expect(page.locator(svgSelector)).toHaveCount(0);

			const [customSvgRes, customLightRes, customDarkRes, customPngRes] =
				await Promise.all([
					page.request.get("/favicon.svg"),
					page.request.get("/favicon-light.svg"),
					page.request.get("/favicon-dark.svg"),
					page.request.get("/favicon.png"),
				]);
			expect(customSvgRes.status()).toBe(404);
			expect(customLightRes.status()).toBe(404);
			expect(customDarkRes.status()).toBe(404);
			expect(customPngRes.status()).toBe(200);

			// Delete custom favicon and confirm default light/dark SVGs return
			const deleteRes = await page.request.post(
				"/api/v1/system/general",
				{
					multipart: { ...baseFields, removeFavicon: "1" },
				}
			);
			expect(deleteRes.status()).toBe(200);
			const deleteJson = await deleteRes.json();
			expect(deleteJson?.data?.favicon?.isCustom).toBe(false);
			expect(deleteJson?.data?.favicon?.lightSvgUrl).toContain(
				"/favicon-light.svg"
			);
			expect(deleteJson?.data?.favicon?.darkSvgUrl).toContain(
				"/favicon-dark.svg"
			);

			await page.reload({ waitUntil: "domcontentloaded" });
			await expect(heading).toBeVisible();
			await expect(page.locator(svgSelector)).toHaveCount(3);
			expect((await page.request.get("/favicon-dark.svg")).status()).toBe(
				200
			);
		} finally {
			await page.request.post("/api/v1/system/general", {
				multipart: { ...baseFields, removeFavicon: "1" },
			});
		}
	});
});
