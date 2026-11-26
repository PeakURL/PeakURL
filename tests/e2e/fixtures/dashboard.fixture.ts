import type { Page } from "@playwright/test";
import type { ApiUserCapabilities } from "../../../client/api/types/users";
import type { PeakURLData } from "../../../client/data";

/** Apply fixture data whether bootstrap comes from packaged HTML or the i18n endpoint. */
export async function mockDashboardBootstrap(
	page: Page,
	overrides: Partial<PeakURLData>
) {
	await page.addInitScript((fixtureData) => {
		const initial = { ...window.__PEAKURL__, ...fixtureData };
		let current = initial;
		Object.defineProperty(window, "__PEAKURL__", {
			configurable: true,
			get: () => current,
			set: (data: PeakURLData) => {
				current = { ...data, ...initial };
			},
		});
	}, overrides);
}

/** Fake dashboard session for UI checks without signing in or modifying an installation. */
export async function mockDashboardSession(
	page: Page,
	options: {
		role?: "admin" | "editor";
		capabilities?: ApiUserCapabilities;
		version?: string;
		direction?: "ltr" | "rtl";
	} = {}
) {
	const runtime = {
		version: options.version || "4.2.0",
		textDirection: options.direction || "ltr",
	};
	await mockDashboardBootstrap(page, runtime);
	await page.route("**/api/v1/users/me", (route) =>
		route.fulfill({
			json: {
				data: {
					id: "dashboard-preview",
					username: "preview",
					firstName: "Preview",
					lastName: "User",
					email: "preview@example.com",
					role: options.role || "admin",
					capabilities: options.capabilities || {},
				},
			},
		})
	);
	await page.route("**/api/v1/system/i18n", (route) =>
		route.fulfill({
			json: {
				data: {
					...runtime,
					locale: "en_US",
					htmlLang: "en-US",
					favicon: {
						configured: true,
						isCustom: false,
						svgUrl: "/favicon.svg",
						lightSvgUrl: "/favicon-light.svg",
						darkSvgUrl: "/favicon-dark.svg",
						url: "/favicon.png",
						iconUrl: "/favicon.ico",
						appleTouchUrl: "/apple-touch-icon.png",
						manifestUrl: "/site.webmanifest",
						sizes: "512x512",
						updatedAt: "2026-11-25T12:00:00Z",
					},
				},
			},
		})
	);
	await page.route("**/api/v1/urls?*", (route) =>
		route.fulfill({
			json: { data: { items: [], meta: { totalItems: 0 } } },
		})
	);
}
