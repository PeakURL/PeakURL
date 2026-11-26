import { expect, test } from "@playwright/test";

import { mockDashboardSession } from "../fixtures/dashboard.fixture";

const geographyUrl =
	"https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/countries-50m.json";

test("the map can recover from a CDN failure without interrupting the dashboard", async ({
	page,
}) => {
	await mockDashboardSession(page, {
		capabilities: { view_analytics: true, view_links: true },
	});
	await page.route("**/api/v1/analytics?*", (route) =>
		route.fulfill({
			json: {
				data: {
					totalClicks: 12,
					totalLinks: 2,
					traffic: {
						labels: ["2026-11-25"],
						clicks: [12],
						unique: [9],
					},
					countries: [
						{ code: "GB", name: "United Kingdom", count: 12 },
					],
				},
			},
		})
	);
	let geographyRequests = 0;
	await page.route(geographyUrl, async (route) => {
		geographyRequests++;
		if (geographyRequests === 1) {
			await route.abort("failed");
			return;
		}
		await route.fulfill({
			headers: { "access-control-allow-origin": "*" },
			json: {
				type: "Topology",
				arcs: [
					[
						[-5, 50],
						[-1, 55],
						[1, 51],
						[-5, 50],
					],
				],
				objects: {
					countries: {
						type: "GeometryCollection",
						geometries: [
							{ type: "Polygon", id: "826", arcs: [[0]] },
						],
					},
				},
			},
		});
	});
	await page.goto("/dashboard/about");
	expect(geographyRequests).toBe(0);
	const navigation = page.getByRole("navigation", { name: "Dashboard" });
	await navigation
		.getByRole("link", { name: "Overview", exact: true })
		.click();
	const map = page.locator(".world-map");
	await expect(map.getByRole("status")).toContainText(
		"Unable to load the world map right now."
	);
	await expect(page.locator(".dashboard-stats-grid")).toContainText("12");
	await expect(page.locator(".dashboard-traffic canvas")).toBeVisible();
	const retry = map.getByRole("button", { name: "Try again" });
	await retry.focus();
	await retry.press("Enter");
	await expect(map.locator(".world-map-country-clickable")).toHaveCount(1);
	await expect(map.getByText("Loading map...")).toHaveCount(0);

	await navigation.getByRole("link", { name: /^Links/ }).click();
	await navigation
		.getByRole("link", { name: "Overview", exact: true })
		.click();
	await expect(map.locator(".world-map-country-clickable")).toHaveCount(1);
	expect(geographyRequests).toBe(2);
});
