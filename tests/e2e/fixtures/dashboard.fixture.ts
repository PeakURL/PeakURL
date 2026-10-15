import type { Page } from "@playwright/test";
import type { ApiUserCapabilities } from "../../../client/api/types/users";

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
					version: options.version || "4.2.0",
					locale: "en_US",
					htmlLang: "en-US",
					textDirection: options.direction || "ltr",
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
